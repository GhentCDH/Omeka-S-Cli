<?php
namespace OSC\Addon;

use OSC\Exceptions\NotFoundException;

/**
 * The [info] section of an add-on's config/module.ini or config/theme.ini: its name, version and
 * Omeka S version constraint. An ini without sections is read as the info itself.
 *
 * Only the info is exposed: it is all this tool reads (a theme's [config] section describes its
 * settings form, which is Omeka's business).
 */
final class AddonIni
{
    /** @param array<string,mixed> $info */
    private function __construct(private array $info)
    {
    }

    /**
     * Read config/<module|theme>.ini in an add-on's folder (e.g. modules/Common, or the folder found
     * in an unpacked release).
     *
     * @throws NotFoundException When the file is missing or is not a valid ini file
     */
    public static function fromAddonDir(string $addonDir, AddonType $type): self
    {
        return self::fromFile(rtrim($addonDir, '/') . '/config/' . $type->iniName());
    }

    /** @throws NotFoundException When the file is missing or is not a valid ini file */
    public static function fromFile(string $path): self
    {
        $ini = is_file($path) ? @parse_ini_file($path, true) : false;
        if (!is_array($ini) || $ini === []) {
            throw new NotFoundException('No valid ' . basename($path) . " found in '" . dirname($path) . "'.");
        }
        $info = isset($ini['info']) && is_array($ini['info']) ? $ini['info'] : $ini;
        return new self($info);
    }

    public function name(): ?string
    {
        return $this->string('name');
    }

    public function version(): ?string
    {
        return $this->string('version');
    }

    public function omekaConstraint(): ?string
    {
        return $this->string('omeka_version_constraint');
    }

    /** @return array<string,mixed> The whole info section */
    public function info(): array
    {
        return $this->info;
    }

    /** A trimmed info value, or null when absent or empty. */
    private function string(string $key): ?string
    {
        $value = $this->info[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
