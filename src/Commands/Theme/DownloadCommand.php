<?php
namespace OSC\Commands\Theme;

use Exception;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUriType;
use OSC\Helper\FileUtils;
use OSC\Repository\Theme\OmekaDotOrg;

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
        $modulesPath = FileUtils::createPath([$this->getOmekaPath(), "themes"]);
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
                $themeId = $themeUri->getId();
                $themeVersion = $themeUri->getVersion();

                // find theme in omeka.org
                $themeRepo = new OmekaDotOrg();
                $themeDetails = $themeRepo->find($themeId);
                if(!$themeDetails){
                    throw new NotFoundException("Theme '{$themeId}' is not found in the official theme list.");
                }
                $themeDirName = $themeDetails->getDirName();

                // check if version exists
                $versionDetails = $themeDetails->getVersion($themeVersion ?? $themeDetails->getLatestVersionNumber());
                if(!$versionDetails){
                    throw new NotFoundException("Theme '{$themeDirName}' with version '{$themeVersion}' is not found in the official theme list.");
                }

                $downloader = new ZipDownloader($versionDetails->getDownloadUrl());
                break;
        }

        // check if module is already available
        if ($themeDirName) {
            $themeDestinationPath = FileUtils::createPath([$this->getOmekaPath(), "themes", $themeDirName]);

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
            $themeSourcePath = FileUtils::findSubpath($tmpDownloadPath, 'config/theme.ini');
            if (!$themeSourcePath) {
                throw new NotFoundException("No valid theme found in download folder.");
            }

            // Parse module.ini
            $themeConfigPath = FileUtils::createPath([$themeSourcePath, "config", "theme.ini"]);
            $themeIni = parse_ini_file($themeConfigPath, true);
            if (!$themeIni) {
                throw new NotFoundException("No valid theme.ini found in download folder.");
            }

            // Get theme destination path
            if (!$themeDirName) {
                $themeDirName = FileUtils::createSafeName($themeIni['info']['name']);
            }
            $themeDestinationPath = FileUtils::createPath([$this->getOmekaPath(), "themes", $themeDirName]);

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
            FileUtils::moveFolder($themeSourcePath, $themeDestinationPath);
            $this->debug("done", true);

        } finally {
            if (is_dir($tmpDownloadPath)) {
                $this->debug("Cleaning up {$tmpDownloadPath} ... ");
                FileUtils::removeFolder($tmpDownloadPath);
                $this->debug("done", true);
            }
        }

        $this->ok("Theme '{$themeDirName}' successfully downloaded.", true);
    }

    private function removeTheme(string $path): void
    {
        if (empty($path) || $path == '/' || !(str_contains($path, 'themes')))
            throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');
        FileUtils::removeFolder($path);
    }

    private function backupTheme(string $path): void
    {
        $backupDir = getenv('HOME') . '/.omeka-s-cli/backups/themes';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true)) {
            throw new Exception("Could not create backup directory '{$backupDir}'.");
        }

        FileUtils::moveFolder($path, FileUtils::createPath([$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

}
