<?php
namespace OSC\Commands\Theme;

use Exception;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\Path;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUriType;
use OSC\Helper\VersionCompatibility;

class DownloadCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:download', 'Download theme');
        $this->argument('<theme>', 'Theme URI (syntax: theme-id:version, zip-release-url, git-url#version|tag|branch)', null);
        $this->option('-f --force', 'Force theme overwrite', 'boolval', false);
        $this->option('-b --backup', 'Backup current theme before download (delete otherwise)', 'boolval', false);
        $this->usage(
            'theme:download freedom<eol>' .
            'theme:download freedom:1.0.7<eol>' .
            'theme:download https://github.com/omeka-s-themes/freedom/releases/download/v1.0.7/freedom-v1.0.7.zip<eol>' .
            'theme:download https://github.com/omeka-s-themes/freedom.git#v1.0.7<eol>' .
            'theme:download gh:omeka-s-themes/freedom#v1.0.7'
        );
    }

    public function execute(?string $theme, ?bool $force, ?bool $backup): void
    {
        $modulesPath = Path::createPath([$this->getOmekaPath(), "themes"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Themes directory is not writable. Please check permissions.");
        }

        $themeDirName = null;
        $downloader = null;

        // create downloader
        $themeUri = ResourceUriParser::parse($theme);
        switch ($themeUri->getType()) {
            case ResourceUriType::GitRepo:
                $downloader = new GitDownloader($themeUri->getId(), $themeUri->getVersion());
                break;
            case ResourceUriType::GitHubRepo:
                $gitUrl = "https://github.com/" . $themeUri->getId() . ".git";
                $downloader = new GitDownloader($gitUrl, $themeUri->getVersion());
                break;
            case ResourceUriType::ZipUrl:
                $downloader = new ZipDownloader($themeUri->getId());
                break;
            case ResourceUriType::IdVersion:
                $repoResult = $this->getThemeRepositoryManager()->find($themeUri->getId());
                if (!$repoResult) {
                    throw new NotFoundException("Could not find theme '{$themeUri->getId()}' in any repository.");
                }
                $themeDirName = $repoResult->getItem()->getDirname();

                $omekaVersion = $this->getOmekaVersion();

                if ($themeUri->getVersion()) {
                    $versionDetails = $repoResult->getItem()->getVersion($themeUri->getVersion());
                    if (!$versionDetails) {
                        throw new NotFoundException("Theme '{$themeDirName}' has no version '{$themeUri->getVersion()}'.");
                    }
                    if (!VersionCompatibility::isCompatible($versionDetails, $omekaVersion)) {
                        if (!$force) {
                            throw new \Exception("Cannot use theme '{$themeDirName}' version '{$themeUri->getVersion()}': incompatible with Omeka S {$omekaVersion}.");
                        }
                    }
                } else {
                    $versionDetails = VersionCompatibility::getLatestCompatible(
                        $repoResult->getItem()->getVersions(),
                        $omekaVersion
                    );
                    if (!$versionDetails) {
                        throw new NotFoundException("Theme '{$themeDirName}' has no version compatible with Omeka S {$omekaVersion}.");
                    }

                    $latestVersionNumber = $repoResult->getItem()->getLatestVersion()->getVersionNumber();
                    if ($versionDetails->getVersionNumber() !== $latestVersionNumber) {
                        $this->warn("Cannot use theme '{$themeDirName}' latest version v{$latestVersionNumber}: incompatible with Omeka S {$omekaVersion}.", true);
                    }

                    $this->info("Selected '{$themeDirName}' v{$versionDetails->getVersionNumber()} (compatible with Omeka S {$omekaVersion}).", true);
                }

                $downloader = new ZipDownloader($versionDetails->getDownloadUrl());
                break;
        }

        // check if module is already available
        if ($themeDirName) {
            $themeDestinationPath = Path::createPath([$this->getOmekaPath(), "themes", $themeDirName]);

            // Check if module is already available
            $themeExists = is_dir($themeDestinationPath);
            if ($themeExists && !$force) {
                throw new ThemeExistsException("Theme '{$themeDirName}' already exists in '{$themeDestinationPath}'. Use the --force option to download anyway.");
            }
        }

        // download theme
        try {
            $this->info("Download {$downloader->getDownloadUrl()} ... ");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->io()->eol();
        }

        try {
            // Find module folder
            $themeSourcePath = Path::findSubpath($tmpDownloadPath, 'config/theme.ini');
            if (!$themeSourcePath) {
                throw new NotFoundException("No valid theme found in download folder.");
            }

            // Parse module.ini
            $themeConfigPath = Path::createPath([$themeSourcePath, "config", "theme.ini"]);
            $themeIni = parse_ini_file($themeConfigPath, true);
            if (!$themeIni) {
                throw new NotFoundException("No valid theme.ini found in download folder.");
            }

            // Get theme destination path
            if (!$themeDirName) {
                $themeDirName = Path::createSafeName($themeIni['info']['name']);
            }
            $themeDestinationPath = Path::createPath([$this->getOmekaPath(), "themes", $themeDirName]);

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

        $this->ok("Theme '{$themeDirName}' successfully downloaded.", true);
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
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true)) {
            throw new Exception("Could not create backup directory '{$backupDir}'.");
        }

        Path::moveFolder($path, Path::createPath([$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

}
