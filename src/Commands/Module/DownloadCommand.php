<?php
namespace OSC\Commands\Module;

use Exception;
use OSC\Commands\Module\Exceptions\ModuleExistsException;
use OSC\Commands\Module\Types\DownloadInfo;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\FileUtils;
use OSC\Helper\ArgumentParser;
use OSC\Helper\ArgumentType;

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
        $modulesPath = FileUtils::createPath([$this->getOmekaPath(), "modules"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Modules directory is not writable. Please check permissions.");
        }

        $moduleDownloadUrl = null;
        $moduleDirName = null;
        $downloader = null;

        $downloadType = ArgumentParser::getArgumentType($moduleId);

        // download from url
        switch ($downloadType) {
            case ArgumentType::GitRepo:
                $moduleDownloadUrl = $moduleId;
                $downloader = new GitDownloader($moduleDownloadUrl);
                break;
            case ArgumentType::ZipUrl:
                $moduleDownloadUrl = $moduleId;
                $downloader = new ZipDownloader($moduleDownloadUrl);
                break;
            case ArgumentType::IdVersion:
                ["id" => $moduleId, "version" => $moduleVersion] = $this->parseModuleVersionString($moduleId);

                // find module in repositories
                $repoResult = $this->getModuleRepositoryManager()->find($moduleId, $moduleVersion);
                if (!$repoResult) {
                    throw new NotFoundException("Could not find module '{$moduleId}' in any repository.");
                }
                $moduleDirName = $repoResult->getItem()->getDirname();

                // check if version exists
                if ($moduleVersion && !$repoResult->getVersionNumber()) {
                    throw new NotFoundException("Module '{$moduleDirName}' has no version '{$moduleVersion}'.");
                }

                // get download url
                $versionInfo = $repoResult->getItem()->getVersion($repoResult->getVersionNumber());
                $moduleDownloadUrl = $versionInfo->getDownloadUrl();

                $downloader = new ZipDownloader($moduleDownloadUrl);
                break;
        }

        // check if module is already available
        if ($moduleDirName) {
            $moduleDestinationPath = FileUtils::createPath([$this->getOmekaPath(), "modules", $moduleDirName]);

            // Check if module is already available
            $moduleExists = is_dir($moduleDestinationPath);
            if ($moduleExists && !$force) {
                throw new ModuleExistsException("Module '{$moduleDirName}' already exists in '{$moduleDestinationPath}'. Use the --force option download anyway.");
            }
        }

        // download module
        try {
            try {
                $this->info("Download {$moduleDownloadUrl} ... ");
                $tmpDownloadPath = $downloader->download();
                $this->info("done");
            } finally {
                $this->io()->eol();
            }

            // Find module folder
            $moduleSourcePath = FileUtils::findSubpath($tmpDownloadPath, 'config/module.ini');
            if (!$moduleSourcePath) {
                throw new NotFoundException("No valid module found in download folder.");
            }

            // Parse module.ini
            $moduleConfigPath = FileUtils::createPath([$moduleSourcePath, "config", "module.ini"]);
            $moduleIni = parse_ini_file($moduleConfigPath, true);
            if (!$moduleIni) {
                throw new NotFoundException("No valid module.ini found in download folder.");
            }

            // Get module destination path
            if (!$moduleDirName) {
                // Get module dirname based on module namespace
                $moduleSrc = file_get_contents(FileUtils::createPath([$moduleSourcePath, "Module.php"]));
                if (!$moduleSrc) {
                    throw new NotFoundException("No valid Module.php found in download folder.");
                }
                $moduleDirName = $this->getModuleNameSpace($moduleSrc);
            }
            $moduleDestinationPath = FileUtils::createPath([$this->getOmekaPath(), "modules", $moduleDirName]);

            // Backup or remove previous version
            if (is_dir($moduleDestinationPath)) {
                if (!$force) {
                    throw new ModuleExistsException("Module '{$moduleDirName}' already exists in '{$moduleDestinationPath}'. Use the --force option download anyway.");
                }

                try {
                    if ($backup) {
                        $this->info("Backup previous version ... ");
                        $this->backupModule($moduleDestinationPath);
                    } else {
                        $this->removeModule($moduleDestinationPath);
                        $this->info("Remove previous version ... ");
                    }
                    $this->info("done");
                } finally {
                    $this->io()->eol();
                }
            }

            // Move to modules directory
            try {
                $this->info("Move module to folder $moduleDestinationPath ... ");
                FileUtils::moveFolder($moduleSourcePath, $moduleDestinationPath);
                $this->info("done");
            } finally {
                $this->io()->eol();
            }

            // Return module info
            $downloadInfo = new DownloadInfo(
                $moduleDirName,
                $moduleIni['info']['name'],
                $moduleIni['info']['description'] ?? null,
                $moduleIni['info']['version'],
                explode(',', $moduleIni['info']['dependencies'] ?? ''),
                $moduleIni['info']['omeka_version_constraint'] ?? null,
            );
        } finally {
            if (isset($tmpDownloadPath) && is_dir($tmpDownloadPath)) {
                $this->info("Cleaning up {$tmpDownloadPath} ... ");
                FileUtils::removeFolder($tmpDownloadPath);
                $this->info("done", true);
            }
        }

        $this->ok("Module '{$downloadInfo->getDirname()}' successfully downloaded.", true);

        if ($install) {
            $command = $this->app()->commands()['module:install'] ?? null;
            $command && $command->execute($downloadInfo->getDirname());
        }

        if ($upgrade) {
            $command = $this->app()->commands()['module:upgrade'] ?? null;
            $command && $command->execute($downloadInfo->getDirname());
        }
    }

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
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

        FileUtils::moveFolder($path, FileUtils::createPath([$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

    private function getModuleNameSpace($src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }

}
