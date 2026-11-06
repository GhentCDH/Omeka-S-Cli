<?php

namespace OSC\Helper;

/**
 * Orders modules so that a module is never left without a dependency it needs.
 *
 * Operations that build modules up (enable, upgrade) handle dependencies first, operations that
 * tear them down (disable, uninstall) handle them last.
 *
 * The order is maintained by hand: Omeka S modules do not declare their dependencies in
 * config/module.ini, so there is nothing on disc to derive it from.
 */
class ModuleDependencyOrder
{
    /**
     * Modules that other modules depend on, most depended upon first.
     */
    private const ORDER = [
        'Common' => 1,
        'Log' => 10,
        'IiifServer' => 20,
    ];

    /**
     * Rank given to modules that nothing is known to depend on.
     */
    private const UNRANKED = 1000;

    /**
     * Order modules with their dependencies first.
     *
     * @param string[] $moduleIds Module ids in any order
     *
     * @return string[] The same ids, dependencies first
     */
    public static function sort(array $moduleIds): array
    {
        usort($moduleIds, fn($a, $b) => self::rank($a) <=> self::rank($b));

        return $moduleIds;
    }

    /**
     * Order modules with their dependencies last.
     *
     * @param string[] $moduleIds Module ids in any order
     *
     * @return string[] The same ids, dependencies last
     */
    public static function reverseSort(array $moduleIds): array
    {
        usort($moduleIds, fn($a, $b) => self::rank($b) <=> self::rank($a));

        return $moduleIds;
    }

    /**
     * How early a module has to be handled; the lower the rank, the more depends on it.
     *
     * @param string $moduleId Module id to look up
     *
     * @return int The rank, or UNRANKED when nothing is known to depend on the module
     */
    private static function rank(string $moduleId): int
    {
        return self::ORDER[$moduleId] ?? self::UNRANKED;
    }
}
