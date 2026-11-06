<?php

namespace OSC\Addon;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use OSC\Repository\OmekaVersionableInterface;

/**
 * Add-on versions: comparing them, and checking them against the Omeka S version.
 */
final class AddonVersion
{
    /** A version without surrounding blanks or a leading 'v' ('v1.2.0' and '1.2.0' are the same). */
    public static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /** Whether two versions are the same, once normalized. */
    public static function equals(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Whether $omekaVersion satisfies an add-on's Omeka S version constraint. No constraint (null or
     * '') is compatible.
     *
     * @throws \UnexpectedValueException When the constraint cannot be parsed
     */
    public static function satisfies(string $omekaVersion, ?string $constraint): bool
    {
        if ($constraint === null || trim($constraint) === '') {
            return true;
        }
        return Semver::satisfies($omekaVersion, $constraint);
    }

    /**
     * The latest of $versions that declares an Omeka S constraint which $omekaVersion satisfies.
     * Versions without a constraint are skipped.
     *
     * @template T of OmekaVersionableInterface
     * @param T[] $versions
     * @return T|null
     */
    public static function latestCompatible(array $versions, string $omekaVersion): mixed
    {
        $best = null;
        foreach ($versions as $version) {
            $constraint = $version->getOmekaVersionConstraint();
            if (empty($constraint)) {
                continue;
            }
            if (!Semver::satisfies($omekaVersion, $constraint)) {
                continue;
            }
            if ($best === null || Comparator::greaterThan($version->getVersionNumber(), $best->getVersionNumber())) {
                $best = $version;
            }
        }
        return $best;
    }

    /**
     * Whether $version has no Omeka S constraint, or one that $omekaVersion satisfies.
     */
    public static function isCompatible(OmekaVersionableInterface $version, string $omekaVersion): bool
    {
        $constraint = $version->getOmekaVersionConstraint();
        return $constraint === null || Semver::satisfies($omekaVersion, $constraint);
    }
}
