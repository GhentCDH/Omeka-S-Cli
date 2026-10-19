<?php
namespace OSC\Blueprint;

use Exception;
use InvalidArgumentException;
use OSC\Cache;
use OSC\Helper\ItemImportConfig;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\SiteConfig;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Throwable;

/**
 * Validate a blueprint (or a standalone partial list) against the canonical JSON schema, and check
 * referential integrity that a schema alone cannot express (an item referencing an undeclared item
 * set, a site permission referencing an undeclared user).
 *
 * The schema is fetched from the shared `omeka-s-blueprints` project (see SCHEMA_SOURCE) and cached
 * for 24h under `$HOME/.cache/omeka-s-cli`. When the fetch fails (offline, network error, GitHub
 * down) it transparently falls back to a copy at `assets/blueprints/blueprint-schema.json`. That copy
 * is not committed: it is downloaded on demand (see `scripts/fetch-blueprint-schema.php`) — by the test
 * bootstrap and by the PHAR build, which bundles it — so the tool keeps working offline and inside
 * the PHAR while still preferring the latest schema whenever the network is available.
 *
 * Structural validation uses opis/json-schema. If that library is somehow unavailable the structural
 * pass is skipped (with no error) and only the referential checks run, so the tool still works.
 */
class BlueprintValidator
{
    /**
     * The schema's identity: the `$id` it declares, under which it is registered so its `$ref`s
     * resolve. This is the floating v0 schema: the latest v0.x.y release, without breaking changes.
     */
    public const SCHEMA_ID = 'https://omeka-s-contrib.github.io/omeka-s-blueprints/schema/v0/blueprint-schema.json';

    /**
     * Where the schema is downloaded from. Pinned to a commit of the shared repository until the v0
     * schema is published at SCHEMA_ID; at that point this becomes SCHEMA_ID.
     */
    public const SCHEMA_SOURCE = 'https://omeka-s-contrib.github.io/omeka-s-blueprints/schema/v0/blueprint-schema.json';

    /** Resolved schema content (from the source or its cache); false until resolved, null when unavailable */
    private string|false|null $schemaContent = false;

    /** partial type => the schema $def it maps to */
    private const PARTIAL_DEFS = [
        'modules'            => 'moduleList',
        'themes'             => 'themeList',
        'files'              => 'fileList',
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
     * Partial types whose list has no $def in the schema (yet): each entry is validated against the
     * item $def instead. Proposed upstream as a `siteList` def.
     */
    private const PARTIAL_ITEM_DEFS = [
        'sites' => 'site',
    ];

    /**
     * Build a validator that loads the schema from the given source.
     *
     * @param string $schemaSource Where to load the schema from. Defaults to the shared repo URL
     *                             (SCHEMA_SOURCE); may be overridden with a local path (e.g. in tests) —
     *                             ResourceFetcher handles both. URL sources are cached for 24h; local
     *                             sources are read fresh (uncached).
     */
    public function __construct(private string $schemaSource = self::SCHEMA_SOURCE)
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
            $this->referentialErrors($blueprint),
            $this->extensionErrors($blueprint)
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
        if (isset(self::PARTIAL_ITEM_DEFS[$type])) {
            return $this->validateItems($data, $type, self::PARTIAL_ITEM_DEFS[$type]);
        }
        $def = self::PARTIAL_DEFS[$type] ?? null;
        if ($def === null) {
            $known = implode(', ', array_keys(self::PARTIAL_DEFS + self::PARTIAL_ITEM_DEFS));
            throw new Exception("Unknown partial type '{$type}'. Known types: {$known}.");
        }
        return $this->validateAgainst($data, self::SCHEMA_ID . '#/$defs/' . $def);
    }

