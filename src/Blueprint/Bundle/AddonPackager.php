<?php
namespace OSC\Blueprint\Bundle;

use Composer\Semver\Semver;
use Exception;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\ResolvedAddon;
use OSC\Helper\ResourceFetcher;
use Throwable;
use ZipArchive;

/**
 * Copy a resolved module or theme release ZIP into a bundle, unchanged, after checking it:
 * - its ini file has a version, and the add-on fits the target Omeka S version;
 * - a module's namespace is its blueprint name.
 *
 * It is saved as `<modules|themes>/<Name>-<version>.zip`. <Name> is the add-on's folder name:
 * - a module's namespace (Omeka requires it to be the folder);
 * - a catalog theme's dirname;
 * - else the blueprint name.
 *
 * Only release ZIPs are bundled: a git checkout may need build steps before it can be installed, so
 * a git source is refused. The ZIP is read in place; where its files sit inside it does not matter,
 * because a deploy looks for the add-on folder the same way (see Path::findSubpath).
 */
class AddonPackager
{
    /**
     * @param string $kind          SourceFields::MODULE or SourceFields::THEME
     * @param string $name          The blueprint name
     * @param string $bundleDir     The bundle directory the ZIP is written under
     * @throws Exception With a message about the add-on (the caller adds which entry it is)
     */
    public function package(
        string $kind,
        string $name,
        ResolvedAddon $resolved,
        ?string $pinnedVersion,
        string $omekaVersion,
        bool $allowIncompatible,
        string $bundleDir,
    ): PackagedAddon {
        if ($resolved->form === ResolvedAddon::GIT) {
            throw new Exception("a git source can't be bundled; a bundle holds release ZIPs. Point source to a release ZIP (URL or path).");
        }
        $iniName = $kind === SourceFields::MODULE ? 'module.ini' : 'theme.ini';
        $source = $resolved->downloader->getDownloadUrl();
        $download = "{$bundleDir}/.addon.download";
        try {
            ResourceFetcher::download($source, $download);
            $zip = new ZipArchive();
            if ($zip->open($download, ZipArchive::RDONLY) !== true) {
                throw new Exception("{$source} is not a ZIP archive");
            }
            try {
                $prefix = self::addonFolder($zip, "config/{$iniName}");
                if ($prefix === null) {
                    throw new Exception("no config/{$iniName} found in {$source}");
                }
                $ini = @parse_ini_string((string) $zip->getFromName("{$prefix}config/{$iniName}"), true);
                $moduleSrc = $kind === SourceFields::MODULE ? $zip->getFromName("{$prefix}Module.php") : false;
            } finally {
                $zip->close();
            }
            $info = is_array($ini) ? ($ini['info'] ?? $ini) : [];
            $version = trim((string) ($info['version'] ?? ''));
            if ($version === '') {
                throw new Exception("config/{$iniName} has no version");
            }

            $warnings = [];
            if ($pinnedVersion !== null && $resolved->form === ResolvedAddon::ZIP
                && self::normalizeVersion($pinnedVersion) !== self::normalizeVersion($version)) {
                $warnings[] = "version {$pinnedVersion} is pinned, but the ZIP contains {$version}; the ZIP wins";
            }
            $incompatibility = self::incompatibility((string) ($info['omeka_version_constraint'] ?? ''), $omekaVersion);
            if ($incompatibility !== null) {
                if (!$allowIncompatible) {
                    throw new Exception("{$incompatibility}; use --allow-incompatible to bundle it anyway");
                }
                $warnings[] = $incompatibility;
            }

            $folder = $this->folderName($kind, $name, $resolved, $moduleSrc);
            if ($folder !== $name) {
                $warnings[] = "renamed to '{$folder}', its folder name";
            }

            $zipPath = ($kind === SourceFields::MODULE ? 'modules' : 'themes')
                . "/{$folder}-" . preg_replace('/[^A-Za-z0-9._-]/', '_', $version) . '.zip';
            $zipDir = dirname("{$bundleDir}/{$zipPath}");
            if (!is_dir($zipDir) && !mkdir($zipDir, 0777, true) && !is_dir($zipDir)) {
                throw new Exception("could not create {$zipDir}");
            }
            if (!rename($download, "{$bundleDir}/{$zipPath}")) {
                throw new Exception("could not write {$zipPath}");
            }

            return new PackagedAddon($folder, $version, $zipPath, $warnings);
        } finally {
            if (is_file($download)) {
                @unlink($download);
            }
        }
    }

    /**
     * The folder (as an entry prefix: '' or ending in '/') of the shallowest $subpath in the archive,
     * or null when it has none. A deploy picks the same folder, as it unpacks the ZIP and looks for
     * the add-on from the top down.
     */
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

    /** The namespace a module's Module.php declares (Omeka requires it to be the module's folder). */
    public static function moduleNamespace(string $src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }

    /**
     * @param string|false $moduleSrc The module's Module.php, or false when there is none (or for a theme)
     * @throws Exception When a module's namespace is not its blueprint name
     */
    private function folderName(string $kind, string $name, ResolvedAddon $resolved, string|false $moduleSrc): string
    {
        if ($kind === SourceFields::MODULE) {
            $namespace = $moduleSrc !== false ? self::moduleNamespace($moduleSrc) : null;
            if ($namespace === null) {
                throw new Exception('no namespace found in Module.php');
            }
            if (strcasecmp($namespace, $name) !== 0) {
                throw new Exception(
                    "Module.php declares namespace '{$namespace}', but the blueprint name is '{$name}'; "
                    . "the name must be the module's folder name"
                );
            }
            return $namespace;
        }
        // a catalog theme lives in the catalog's folder; a sourced theme in the blueprint name
        return $resolved->dirname ?? $name;
    }

    /** Why the add-on does not fit the target Omeka S version, or null when it does (or has no constraint). */
    private static function incompatibility(string $constraint, string $omekaVersion): ?string
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return null;
        }
        try {
            $compatible = Semver::satisfies($omekaVersion, $constraint);
        } catch (Throwable) {
            return "has an unreadable Omeka S version constraint '{$constraint}'";
        }
        return $compatible ? null : "requires Omeka S {$constraint}, but the bundle targets {$omekaVersion}";
    }

    private static function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
