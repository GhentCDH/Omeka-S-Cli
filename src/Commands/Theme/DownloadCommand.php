<?php
namespace OSC\Commands\Theme;

use Exception;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\FileUtils;
use OSC\Repository\Theme\OmekaDotOrg;

class DownloadCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:download', 'Download theme');
        $this->option('-f --force', 'Force theme overwrite', 'boolval', false);
        $this->option('-b --backup', 'Backup current theme before download (delete otherwise)', 'boolval', false);
        $this->argumentThemeId();
    }

    public function execute(?string $themeId, ?bool $force, ?bool $backup): void
    {
        try {
            $modulesPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "themes"]);
            if (!is_writable($modulesPath)) {
                throw new Exception("Themes directory is not writable. Please check permissions.");
            }

            // download from url
            if(preg_match('/^https?:\/\/.+\.zip$/', $themeId)) {
                $tmpThemePath = $this->downloadFromUrl($themeId, $force, $backup);
            } else {
                // try to find module in repositories
                $tmpThemePath = $this->downloadFromThemeRepository($themeId, $force, $backup);
            }

            // Find module folder
            $themeTempPath = FileUtils::findSubpath($tmpThemePath, 'config/theme.ini');
            if (!$themeTempPath) {
                throw new NotFoundException("No valid theme found in download folder.");
            }

            // Parse module.ini
            $themeConfigPath = implode(DIRECTORY_SEPARATOR, [$themeTempPath, "config", "theme.ini"]);
            $data = parse_ini_file($themeConfigPath, true);
            if (!$data) {
                throw new NotFoundException("No valid theme.ini found in download folder.");
            }

            $themeId = $data["info"]["name"] ?? null;
            $themeDestinationPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "themes", $themeId]);

            // Check if module is already available
            if (is_dir($themeDestinationPath)) {
                if (!$force) {
                    throw new ThemeExistsException("Theme '{$themeId}' already exists. Use the --force option to download anyway.");
                }

                // Backup or remove previous version
                if ($backup) {
                    $this->info("Backup previous version ... ");
                    $this->backupTheme($themeDestinationPath);
                } else {
                    $this->removeTheme($themeDestinationPath);
                    $this->info("Remove previous version ... ");
                }
                $this->info("done", true);
            }

            // Move to modules directory
            $this->info("Move theme to folder $themeDestinationPath ... ");
            FileUtils::moveFolder($themeTempPath, $themeDestinationPath);
            $this->info("done", true);

        } finally {
            if (isset($tmpThemePath) && is_dir($tmpThemePath)) {
                $this->info("Cleaning up {$tmpThemePath} ... ");
                FileUtils::removeFolder($tmpThemePath);
                $this->info("done", true);
            }
        }

        $this->ok("Theme '{$themeId}' successfully downloaded.", true);
    }

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }

    private function downloadFromUrl(string $url, bool $force, bool $backup): string {
        $downloader = new ZipDownloader();

        $this->info("Download {$url} ... ");
        $tmpPath = $downloader->download($url);
        $this->info("done", true);

        return $tmpPath;
    }

    private function downloadFromThemeRepository(string $themeId, bool $force, bool $backup): string {
        ["id" => $themeId, "version" => $themeVersion] = $this->parseModuleVersionString($themeId);

        $themeRepo = new OmekaDotOrg();

        $themeDetails = $themeRepo->find($themeId);
        if(!$themeDetails){
            throw new NotFoundException("Theme '{$themeId}' is not found in the official theme list.");
        }

        $themeVersionDetails = $themeDetails->getVersion($themeVersion ?? $themeDetails->getLatestVersionNumber());
        if(!$themeVersionDetails){
            throw new NotFoundException("Theme '{$themeId}' with version '{$themeVersion}' is not found in the official theme list.");
        }

//        // set moduleId to dirname
//        $themeId = $themeDetails->getDirname();
//        $themeDestinationPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "themes", $themeId]);
//
//        // check if module is already available
//        if (is_dir($themeDestinationPath) && !$force) {
//            throw new ThemeExistsException("Theme '{$themeId}' is already available. Use the --force option to download anyway.");
//        }

        // Download and unzip
        $downloader = new ZipDownloader();
        $this->info("Download {$themeVersionDetails->getDownloadUrl()} ... ");
        $tmpPath = $downloader->download($themeVersionDetails->getDownloadUrl());
        $this->info("done", true);

        return $tmpPath;
    }

    private function downloadFromGit(string $url) {

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

        FileUtils::moveFolder($path, implode(DIRECTORY_SEPARATOR, [$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

}
