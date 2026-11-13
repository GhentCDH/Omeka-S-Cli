<?php

namespace OSC\Addon;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
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
     * Whether $candidate is a newer version than $current: 'v' prefixes ignored, parts compared as
     * numbers, a missing trailing zero is equal (1.1 = 1.1.0), a pre-release is below its release.
     * A version Composer cannot parse is compared as given.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare(self::comparable($candidate), self::comparable($current), '>');
    }

    /**
     * Whether an update would install a newer version than $installed: the latest release that is
     * compatible with $omekaVersion (what module:download/theme:download pick), not the latest one.
     * A local version newer than that is not updatable, so --all never downgrades it.
     *
     * @param OmekaVersionableInterface[]|null $versions The repository's releases (null: not in a repository)
     * @return bool|null Null when the installed or the repository versions are unknown
     */
    public static function updateAvailable(?string $installed, ?array $versions, string $omekaVersion): ?bool
    {
        if ($installed === null || $installed === '' || $versions === null) {
            return null;
        }
        $target = self::latestCompatible($versions, $omekaVersion);
        return $target !== null && self::isNewer($target->getVersionNumber(), $installed);
    }

    /** $version in Composer's normalized form (1.1 -> 1.1.0.0), or as given when it cannot be parsed. */
    private static function comparable(string $version): string
    {
        $version = self::normalize($version);
        try {
            return (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException) {
            return $version;
        }
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
