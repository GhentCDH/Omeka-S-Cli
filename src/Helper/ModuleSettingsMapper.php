<?php

namespace OSC\Helper;

/**
 * Map an Omeka setting key to the module that owns it.
 *
 * Omeka core and every module share the same three settings tables (setting, site_setting,
 * user_setting). By convention a module prefixes each of its keys with its directory name
 * lowercased plus an underscore (e.g. the "Log" module writes "log_archive_days"). This mapper
 * reproduces that convention: a key is attributed to the module whose prefix it carries, matching
 * the longest prefix first so that "valuesuggest_x" is attributed to "ValueSuggest" and not to a
 * hypothetical "Value" module. Keys that match no known module prefix are attributed to core.
 *
 * A module that does not follow the prefix convention cannot be detected; its keys fall into core.
 */
class ModuleSettingsMapper
{
    public const CORE = 'core';

    /** @var array<string, string> prefix (lowercased, trailing "_") => module id (original case), longest prefix first */
    private array $prefixes = [];

    /**
     * @param string[] $moduleIds Module directory names, e.g. ['Log', 'ValueSuggest', 'Common']
     */
    public function __construct(array $moduleIds)
    {
        foreach ($moduleIds as $id) {
            $this->prefixes[strtolower($id) . '_'] = $id;
        }

        // Longest prefix first, so a more specific module wins over a shorter one that is a prefix of it.
        uksort($this->prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Return the module id that owns the given setting key, or self::CORE when none does.
     */
    public function moduleForKey(string $key): string
    {
        $key = strtolower($key);
        foreach ($this->prefixes as $prefix => $moduleId) {
            if (str_starts_with($key, $prefix)) {
                return $moduleId;
            }
        }

        return self::CORE;
    }
}
