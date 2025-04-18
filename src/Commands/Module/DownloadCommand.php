<?php
namespace OSC\Commands\Module;

use Exception;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\FileUtils;

class DownloadCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:download', 'Download module');
        $this->option('-f --force', 'Force module overwrite', 'boolval', false);
        $this->option('-b --backup', 'Backup current module before download (delete otherwise)', 'boolval', false);
        $this->option('-i --install', 'Install module after download', 'boolval', false);
        $this->option('-i --upgrade', 'Upgrade module after download', 'boolval', false);
        $this->argumentModuleId();
    }

    public function execute(?string $moduleId, ?bool $force, ?bool $backup, ?bool $install, ?bool $upgrade): void
    {
        $modulesPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "modules"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Modules directory is not writable. Please check permissions.");
        }

        // download from url
        if(preg_match('/^https?:\/\/.+\.zip$/', $moduleId)) {
            $tmpModulePath = $this->downloadFromUrl($moduleId, $force, $backup);
        } else {
            // try to find module in repositories
            $tmpModulePath = $this->downloadFromModuleRepository($moduleId, $force, $backup);
        }

        // Find module folder
        $moduleTempPath = FileUtils::findSubpath($tmpModulePath, 'config/module.ini');
        if (!$moduleTempPath) {
            throw new Exception("No valid module found in download folder.");
        }

        // Parse module.ini
        $moduleConfigPath = implode(DIRECTORY_SEPARATOR, [$moduleTempPath, "config", "module.ini"]);
        $data = parse_ini_file($moduleConfigPath, true);
        if (!$data) {
            throw new Exception("No valid module.ini found in download folder.");
        }

        $moduleId = $data["info"]["name"] ?? null;
        $moduleDestinationPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "modules", $moduleId]);

        // Check if module is already available
        if (is_dir($moduleDestinationPath)) {
            if (!$force) {
                throw new Exception("Module '{$moduleId}' is already available. Use the flag --force in order to download it anyway.");
            }

            // Backup or remove previous version
            if ($backup) {
                $this->info("Backup previous version ... ");
                $this->backupModule($moduleDestinationPath);
            } else {
                $this->removeModule($moduleDestinationPath);
                $this->info("Remove previous version ... ");
            }
            $this->info("done", true);
        }

        // Move to modules directory
        $this->info("Move module to folder $moduleDestinationPath ... ");
        FileUtils::moveFolder($moduleTempPath, $moduleDestinationPath);
        $this->info("done", true);

        $this->ok("Module '{$moduleId}' successfully downloaded.", true);

        if ($install) {
            $command = $this->app()->commands()['module:install'] ?? null;
            $command && $command->execute($moduleId);
        }

        if ($upgrade) {
            $command = $this->app()->commands()['module:upgrade'] ?? null;
            $command && $command->execute($moduleId);
        }
    }

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }

    private function downloadFromUrl(string $moduleUrl, bool $force, bool $backup): string {
        $downloader = new ZipDownloader();

        $this->info("Download {$moduleUrl} ... ");
        $tmpPath = $downloader->download($moduleUrl);
        $this->info("done", true);

        return $tmpPath;
    }

    private function downloadFromModuleRepository(string $moduleId, bool $force, bool $backup): string {
        ["id" => $moduleId, "version" => $moduleVersion] = $this->parseModuleVersionString($moduleId);

        // find module in repositories
        $repoResult = $this->getModuleRepositoryManager()->find($moduleId, $moduleVersion);
        if(!$repoResult){
            throw new Exception("Could not find module '{$moduleId}' in any repository.");
        }

        // check if version exists
        if ($moduleVersion && !$repoResult->getVersionNumber()) {
            throw new Exception("Module '{$moduleId}' has no version '{$moduleVersion}'.");
        }
        $versionInfo = $repoResult->getItem()->getVersion($repoResult->getVersionNumber());

        // get dirname
        $moduleDirName = $repoResult->getItem()->getDirname();
        $moduleDestinationPath = implode(DIRECTORY_SEPARATOR, [$this->getOmekaPath(), "modules", $moduleDirName]);

        // check if module is already available
        if (is_dir($moduleDestinationPath) && !$force) {
            throw new Exception("Module '{$moduleDirName}' is already available. Use the flag --force in order to download it anyway.");
        }

        // Download and unzip
        $downloader = new ZipDownloader();
        $this->info("Download {$versionInfo->getDownloadUrl()} ... ");
        $tmpPath = $downloader->download($versionInfo->getDownloadUrl());
        $this->info("done", true);

        return $tmpPath;
    }

    private function downloadFromGit(string $url) {

    }

    private function removeModule(string $path): void
    {
        if (empty($path) || $path == '/' || !(str_contains($path, 'modules')))
            throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');
        system("rm -rf " . escapeshellarg($path));
    }

    private function backupModule(string $path): void
    {
        $backupDir = getenv('HOME') . '/.omeka-s-cli/backups/modules';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true)) {
            throw new Exception("Could not create backup directory '{$backupDir}'.");
        }

        FileUtils::moveFolder($path, implode(DIRECTORY_SEPARATOR, [$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

}
