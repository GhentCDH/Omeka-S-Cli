<?php
namespace OSC\Blueprint\Bundle;

/** A module or theme written into a bundle. */
final class PackagedAddon
{
    /**
     * @param string   $name     The add-on's folder name (what the bundled blueprint calls it)
     * @param string   $version  The version in its ini file
     * @param string   $zipPath  The ZIP, relative to the bundle directory (e.g. modules/Common-3.4.71.zip)
     * @param string[] $warnings Advisory messages (renamed, version mismatch, ...)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $zipPath,
        public readonly array $warnings = [],
    ) {
    }
}
