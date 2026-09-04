<?php
namespace OSC\Blueprint;

use Exception;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validate a blueprint (or a standalone partial list) against the bundled JSON schema, and check
 * referential integrity that a schema alone cannot express (an item referencing an undeclared item
 * set, a site permission referencing an undeclared user).
 *
 * Structural validation uses opis/json-schema. If that library is somehow unavailable the structural
 * pass is skipped (with no error) and only the referential checks run, so the tool still works.
 */
class BlueprintValidator
{
    private const SCHEMA_ID = 'https://raw.githubusercontent.com/GhentCDH/Omeka-S-Cli/main/assets/blueprints/omeka-s-cli.blueprint-schema.json';

    /** partial type => the schema $def it maps to */
    private const PARTIAL_DEFS = [
        'modules'            => 'moduleList',
        'themes'             => 'themeList',
        'vocabularies'       => 'vocabularyList',
        'resourceTemplates'  => 'resourceTemplateList',
        'resource-templates' => 'resourceTemplateList',
        'settings'           => 'settings',
        'users'              => 'userList',
        'items'              => 'itemList',
        'itemSets'           => 'itemSetList',
        'item-sets'          => 'itemSetList',
    ];

    /**
     * Validate a full blueprint.
     *
     * @param array $blueprint The import-resolved blueprint
     * @return string[] Human-readable error messages; empty when valid
     */
    public function validateBlueprint(array $blueprint): array
    {
        return array_merge(
            $this->validateAgainst($blueprint, self::SCHEMA_ID),
            $this->referentialErrors($blueprint)
        );
    }

    /**
     * Validate a standalone partial (a list, or the settings value).
     *
     * @param mixed  $data
     * @param string $type
     * @return string[] Error messages; empty when valid
     * @throws Exception When the partial type is unknown
     */
    public function validatePartial(mixed $data, string $type): array
    {
        $def = self::PARTIAL_DEFS[$type] ?? null;
        if ($def === null) {
            $known = implode(', ', array_keys(self::PARTIAL_DEFS));
            throw new Exception("Unknown partial type '{$type}'. Known types: {$known}.");
        }
        return $this->validateAgainst($data, self::SCHEMA_ID . '#/$defs/' . $def);
    }

    public function schemaFile(): string
    {
        return dirname(__DIR__, 2) . '/assets/blueprints/omeka-s-cli.blueprint-schema.json';
    }

    /**
     * Run structural (JSON-schema) validation.
     *
     * @param mixed  $data
     * @param string $schemaId Schema id, optionally with a `#/$defs/...` fragment
     * @return string[]
     */
    private function validateAgainst(mixed $data, string $schemaId): array
    {
        if (!class_exists(Validator::class) || !is_readable($this->schemaFile())) {
            return [];
        }

        $validator = new Validator();
        $validator->resolver()->registerFile(self::SCHEMA_ID, $this->schemaFile());

        // opis operates on native JSON values (stdClass objects), not associative arrays
        $native = json_decode(json_encode($data));
        $result = $validator->validate($native, $schemaId);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        foreach ((new ErrorFormatter())->format($result->error()) as $path => $messages) {
            $where = $path === '' ? '/' : $path;
            foreach ((array) $messages as $message) {
                $errors[] = "{$where}: {$message}";
            }
        }
        return $errors;
    }

    /**
     * Referential-integrity checks that the schema cannot express.
     *
     * @param array $blueprint
     * @return string[]
     */
    private function referentialErrors(array $blueprint): array
    {
        $errors = [];

        $itemSetTitles = $this->titlesLower($blueprint['itemSets'] ?? []);
        foreach (($blueprint['items'] ?? []) as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['itemSets'] ?? []) as $title) {
                if (!in_array(strtolower((string) $title), $itemSetTitles, true)) {
                    $errors[] = "items[{$i}]: references unknown item set '{$title}'.";
                }
            }
        }

        $userEmails = [];
        foreach (($blueprint['users'] ?? []) as $user) {
            if (is_array($user) && isset($user['email'])) {
                $userEmails[] = strtolower($user['email']);
            }
        }
        foreach ($this->sites($blueprint) as $s => $site) {
            $label = $site['title'] ?? $site['slug'] ?? $s;
            foreach (($site['permissions'] ?? []) as $permission) {
                $user = strtolower((string) ($permission['user'] ?? ''));
                if ($user !== '' && !in_array($user, $userEmails, true)) {
                    $errors[] = "site '{$label}': permission references unknown user '{$permission['user']}'.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array> The blueprint's sites (from `sites`, else the singular `site`)
     */
    private function sites(array $blueprint): array
    {
        if (isset($blueprint['sites']) && is_array($blueprint['sites'])) {
            return $blueprint['sites'];
        }
        if (isset($blueprint['site']) && is_array($blueprint['site'])) {
            return [$blueprint['site']];
        }
        return [];
    }

    /**
     * @return string[] Lower-cased titles of the given item-set list
     */
    private function titlesLower(array $itemSets): array
    {
        $titles = [];
        foreach ($itemSets as $itemSet) {
            if (is_array($itemSet) && isset($itemSet['title'])) {
                $titles[] = strtolower($itemSet['title']);
            }
        }
        return $titles;
    }
}
