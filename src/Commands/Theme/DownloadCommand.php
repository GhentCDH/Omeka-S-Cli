<?php
namespace OSC\Commands\Theme;

use Exception;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Commands\Theme\Types\DownloadInfo;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\ArgumentParser;
use OSC\Helper\ArgumentType;
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
        $modulesPath = FileUtils::createPath([$this->getOmekaPath(), "themes"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Themes directory is not writable. Please check permissions.");
        }

        $themeDownloadUrl = null;
        $themeDirName = null;
        $downloader = null;

        $downloadType = ArgumentParser::getArgumentType($themeId);

        switch ($downloadType) {
            case ArgumentType::GitRepo:
                $themeDownloadUrl = $themeId;
                $downloader = new GitDownloader($themeDownloadUrl);
                break;
            case ArgumentType::ZipUrl:
                $themeDownloadUrl = $themeId;
                $downloader = new ZipDownloader($themeDownloadUrl);
                break;
            case ArgumentType::IdVersion:
                ["id" => $themeId, "version" => $themeVersion] = $this->parseModuleVersionString($themeId);

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
                $themeDownloadUrl = $versionDetails->getDownloadUrl();

                $downloader = new ZipDownloader($themeDownloadUrl);
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
            try {
                $this->info("Download {$themeDownloadUrl} ... ");
                $tmpDownloadPath = $downloader->download();
                $this->info("done");
            } finally {
                $this->io()->eol();
            }

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
                        $this->info("Backup previous version ... ");
                        $this->backupTheme($themeDestinationPath);
                    } else {
                        $this->removeTheme($themeDestinationPath);
                        $this->info("Remove previous version ... ");
                    }
                    $this->info("done");
                } finally {
                    $this->io()->eol();
                }
            }

            // Move to themes directory
            $this->info("Move theme to folder $themeDestinationPath ... ");
            FileUtils::moveFolder($themeSourcePath, $themeDestinationPath);
            $this->info("done", true);

            // Return module info
            $downloadInfo = new DownloadInfo(
                $themeDirName,
                $themeIni['info']['name'],
                $themeIni['info']['description'] ?? null,
                $themeIni['info']['version'],
                $themeIni['info']['omeka_version_constraint'] ?? null,
            );
        } finally {
            if (isset($tmpDownloadPath) && is_dir($tmpDownloadPath)) {
                $this->info("Cleaning up {$tmpDownloadPath} ... ");
                FileUtils::removeFolder($tmpDownloadPath);
                $this->info("done", true);
            }
        }

        $this->ok("Theme '{$downloadInfo->getDirname()}' successfully downloaded.", true);
    }

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }

    private function downloadFromZipRelease(string $themeUrl, ?string $themeDirName, bool $force, bool $backup): DownloadInfo {


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
