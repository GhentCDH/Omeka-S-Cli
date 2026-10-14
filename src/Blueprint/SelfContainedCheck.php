<?php
namespace OSC\Blueprint;

/**
 * The self-contained rules a loader cannot see, because nothing is written to resolve: an add-on
 * entry without a source (it would be looked up in a catalog) and, when the core will be installed,
 * a missing core source (the release would be downloaded). Reference violations are collected by
 * BlueprintLoader in self-contained mode.
 */
final class SelfContainedCheck
{
    /** Themes that ship with the Omeka S core, so they need no source. */
    public const CORE_THEMES = ['default'];

    /**
     * @param bool $coreWillInstall Whether the core phase will download and install Omeka S
     * @return string[]
     */
    public static function violations(Blueprint $blueprint, bool $coreWillInstall): array
    {
        $violations = [];
        foreach (['modules' => $blueprint->modules(), 'themes' => $blueprint->themes()] as $key => $entries) {
            foreach ($entries as $entry) {
                $name = is_string($entry) ? $entry : (string) ($entry['name'] ?? '');
                $source = is_array($entry) ? ($entry['source'] ?? null) : null;
                if (is_string($source) && $source !== '') {
                    continue;
                }
                if ($key === 'themes' && in_array($name, self::CORE_THEMES, true)) {
                    continue;
                }
                $violations[] = "{$key} '{$name}' has no source: it would be downloaded from a catalog.";
            }
        }
        if ($coreWillInstall && $blueprint->installSource() === null) {
            $violations[] = SourceFields::CORE_SOURCE . ' is missing: the Omeka S core would be downloaded.';
        }
        return $violations;
    }
}
