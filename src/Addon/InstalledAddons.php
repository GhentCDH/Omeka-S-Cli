<?php
namespace OSC\Addon;

use OSC\Exceptions\NotFoundException;
use OSC\Helper\Path;

/**
 * The add-ons on disk in an Omeka S installation (downloaded, whether installed in Omeka S or not).
 */
final class InstalledAddons
{
    public function __construct(private string $omekaPath)
    {
    }

    /** The folder an add-on lives in (or would). */
    public function path(AddonType $type, string $name): string
    {
        return rtrim($this->omekaPath, '/') . '/' . $type->dir() . '/' . $name;
    }

    /** Whether the add-on's folder exists. */
    public function exists(AddonType $type, string $name): bool
    {
        return $name !== '' && is_dir($this->path($type, $name));
    }

    /**
     * The folder of an add-on on disk, matching the name case-insensitively (as the repositories do),
     * or null when it is not on disk. An exact match wins.
     */
    public function find(AddonType $type, string $name): ?string
    {
        if (!Path::isSafeFolderName($name)) {
            return null;
        }
        if ($this->exists($type, $name)) {
            return $name;
        }
        $dir = rtrim($this->omekaPath, '/') . '/' . $type->dir();
        foreach (is_dir($dir) ? scandir($dir) : [] as $entry) {
            if (strcasecmp($entry, $name) === 0 && is_dir("{$dir}/{$entry}")) {
                return $entry;
            }
        }
        return null;
    }

    /** The version in the add-on's ini, or null when it is not on disk or declares none. */
    public function version(AddonType $type, string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        try {
            return AddonIni::fromAddonDir($this->path($type, $name), $type)->version();
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Whether a pinned version differs from the one on disk (so the add-on must be downloaded
     * again). No pinned version, or no version on disk, is not a difference.
     */
    public function versionDiffers(AddonType $type, string $name, ?string $want): bool
    {
        if (!$want) {
            return false;
        }
        $have = $this->version($type, $name);
        return $have !== null && !AddonVersion::equals($have, $want);
    }
}
