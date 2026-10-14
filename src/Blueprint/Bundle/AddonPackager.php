<?php
namespace OSC\Blueprint\Bundle;

use Closure;
use Composer\Semver\Semver;
use Exception;
use FilesystemIterator;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\ResolvedAddon;
use OSC\Helper\Path;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Turn a resolved module or theme into a bundle-ready ZIP. Each add-on is:
 * - fetched;
 * - checked against the target Omeka S version;
 * - given its Composer dependencies when it ships a lock file without vendor/ (so a deploy never
 *   needs Composer or the network);
 * - repacked as `<modules|themes>/<Name>-<version>.zip`, with a single `<Name>/` folder and no .git.
 *
 * <Name> is the add-on's folder name:
 * - a module's namespace (Omeka requires it to be the folder);
 * - a catalog theme's dirname;
 * - else the blueprint name.
 */
class AddonPackager
{
    /** @var Closure(string): void */
    private Closure $composerInstall;

    /** @param (Closure(string): void)|null $composerInstall Installs the dependencies of the package in a directory */
    public function __construct(?Closure $composerInstall = null)
    {
        $this->composerInstall = $composerInstall ?? self::runComposerInstall(...);
    }

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
        $iniName = $kind === SourceFields::MODULE ? 'module.ini' : 'theme.ini';
        $download = $resolved->downloader->download();
        try {
            $root = Path::findSubpath($download, "config/{$iniName}");
            if ($root === null) {
                throw new Exception("no config/{$iniName} found in {$resolved->downloader->getDownloadUrl()}");
            }
            $ini = @parse_ini_file("{$root}/config/{$iniName}", true);
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

            $folder = $this->folderName($kind, $name, $resolved, $root);
            if ($folder !== $name) {
                $warnings[] = "renamed to '{$folder}', its folder name";
            }

            if (!is_dir("{$root}/vendor") && is_file("{$root}/composer.lock")) {
                ($this->composerInstall)($root);
            }

            $zipPath = ($kind === SourceFields::MODULE ? 'modules' : 'themes')
                . "/{$folder}-" . preg_replace('/[^A-Za-z0-9._-]/', '_', $version) . '.zip';
            $zipDir = dirname("{$bundleDir}/{$zipPath}");
            if (!is_dir($zipDir) && !mkdir($zipDir, 0777, true) && !is_dir($zipDir)) {
                throw new Exception("could not create {$zipDir}");
            }
            self::zipFolder($root, "{$bundleDir}/{$zipPath}", $folder);

            return new PackagedAddon($folder, $version, $zipPath, $warnings);
        } finally {
            if (is_string($download) && is_dir($download)) {
                Path::removeFolder($download);
            }
        }
    }

    /** The namespace a module's Module.php declares (Omeka requires it to be the module's folder). */
    public static function moduleNamespace(string $src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }

    /** @throws Exception When a module's namespace is not its blueprint name */
    private function folderName(string $kind, string $name, ResolvedAddon $resolved, string $root): string
    {
        if ($kind === SourceFields::MODULE) {
            $src = @file_get_contents("{$root}/Module.php");
            $namespace = $src !== false ? self::moduleNamespace($src) : null;
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

    /** Zip $dir (without its .git folder) into $zipPath, under a single top-level folder $prefix. */
    private static function zipFolder(string $dir, string $zipPath, string $prefix): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("could not create {$zipPath}");
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($files as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($relative === '.git' || str_starts_with($relative, '.git/')) {
                continue;
            }
            $entry = "{$prefix}/{$relative}";
            $file->isDir() ? $zip->addEmptyDir($entry) : $zip->addFile($file->getPathname(), $entry);
        }
        if (!$zip->close()) {
            throw new Exception("could not write {$zipPath}");
        }
    }

    /** @throws Exception When Composer is missing or fails */
    private static function runComposerInstall(string $dir): void
    {
        exec('command -v composer 2>/dev/null', $output, $code);
        if ($code !== 0) {
            throw new Exception(
                "it ships composer.lock without vendor/, so its dependencies must be installed, but 'composer' is not on the PATH"
            );
        }
        $output = [];
        exec(sprintf('cd %s && composer install --no-dev --no-interaction --quiet 2>&1', escapeshellarg($dir)), $output, $code);
        if ($code !== 0) {
            throw new Exception('composer install failed: ' . implode("\n", $output));
        }
    }
}
