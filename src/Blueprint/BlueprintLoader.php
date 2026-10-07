<?php
namespace OSC\Blueprint;

use Exception;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\ResourceFetcher;
use Otar\JSONC;

/**
 * Load a blueprint from a file or URL, parse it as jsonc, and resolve every `{ "$import": <uri> }`
 * reference into a single, fully inlined blueprint array.
 *
 * References live under the key they extend (`modules`, `themes`, ...): an `$import` entry is
 * replaced in place by the items of the referenced list (itself jsonc, and itself allowed to
 * contain further `$import` entries). Referenced sources are resolved relative to the file/URL that
 * contains them. Circular references are detected and rejected.
 *
 * References written in a blueprint (`$import` and the asset fields) must be URLs, repo-aware
 * references or relative paths: absolute paths and `file:` URLs are rejected, and a relative path
 * that resolves outside the blueprint root (by default the top-level blueprint's directory) is
 * rejected too, so a blueprint can never reach an arbitrary file on the local filesystem.
 *
 * De-duplication: within a resolved list, entries sharing a natural identity (module/theme `name`,
 * file `destination`, vocabulary `prefix`, resource-template `label`, user `email`, item/item-set
 * `title`) collapse to the last occurrence, so a later inline entry — or a later import — overrides
 * an earlier one. When such an override actually changes the value, an advisory warning is recorded
 * (see takeWarnings()), so intentional layering keeps working while an accidental duplicate stays
 * visible.
 */
class BlueprintLoader
{
    /** Keys whose value is a list of items that may contain `$import` references. */
    private const LIST_KEYS = ['modules', 'themes', 'files', 'vocabularies', 'resourceTemplates', 'users', 'sites', 'itemSets', 'items'];

    /**
     * Per-list item fields that hold a relative asset reference. They are resolved against the source
     * that declares the item — so a vocabulary pulled in via `$import` resolves its `source` against
     * the imported config's location, not the top-level blueprint's.
     */
    private const ASSET_FIELDS = [
        'files' => ['source'],
        'vocabularies' => ['source'],
        'resourceTemplates' => ['source'],
    ];

    /** An absolute filesystem path (POSIX, UNC/backslash, Windows drive) or a file: URL. */
    private const ABSOLUTE_PATTERN = '#^(/|\\\\|[A-Za-z]:[\\\\/]|file:)#i';

    /** Absolute sources currently being resolved, to detect circular imports. */
    private array $visiting = [];

    /** Advisory messages gathered during the current load (e.g. a duplicate that overrode a value). */
    private array $warnings = [];

    /** Resolves repo-aware and relative references (`$import`) into fetchable paths/URLs. */
    private ReferenceResolver $resolver;

    /** Local directory every local reference must stay inside, for the current load (null: none). */
    private ?string $root = null;

    /**
     * @param ReferenceResolver|null $resolver Resolver for repo-aware and relative references
     * @param string|null            $rootOverride Directory local references must stay inside; defaults
     *                                             to the directory of the top-level blueprint
     */
    public function __construct(?ReferenceResolver $resolver = null, private ?string $rootOverride = null)
    {
        $this->resolver = $resolver ?? ReferenceResolver::withDefaults();
    }

    /**
     * Load and fully resolve a blueprint.
     *
     * @param string $source Path or URL to the blueprint (a repo-aware reference is accepted too)
     * @return Blueprint The normalized, import-resolved blueprint
     * @throws Exception On fetch/parse errors, circular imports or a reference outside the root
     */
    public function load(string $source): Blueprint
    {
        $source = $this->begin($source);
        return $this->guarded($source, function () use ($source) {
            $blueprint = $this->decodeObject(ResourceFetcher::fetch($source), $source);
            return new Blueprint($this->resolve($blueprint, $source));
        });
    }

    /**
     * Advisory messages collected during the most recent load()/loadPartial(), then cleared.
     *
     * Currently: a notice that a later entry (often from an $import) overrode an earlier one with a
     * different value. A duplicate that re-declares an identical value is not reported.
     *
     * @return string[]
     */
    public function takeWarnings(): array
    {
        $warnings = array_values(array_unique($this->warnings));
        $this->warnings = [];
        return $warnings;
    }

