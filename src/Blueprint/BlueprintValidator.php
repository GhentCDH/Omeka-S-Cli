<?php
namespace OSC\Blueprint;

use Exception;
use OSC\Cache;
use OSC\Helper\ResourceFetcher;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Throwable;

/**
 * Validate a blueprint (or a standalone partial list) against the canonical JSON schema, and check
 * referential integrity that a schema alone cannot express (an item referencing an undeclared item
 * set, a site permission referencing an undeclared user).
 *
 * The schema is fetched from the shared `omeka-s-blueprints` repository (see SCHEMA_ID) and cached
 * for 24h under `$HOME/.cache/omeka-s-cli`. When the fetch fails (offline, network error, GitHub
 * down) it transparently falls back to the copy bundled in `assets/blueprints/`, so the tool keeps
 * working offline and inside the PHAR.
 *
 * Structural validation uses opis/json-schema. If that library is somehow unavailable the structural
 * pass is skipped (with no error) and only the referential checks run, so the tool still works.
 */
class BlueprintValidator
{
    private const SCHEMA_ID = 'https://raw.githubusercontent.com/omeka-s-contrib/omeka-s-blueprints/main/assets/schema/blueprint-schema.json';

    /** Resolved schema content (from the source or its cache); false until resolved, null when unavailable */
    private string|false|null $schemaContent = false;

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
     * Build a validator that loads the schema from the given source.
     *
     * @param string $schemaSource Where to load the schema from. Defaults to the shared repo URL
     *                             (SCHEMA_ID); may be overridden with a local path (e.g. in tests) —
     *                             ResourceFetcher handles both. URL sources are cached for 24h; local
     *                             sources are read fresh (uncached).
     */
    public function __construct(private string $schemaSource = self::SCHEMA_ID)
    {
    }

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
        return dirname(__DIR__, 2) . '/assets/blueprints/blueprint-schema.json';
    }

    /**
     * Load the schema JSON from the configured source, or null when it cannot be obtained (the caller
     * then falls back to the bundled file). URL sources are cached for 24h; local sources are read
     * fresh. The result is memoized so validateBlueprint() reads the source at most once per instance.
     *
     * @return string|null Raw schema JSON, or null when unavailable (offline, network/parse error)
     */
    private function fetchSchema(): ?string
    {
        if ($this->schemaContent !== false) {
            return $this->schemaContent;
        }

        $isUrl = ResourceFetcher::isUrl($this->schemaSource);
        $cache = Cache::getCache();
        $cacheKey = 'blueprint-schema-' . hash('sha256', $this->schemaSource);

        if ($isUrl) {
            $cached = $cache->get($cacheKey);
            if (is_string($cached)) {
                return $this->schemaContent = $cached;
            }
        }

        try {
            $content = ResourceFetcher::fetch($this->schemaSource);
            // reject non-JSON before caching or handing it to opis
            json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            if ($isUrl) {
                $cache->set($cacheKey, $content);
            }
            return $this->schemaContent = $content;
        } catch (Throwable) {
            return $this->schemaContent = null;
        }
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
        if (!class_exists(Validator::class)) {
            return [];
        }

        $validator = new Validator();

        $schema = $this->fetchSchema();
        if ($schema !== null) {
            // schema fetched from the shared repo (or served from its 24h cache)
            $validator->resolver()->registerRaw($schema, self::SCHEMA_ID);
        } elseif (is_readable($this->schemaFile())) {
            // offline / fetch failed: fall back to the bundled copy
            $validator->resolver()->registerFile(self::SCHEMA_ID, $this->schemaFile());
        } else {
            return [];
        }

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