    /**
     * Validate a list partial entry by entry against an item $def, prefixing each error path with the
     * entry's index.
     *
     * @return string[]
     */
    private function validateItems(mixed $data, string $type, string $itemDef): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return ["/: must be a list of {$type}"];
        }
        $errors = [];
        foreach ($data as $i => $entry) {
            foreach ($this->validateAgainst($entry, self::SCHEMA_ID . '#/$defs/' . $itemDef) as $error) {
                // "/: msg" (the entry itself) becomes "/1: msg"; "/title: msg" becomes "/1/title: msg"
                $errors[] = '/' . $i . (str_starts_with($error, '/:') ? substr($error, 1) : $error);
            }
        }
        return $errors;
    }

    /** The schema copy shipped with the CLI (also inside the PHAR): the offline fallback. */
    public static function bundledSchemaPath(): string
    {
        return dirname(__DIR__, 2) . '/assets/blueprints/blueprint-schema.json';
    }

    public function schemaFile(): string
    {
        return self::bundledSchemaPath();
    }

    /**
     * Bust the cached schema so the next validation re-downloads it (used by `--refresh`). Mirrors
     * AbstractRepository::refresh(). A no-op for local (uncached) sources.
     */
    public function refresh(): void
    {
        Cache::getCache()->delete($this->cacheKey());
        $this->schemaContent = false;
    }

    /** Cache key for the current schema source (avoids PSR-16 reserved chars by hashing). */
    private function cacheKey(): string
    {
        return 'blueprint-schema-' . hash('sha256', $this->schemaSource);
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
        $cacheKey = $this->cacheKey();

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
     * The shape of the CLI's item and item-set entries (x-omeka-s-cli.items / .itemSets), which the
     * shared schema does not cover: each must be a valid ItemImportConfig, and an item set has no media.
     *
     * @return string[]
     */
    private function extensionErrors(array $blueprint): array
    {
        if (!array_key_exists('x-omeka-s-cli', $blueprint)) {
            return [];
        }
        // the shared schema accepts any value under an x- key
        $extension = $blueprint['x-omeka-s-cli'];
        if (!is_array($extension) || ($extension !== [] && array_is_list($extension))) {
            return ['x-omeka-s-cli must be an object.'];
        }
        $errors = [];
        foreach (['items' => SourceFields::EXTENSION_ITEMS, 'itemSets' => SourceFields::EXTENSION_ITEM_SETS] as $key => $label) {
            if (!array_key_exists($key, $extension)) {
                continue;
            }
            $entries = $extension[$key];
            if (!is_array($entries) || !array_is_list($entries)) {
                $errors[] = "{$label} must be a list.";
                continue;
            }
            foreach ($entries as $i => $entry) {
                if ($key === 'itemSets' && is_array($entry) && !empty($entry['media'])) {
                    $errors[] = "{$label}[{$i}]: item sets have no media.";
                    continue;
                }
                try {
                    ItemImportConfig::fromArray(is_array($entry) ? $entry : []);
                } catch (InvalidArgumentException $e) {
                    $errors[] = "{$label}[{$i}]: {$e->getMessage()}";
                }
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

        // an item set may be a simple one or a CLI entry with a title
        $itemSetTitles = array_merge(
            $this->titlesLower($blueprint['itemSets'] ?? []),
            $this->titlesLower(is_array($blueprint['x-omeka-s-cli'] ?? null) && is_array($blueprint['x-omeka-s-cli']['itemSets'] ?? null) ? $blueprint['x-omeka-s-cli']['itemSets'] : [])
        );
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

        // a permission may name a blueprint user or the install admin (who exists once core is installed)
        $userEmails = [];
        foreach (($blueprint['users'] ?? []) as $user) {
            if (is_array($user) && isset($user['email'])) {
                $userEmails[] = strtolower($user['email']);
            }
        }
        $adminEmail = $blueprint['install']['admin']['email'] ?? null;
        if (is_string($adminEmail)) {
            $userEmails[] = strtolower($adminEmail);
        }

        // 'default' ships with the core, so it is always declared
        $themeNames = [strtolower(SiteConfig::DEFAULT_THEME)];
        foreach (($blueprint['themes'] ?? []) as $theme) {
            $name = is_string($theme) ? $theme : (is_array($theme) ? ($theme['name'] ?? null) : null);
            if (is_string($name)) {
                $themeNames[] = strtolower($name);
            }
        }

        foreach ($this->sites($blueprint) as $s => $site) {
            $label = $site['title'] ?? $site['slug'] ?? $s;
            $slug = $site['slug'] ?? null;
            if (is_string($slug) && !SiteConfig::isValidSlug($slug)) {
                $errors[] = "site '{$label}': invalid slug '{$slug}' (only letters, digits, '_' and '-' are allowed).";
            }
            $theme = $site['theme'] ?? null;
            if (is_string($theme) && !in_array(strtolower($theme), $themeNames, true)) {
                $errors[] = "site '{$label}': theme '{$theme}' is not declared in themes.";
            }
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
     * @return array<int, array> The blueprint's sites
     */
    private function sites(array $blueprint): array
    {
        return is_array($blueprint['sites'] ?? null) ? $blueprint['sites'] : [];
    }

    /**
     * @return string[] Lower-cased titles of the given item-set list
     */
    private function titlesLower(array $itemSets): array
    {
        $titles = [];
        foreach ($itemSets as $itemSet) {
            if (is_array($itemSet) && isset($itemSet['title']) && is_string($itemSet['title'])) {
                $titles[] = strtolower($itemSet['title']);
            }
        }
        return $titles;
    }
}
