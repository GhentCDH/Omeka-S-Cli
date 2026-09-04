<?php
namespace OSC\Blueprint;

use Exception;
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
 * De-duplication: within a resolved list, entries sharing a natural identity (module/theme `name`,
 * vocabulary `prefix`, resource-template `label`, user `email`, item/item-set `title`) collapse to
 * the last occurrence, so a later inline entry — or a later import — overrides an earlier one.
 */
class BlueprintLoader
{
    /** Keys whose value is a list of items that may contain `$import` references. */
    private const LIST_KEYS = ['modules', 'themes', 'vocabularies', 'resourceTemplates', 'users', 'itemSets', 'items'];

    /** Absolute sources currently being resolved, to detect circular imports. */
    private array $visiting = [];

    /**
     * Load and fully resolve a blueprint.
     *
     * @param string $source Path or URL to the blueprint
     * @return Blueprint The normalized, import-resolved blueprint
     * @throws Exception On fetch/parse errors or circular imports
     */
    public function load(string $source): Blueprint
    {
        $blueprint = $this->decodeObject($source);
        return new Blueprint($this->resolve($blueprint, $source));
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
        $content = ResourceFetcher::fetch($source);
        $data = $this->decode($content);

        if ($type === 'settings') {
            return $this->resolveSettings($data, $source);
        }

        if (!is_array($data)) {
            throw new Exception("Partial '{$source}' must be a JSON array or object.");
        }
        // a single item is allowed; wrap it so it is treated as a one-element list
        if (!array_is_list($data)) {
            $data = [$data];
        }
        return $this->resolveList($data, $source, $type);
    }

    private function decodeObject(string $source): array
    {
        $content = ResourceFetcher::fetch($source);
        $data = $this->decode($content);
        if (!is_array($data) || array_is_list($data)) {
            throw new Exception("Blueprint '{$source}' must be a JSON object.");
        }
        return $data;
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
                $resolved[] = $entry;
                continue;
            }

            $ref = $this->resolveRef($entry['$import'], $source);
            $imported = $this->importList($ref, $key);
            foreach ($imported as $item) {
                $resolved[] = $item;
            }
        }
        return $this->dedupe($resolved, $key);
    }

    /**
     * Fetch, decode and recursively resolve an imported list, guarding against cycles.
     *
     * @return array
     * @throws Exception
     */
    private function importList(string $ref, string $key): array
    {
        $guard = $this->guardKey($ref);
        if (isset($this->visiting[$guard])) {
            throw new Exception("Circular \$import detected at '{$ref}'.");
        }
        $this->visiting[$guard] = true;
        try {
            $content = ResourceFetcher::fetch($ref);
            $data = $this->decode($content);
            if (!is_array($data)) {
                throw new Exception("Imported source '{$ref}' must be a JSON array or object.");
            }
            if (!array_is_list($data)) {
                $data = [$data];
            }
            return $this->resolveList($data, $ref, $key);
        } finally {
            unset($this->visiting[$guard]);
        }
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
                $ref = $this->resolveRef($entry['$import'], $source);
                $imported = $this->resolveSettings($this->decode(ResourceFetcher::fetch($ref)), $ref);
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

    private function isReference(mixed $entry): bool
    {
        return is_array($entry) && array_key_exists('$import', $entry);
    }

    /**
     * Resolve a reference relative to the source that contains it.
     */
    private function resolveRef(string $ref, string $base): string
    {
        if (ResourceFetcher::isUrl($ref) || str_starts_with($ref, '/')) {
            return $ref;
        }
        if (ResourceFetcher::isUrl($base)) {
            // resolve relative to the base URL's directory (simple join; no ../ handling)
            return preg_replace('#/[^/]*$#', '/', $base) . $ref;
        }
        return rtrim(dirname($base), '/') . '/' . $ref;
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
            unset($keyed[$id]); // drop earlier occurrence so the last one keeps last position
            $keyed[$id] = $entry;
        }
        return array_merge(array_values($keyed), $loose);
    }

    private function identity(mixed $entry, string $key): string
    {
        if (is_string($entry)) {
            // bare string form (module/theme name)
            return strtolower($entry);
        }
        if (!is_array($entry)) {
            return '';
        }
        $field = match ($key) {
            'modules', 'themes'   => $entry['name'] ?? '',
            'vocabularies'        => $entry['prefix'] ?? '',
            'resourceTemplates'   => $entry['label'] ?? $entry['source'] ?? '',
            'users'               => $entry['email'] ?? '',
            'itemSets', 'items'   => $entry['title'] ?? '',
            default               => '',
        };
        return is_string($field) ? strtolower($field) : '';
    }
}
