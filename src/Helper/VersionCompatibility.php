<?php

namespace OSC\Helper;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use OSC\Repository\OmekaVersionableInterface;

/**
 * @template T of OmekaVersionableInterface
 */
class VersionCompatibility
{
    /**
     * Returns the latest version from $versions that satisfies $omekaVersion.
     * Versions without a constraint are treated as universally compatible.
     *
     * @param T[] $versions
     * @return T|null
     */
    public static function getLatestCompatible(array $versions, string $omekaVersion): mixed
    {
        $best = null;
        foreach ($versions as $version) {
            $constraint = $version->getOmekaVersionConstraint();
            if ($constraint !== null && !Semver::satisfies($omekaVersion, $constraint)) {
                continue;
            }
            if ($best === null || Comparator::greaterThan($version->getVersionNumber(), $best->getVersionNumber())) {
                $best = $version;
            }
        }
        return $best;
    }

    /**
     * Returns true if $version has no Omeka constraint or if $omekaVersion satisfies it.
     */
    public static function isCompatible(OmekaVersionableInterface $version, string $omekaVersion): bool
    {
        $constraint = $version->getOmekaVersionConstraint();
        return $constraint === null || Semver::satisfies($omekaVersion, $constraint);
    }
}
