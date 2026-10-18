<?php
namespace OSC\Helper;

use InvalidArgumentException;
use OSC\Blueprint\SourceFields;
use ZipArchive;

/**
 * A module or theme release ZIP, read in place (nothing is unpacked): where the add-on sits in the
 * archive, its ini version and Omeka S constraint, and a module's namespace.
 *
 * The add-on is the folder of the shallowest `config/module.ini` (or `theme.ini`). A deploy unpacks
 * the ZIP and finds it with Path::findSubpath(), which searches depth-first: the two agree whenever
 * the candidates are nested (`Foo/` and `Foo/vendor/x/`), as in a release ZIP with one top folder.
 */
final class AddonZip
{
    /**
     * @param string      $folder     The add-on's folder as an entry prefix: '' or ending in '/'
     * @param string      $version    The ini version
     * @param string      $constraint The ini omeka_version_constraint, '' when absent
     * @param string|null $namespace  A module's namespace (null for a theme, or without Module.php)
     */
    private function __construct(
        public readonly string $folder,
        public readonly string $version,
        public readonly string $constraint,
        public readonly ?string $namespace,
    ) {
    }

    /**
     * @param string      $kind SourceFields::MODULE or SourceFields::THEME
     * @param string|null $name What messages call the ZIP (e.g. the URL it was downloaded from); default $path
     * @throws InvalidArgumentException When the file is no ZIP, or has no readable ini with a version
     */
    public static function read(string $path, string $kind, ?string $name = null): self
    {
        $name ??= $path;
        $iniName = 'config/' . ($kind === SourceFields::MODULE ? 'module.ini' : 'theme.ini');
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException("{$name} is not a ZIP archive");
        }
        try {
            $folder = self::addonFolder($zip, $iniName);
            if ($folder === null) {
                throw new InvalidArgumentException("no {$iniName} found in {$name}");
            }
            $iniSrc = $zip->getFromName("{$folder}{$iniName}");
            if ($iniSrc === false) {
                throw new InvalidArgumentException("cannot read {$folder}{$iniName} in {$name}");
            }
            $moduleSrc = $kind === SourceFields::MODULE ? $zip->getFromName("{$folder}Module.php") : false;
        } finally {
            $zip->close();
        }

        $ini = @parse_ini_string($iniSrc, true);
        $info = is_array($ini) ? ($ini['info'] ?? $ini) : [];
        $version = trim((string) ($info['version'] ?? ''));
        if ($version === '') {
            throw new InvalidArgumentException("{$iniName} has no version");
        }
        return new self(
            $folder,
            $version,
            trim((string) ($info['omeka_version_constraint'] ?? '')),
            $moduleSrc !== false ? self::moduleNamespace($moduleSrc) : null,
        );
    }

    /** The namespace a module's Module.php declares (Omeka requires it to be the module's folder). */
    public static function moduleNamespace(string $src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }

    /** The entry prefix ('' or ending in '/') of the shallowest $subpath in the archive, or null. */
    private static function addonFolder(ZipArchive $zip, string $subpath): ?string
    {
        $found = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (!str_ends_with($entry, $subpath)) {
                continue;
            }
            $prefix = substr($entry, 0, -strlen($subpath));
            if ($prefix !== '' && !str_ends_with($prefix, '/')) {
                continue;
            }
            if ($found === null || substr_count($prefix, '/') < substr_count($found, '/')) {
                $found = $prefix;
            }
        }
        return $found;
    }
}
