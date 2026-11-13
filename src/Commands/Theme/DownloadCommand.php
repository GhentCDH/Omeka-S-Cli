<?php
namespace OSC\Commands\Theme;

use Exception;
use OSC\Addon\AddonIni;
use OSC\Addon\AddonType;
use OSC\Addon\InstalledAddons;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\AddonResolver;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\Offline;
use OSC\Helper\Path;

class DownloadCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:download', 'Download theme');
        $this->argument('<theme>', 'Theme URI (syntax: theme-id:version, zip-release-url, zip-file, git-url#version|tag|branch)', null);
        $this->option('-f --force', 'Force theme overwrite', 'boolval', false);
        $this->option('-b --backup', 'Backup current theme before download (delete otherwise)', 'boolval', false);
        $this->option('--dir', 'Folder (the theme ID) to install into (default: the folder the repository gives, or the theme.ini name for a zip or git source)');
        $this->usage(
            'theme:download freedom<eol>' .
            'theme:download freedom:1.0.7<eol>' .
            'theme:download https://github.com/omeka-s-themes/freedom/releases/download/v1.0.7/freedom-v1.0.7.zip<eol>' .
            'theme:download ./freedom-v1.0.7.zip<eol>' .
            'theme:download https://github.com/omeka-s-themes/freedom.git#v1.0.7<eol>' .
            'theme:download gh:omeka-s-themes/freedom#v1.0.7 --dir freedom<eol>' .
            'theme:download gh:omeka-s-themes/freedom#v1.0.7'
        );
    }

    public function execute(?string $theme, ?bool $force, ?bool $backup, ?string $dir = null): void
    {
        $modulesPath = Path::createPath([$this->getOmekaPath(), "themes"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Themes directory is not writable. Please check permissions.");
        }
        $installed = new InstalledAddons($this->getOmekaPath());

        // offline, a repository name cannot be looked up; a theme already on disk (in --dir, if given)
        // needs no lookup and gets the same "already exists" warning as online
        if (!$force && Offline::isEnabled()) {
            // --dir is an exact folder name; a repository name matches case-insensitively, as the repository does
            $existing = ($dir !== null && $dir !== '')
                ? ($installed->exists(AddonType::Theme, $dir) ? $dir : null)
                : $installed->find(AddonType::Theme, AddonResolver::addonId((string) $theme) ?? '');
            if ($existing) {
                throw new ThemeExistsException("Theme '{$existing}' already exists in '{$installed->path(AddonType::Theme, $existing)}'. Use the --force option to download anyway.");
            }
        }

        $resolved = (new AddonResolver($this->getThemeRepositoryManager(), AddonType::Theme))
            ->resolve($theme, fn() => $this->getOmekaVersion(), (bool) $force);
        foreach ($resolved->warnings as $warning) {
            $this->warn($warning, true);
        }
        foreach ($resolved->notes as $note) {
            $this->info($note, true);
        }
        $themeDirName = $resolved->dirname;
        $downloader = $resolved->downloader;
        if ($dir !== null && $dir !== '') {
            if (!Path::isSafeFolderName($dir)) {
                throw new Exception("Invalid --dir '{$dir}': use a plain folder name (letters, digits, '_', '-', '.').");
            }
            $themeDirName = $dir;
        }

        // check if module is already available
        if ($themeDirName) {
            $themeDestinationPath = $installed->path(AddonType::Theme, $themeDirName);

            // Check if theme is already available
            if ($installed->exists(AddonType::Theme, $themeDirName) && !$force) {
                throw new ThemeExistsException("Theme '{$themeDirName}' already exists in '{$themeDestinationPath}'. Use the --force option to download anyway.");
            }
        }

        // download theme
        try {
            $this->info("Download {$downloader->getDownloadUrl()} ... ");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->info("", true);
        }

        try {
            // Find theme folder
            $themeSourcePath = Path::findSubpath($tmpDownloadPath, 'config/theme.ini');
            if (!$themeSourcePath) {
                throw new NotFoundException("No valid theme found in download folder.");
            }
            $themeIni = AddonIni::fromAddonDir($themeSourcePath, AddonType::Theme);

            // Get theme destination path
            if (!$themeDirName) {
                $themeDirName = Path::createSafeName((string) $themeIni->name());
            }
            $themeDestinationPath = $installed->path(AddonType::Theme, $themeDirName);

            // Check if theme is already available
            if (is_dir($themeDestinationPath)) {
                if (!$force) {
                    throw new ThemeExistsException("Theme '{$themeDirName}' already exists in '{$themeDestinationPath}'. Use the --force option to download anyway.");
                }

                // Backup or remove previous version
                try {
                    if ($backup) {
                        $this->debug("Backup previous version ... ");
                        $this->backupTheme($themeDestinationPath);
                    } else {
                        $this->removeTheme($themeDestinationPath);
                        $this->debug("Remove previous version ... ");
                    }
                    $this->debug("done");
                } finally {
                    $this->debug("", true);
                }
            }

            // Move to themes directory
            $this->debug("Move theme to folder $themeDestinationPath ... ");
            Path::moveFolder($themeSourcePath, $themeDestinationPath);
            $this->debug("done", true);

        } finally {
            if (is_dir($tmpDownloadPath)) {
                $this->debug("Cleaning up {$tmpDownloadPath} ... ");
                Path::removeFolder($tmpDownloadPath);
                $this->debug("done", true);
            }
        }

        $version = $themeIni->version();
        $this->ok("Theme '{$themeDirName}'" . ($version ? " v{$version}" : '') . " downloaded.", true);
    }

    private function removeTheme(string $path): void
    {
        if (empty($path) || $path == '/' || !(str_contains($path, 'themes')))
            throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');
        Path::removeFolder($path);
    }

    private function backupTheme(string $path): void
    {
        $backupDir = getenv('HOME') . '/.omeka-s-cli/backups/themes';
        Path::ensureDir($backupDir, 0777, "Could not create backup directory '{$backupDir}'");

        Path::moveFolder($path, Path::createPath([$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

}