    /** Decode jsonc content (comments and trailing commas allowed), throwing on invalid JSON. */
    private function decode(string $content): mixed
    {
        return JSONC::decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Load and resolve a standalone partial (a bare list, or the settings map/list).
     *
     * @param string $source Path or URL to the partial
     * @param string $type   One of the list keys, or 'settings'
     * @return mixed The resolved list (or settings map)
     * @throws Exception
     */
    public function loadPartial(string $source, string $type): mixed
    {
        $source = $this->begin($source);
        return $this->guarded($source, function () use ($source, $type) {
            $data = $this->decode(ResourceFetcher::fetch($source));
            if ($type === 'settings') {
                return $this->resolveSettings($data, $source);
            }
            return $this->resolveList($this->asList($data, $source), $source, $type);
        });
    }

    /**
     * Reset the per-load state and resolve the top-level source. The top-level source is given by the
     * user, not written in a blueprint, so it may be absolute; it sets the default blueprint root.
     */
    private function begin(string $source): string
    {
        $this->warnings = [];
        $this->visiting = [];
        $source = $this->resolver->resolve($source);

        if (ResourceFetcher::isFile($source)) {
            // an absolute top-level path makes every local reference below it absolute too
            $source = realpath($source) ?: $source;
        }

        $this->root = null;
        if ($this->rootOverride !== null) {
            $root = realpath($this->rootOverride);
            if ($root === false || !is_dir($root)) {
                throw new Exception("Blueprint root '{$this->rootOverride}' is not a directory.");
            }
            $this->root = $root;
        } elseif (ResourceFetcher::isFile($source)) {
            $this->root = dirname($source);
        }
        return $source;
    }

    /** Decode jsonc content that must be a JSON object (not a list); $source is used for errors only. */
    private function decodeObject(string $content, string $source): array
    {
        $data = $this->decode($content);
        if (!is_array($data) || array_is_list($data)) {
            throw new Exception("Blueprint '{$source}' must be a JSON object.");
        }
        return $data;
    }

    /** A decoded list source as a list: a single item (an object) becomes a one-element list. */
    private function asList(mixed $data, string $source): array
    {
        if (!is_array($data)) {
            throw new Exception("'{$source}' must be a JSON array or object.");
        }
        return array_is_list($data) ? $data : [$data];
    }

    private function resolve(array $blueprint, string $source): array
    {
        foreach (self::LIST_KEYS as $key) {
            if (isset($blueprint[$key]) && is_array($blueprint[$key])) {
                $blueprint[$key] = $this->resolveList($blueprint[$key], $source, $key);
            }
        }
        if (isset($blueprint['settings'])) {
            $blueprint['settings'] = $this->resolveSettings($blueprint['settings'], $source);
        }
        return $blueprint;
    }

    /**
     * Resolve every `$import` in a list and de-duplicate the result.
     *
     * @param array  $list   The raw list (inline items and/or references)
     * @param string $source The source that contains the list (for relative resolution)
     * @param string $key    The list key (for identity/de-duplication)
     * @return array
     * @throws Exception
     */
    private function resolveList(array $list, string $source, string $key): array
    {
        $resolved = [];
        foreach ($list as $entry) {
            if (!$this->isReference($entry)) {
                // an inline item is declared by `$source`, so its relative asset refs resolve against it
                $resolved[] = $this->resolveItemAssets($entry, $source, $key);
                continue;
            }

            // items pulled in by an import were already asset-resolved against the imported source
            $imported = $this->import(
                $entry['$import'],
                $source,
                fn(mixed $data, string $ref) => $this->resolveList($this->asList($data, $ref), $ref, $key)
            );
            foreach ($imported as $item) {
                $resolved[] = $item;
            }
        }
        return $this->dedupe($resolved, $key);
    }

    /**
     * Resolve the settings value into a single flat map of id => value.
     *
     * Accepts an inline map, or a list of maps/references merged in order (later values win).
     *
     * @param mixed  $settings
     * @param string $source
     * @return array
     * @throws Exception
     */
    private function resolveSettings(mixed $settings, string $source): array
    {
        if (!is_array($settings)) {
            throw new Exception("The 'settings' value in '{$source}' must be an object or an array.");
        }

        // inline map
        if (!array_is_list($settings)) {
            return $settings;
        }

        // list of maps / references, merged in order
        $merged = [];
        foreach ($settings as $entry) {
            if ($this->isReference($entry)) {
                $imported = $this->import(
                    $entry['$import'],
                    $source,
                    fn(mixed $data, string $ref) => $this->resolveSettings($data, $ref)
                );
                $merged = array_merge($merged, $imported);
                continue;
            }
            if (!is_array($entry) || array_is_list($entry)) {
                throw new Exception("Each entry of a 'settings' list must be a map or an \$import reference.");
            }
            $merged = array_merge($merged, $entry);
        }
        return $merged;
    }

    /**
     * The single path for an `$import`: check and resolve the reference against the source that
     * contains it, guard against cycles, then fetch, decode and hand the data to $resolve.
     *
     * @param mixed    $raw     The `$import` value
     * @param string   $base    The source containing the reference
     * @param callable $resolve fn(mixed $data, string $ref): mixed
     * @throws Exception
     */
    private function import(mixed $raw, string $base, callable $resolve): mixed
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new Exception("An \$import in '{$base}' must be a non-empty string.");
        }
        $ref = $this->reference($raw, $base);
        return $this->guarded($ref, fn() => $resolve($this->decode(ResourceFetcher::fetch($ref)), $ref));
    }

    /**
     * Run $fn with $source on the stack of sources being resolved, rejecting a source that is already
     * on it (a circular import).
     *
     * @throws Exception
     */
    private function guarded(string $source, callable $fn): mixed
    {
        $guard = $this->guardKey($source);
        if (isset($this->visiting[$guard])) {
            throw new Exception("Circular \$import detected at '{$source}'.");
        }
        $this->visiting[$guard] = true;
        try {
            return $fn();
        } finally {
            unset($this->visiting[$guard]);
        }
    }

    /**
     * Turn a reference written in a blueprint into a fetchable path or URL: reject absolute paths and
     * file: URLs, resolve it against the source that contains it, and require a local result to stay
     * inside the blueprint root. A URL result needs no check: a relative reference in a remote file
     * always resolves to a URL.
     *
     * @throws Exception
     */
    private function reference(string $raw, string $base): string
    {
        $raw = trim($raw);
        if (preg_match(self::ABSOLUTE_PATTERN, $raw) === 1) {
            throw new Exception(
                "Reference '{$raw}' in '{$base}' is an absolute path or file: URL; use a relative path or a URL."
            );
        }

        $ref = $this->resolver->resolve($raw, $base);
        if (ResourceFetcher::isUrl($ref)) {
            return $ref;
        }
        if ($this->root === null || !$this->isInside($ref, $this->root)) {
            $root = $this->root ?? '(none: the blueprint is not a local file)';
            throw new Exception(
                "Reference '{$raw}' in '{$base}' resolves outside the blueprint root {$root}; use --root to widen it."
            );
        }
        return $ref;
    }

    /** Whether a local path lies inside $root; an existing path is compared by its real path (symlinks). */
    private function isInside(string $path, string $root): bool
    {
        $path = realpath($path) ?: $path;
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    /**
     * Resolve an inline item's relative asset fields (see ASSET_FIELDS) against the source that
     * declares it. Repo-aware references become raw URLs; URLs pass through.
     *
     * @param mixed  $entry  The inline item
     * @param string $source The source declaring the item (for relative resolution)
     * @param string $key    The list key (selects which fields are asset references)
     * @return mixed The item with its asset fields resolved
     * @throws Exception
     */
    private function resolveItemAssets(mixed $entry, string $source, string $key): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }
        foreach (self::ASSET_FIELDS[$key] ?? [] as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field]) || trim($entry[$field]) === '') {
                continue;
            }
            $entry[$field] = $this->reference($entry[$field], $source);
        }
        return $entry;
    }

    private function isReference(mixed $entry): bool
    {
        return is_array($entry) && array_key_exists('$import', $entry);
    }

    private function guardKey(string $source): string
    {
        return ResourceFetcher::isUrl($source) ? $source : (realpath($source) ?: $source);
    }

    /**
     * Collapse entries with the same natural identity, keeping the last occurrence.
     *
     * @param array  $list
     * @param string $key
     * @return array
     */
    private function dedupe(array $list, string $key): array
    {
        $keyed = [];
        $loose = [];
        foreach ($list as $entry) {
            $id = $this->identity($entry, $key);
            if ($id === '') {
                $loose[] = $entry;
                continue;
            }
            // a later entry with the same identity but a different value overrides the earlier one;
            // record it so an accidental duplicate is visible while intentional layering still works
            if (array_key_exists($id, $keyed) && $keyed[$id] != $entry) {
                $label = $this->identityLabel($entry, $key);
                $this->warnings[] = "{$key}: '{$label}' is declared more than once; the later definition overrides the earlier one.";
            }
            unset($keyed[$id]); // drop earlier occurrence so the last one keeps last position
            $keyed[$id] = $entry;
        }
        return array_merge(array_values($keyed), $loose);
    }

    private function identity(mixed $entry, string $key): string
    {
        return strtolower($this->identityLabel($entry, $key));
    }

    /** The natural-identity value of an entry, in its original casing (for messages). */
    private function identityLabel(mixed $entry, string $key): string
    {
        if (is_string($entry)) {
            // bare string form (module/theme name)
            return $entry;
        }
        if (!is_array($entry)) {
            return '';
        }
        $field = match ($key) {
            'modules', 'themes'   => $entry['name'] ?? '',
            'files'               => $entry['destination'] ?? '',
            'vocabularies'        => $entry['prefix'] ?? '',
            'resourceTemplates'   => $entry['label'] ?? $entry['source'] ?? '',
            'users'               => $entry['email'] ?? '',
            'sites'               => $entry['slug'] ?? $entry['title'] ?? '',
            'itemSets', 'items'   => $entry['title'] ?? '',
            default               => '',
        };
        return is_string($field) ? $field : '';
    }
}
