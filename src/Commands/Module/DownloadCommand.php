<?php
namespace OSC\Commands\Module;

use Exception;
use OSC\Commands\Module\Exceptions\ModuleExistsException;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\Path;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\VersionCompatibility;
use OSC\Helper\Types\ResourceUriType;

class DownloadCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('module:download', 'Download module');
        $this->argument('<module>', 'Module URI (syntax: module-id:version, zip-release-url, git-url#version|tag|branch)', null);
        $this->option('-f --force', 'Force module download', 'boolval', false);
        $this->option('-b --backup', 'Backup current module before download (delete otherwise)', 'boolval', false);
        $this->option('-i --install', 'Install module after download', 'boolval', false);
        $this->option('-u --upgrade', 'Upgrade module after download', 'boolval', false);
        $this->usage(
            'module:download common<eol>' .
            'module:download common:3.4.71<eol>' .
            'module:download https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch/releases/download/3.4.22/AdvancedSearch-3.4.22.zip<eol>' .
            'module:download https://github.com/Daniel-KM/Omeka-S-module-AdvancedSearch.git#3.4.22<eol>' .
            'module:download gh:Daniel-KM/Omeka-S-module-AdvancedSearch#3.4.22'
        );
    }

    public function execute(?string $module, ?bool $force = false, ?bool $backup = false, ?bool $install = false, ?bool $upgrade = false): void
    {
        $modulesPath = Path::createPath([$this->getOmekaPath(), "modules"]);
        if (!is_writable($modulesPath)) {
            throw new Exception("Modules directory is not writable. Please check permissions.");
        }

        $moduleDirName = null;
        $downloader = null;

        // create downloader
        $moduleUri = ResourceUriParser::parse($module);
        switch ($moduleUri->getType()) {
            case ResourceUriType::GitRepo:
                $downloader = new GitDownloader($moduleUri->getId(), $moduleUri->getVersion());
                break;
            case ResourceUriType::GitHubRepo:
                $gitUrl = "https://github.com/" . $moduleUri->getId() . ".git";
                $downloader = new GitDownloader($gitUrl, $moduleUri->getVersion());
                break;
            case ResourceUriType::ZipUrl:
                $downloader = new ZipDownloader($moduleUri->getId());
                break;
            case ResourceUriType::IdVersion:
                // find module in repositories
                $repoResult = $this->getModuleRepositoryManager()->find($moduleUri->getId());
                if (!$repoResult) {
                    throw new NotFoundException("Could not find module '{$moduleUri->getId()}' in any repository.");
                }
                $moduleDirName = $repoResult->getItem()->getDirname();

                $omekaVersion = $this->getOmekaVersion();

                // find version (specific or latest)
                if ($moduleUri->getVersion()) {
                    $versionInfo = $repoResult->getItem()->getVersion($moduleUri->getVersion());
                    if (!$versionInfo) {
                        throw new NotFoundException("Module '{$moduleDirName}' has no version '{$moduleUri->getVersion()}'.");
                    }
                    if (!VersionCompatibility::isCompatible($versionInfo, $omekaVersion)) {
                        if (!$force) {
                            throw new \Exception("Cannot use module '{$moduleDirName}' version '{$moduleUri->getVersion()}': incompatible with Omeka S {$omekaVersion}.");
                        }
                    }
                } else {
                    // find latest version compatible with current Omeka S version
                    $versionInfo = VersionCompatibility::getLatestCompatible(
                        $repoResult->getItem()->getVersions(),
                        $omekaVersion
                    );

                    if (!$versionInfo) {
                        throw new NotFoundException("Module '{$moduleDirName}' has no version compatible with Omeka S {$omekaVersion}.");
                    }

                    $latestVersionNumber = $repoResult->getItem()->getLatestVersion()->getVersionNumber();
                    if ($versionInfo->getVersionNumber() !== $latestVersionNumber) {
                        $this->warn("Cannot use module '{$moduleDirName}' latest version v{$latestVersionNumber}: incompatible with Omeka S {$omekaVersion}.", true);
                    }

                    $this->info("Selected '{$moduleDirName}' v{$versionInfo->getVersionNumber()} (compatible with Omeka S {$omekaVersion}).", true);
                }

                // get downloader
                $downloader = new ZipDownloader($versionInfo->getDownloadUrl());
                break;
        }

        // early check if module is already available
        if ($moduleDirName) {
            $moduleDestinationPath = Path::createPath([$this->getOmekaPath(), "modules", $moduleDirName]);

            // Check if module is already available
            $moduleExists = is_dir($moduleDestinationPath);
            if ($moduleExists && !$force) {
                throw new ModuleExistsException("Module '{$moduleDirName}' already exists in '{$moduleDestinationPath}'. Use the --force option download anyway.");
            }
        }

        // download module
        try {
            $this->info("Download {$downloader->getDownloadUrl()} ... ");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->info("", true);
        }

        try {
            // Find module folder
            $moduleSourcePath = Path::findSubpath($tmpDownloadPath, 'config/module.ini');
            if (!$moduleSourcePath) {
                throw new NotFoundException("No valid module found in download folder.");
            }

            // Parse module.ini
            $moduleConfigPath = Path::createPath([$moduleSourcePath, "config", "module.ini"]);
            $moduleIni = parse_ini_file($moduleConfigPath, true);
            if (!$moduleIni) {
                throw new NotFoundException("No valid module.ini found in download folder.");
            }

            // Get module destination path
            if (!$moduleDirName) {
                // Get module dirname based on module namespace
                $moduleSrc = file_get_contents(Path::createPath([$moduleSourcePath, "Module.php"]));
                if (!$moduleSrc) {
                    throw new NotFoundException("No valid Module.php found in download folder.");
                }
                $moduleDirName = $this->getModuleNameSpace($moduleSrc);
            }
            $moduleDestinationPath = Path::createPath([$this->getOmekaPath(), "modules", $moduleDirName]);

            // Install dependencies (if any)
            if (!is_dir(Path::createPath([$moduleSourcePath, "vendor"])) &&
                file_exists(Path::createPath([$moduleSourcePath, "composer.lock"]))){
                $composerCommand = sprintf('cd %s && composer install -q --no-dev &> /dev/null', escapeshellarg($moduleSourcePath));
                exec($composerCommand, $output, $exitCode);
                $exitCode && throw new \ErrorException("Failed to install dependencies");
            }

            // Backup or remove previous version
            if (is_dir($moduleDestinationPath)) {
                if (!$force) {
                    throw new ModuleExistsException("Module '{$moduleDirName}' already exists in '{$moduleDestinationPath}'. Use the --force option download anyway.");
                }

                try {
                    if ($backup) {
                        $this->debug("Backup previous version ... ");
                        $this->backupModule($moduleDestinationPath);
                    } else {
                        $this->removeModule($moduleDestinationPath);
                        $this->debug("Remove previous version ... ");
                    }
                    $this->debug("done");
                } finally {
                    $this->debug("", true);
                }
            }

            // Move to modules directory
            try {
                $this->debug("Move module to folder $moduleDestinationPath ... ");
                Path::moveFolder($moduleSourcePath, $moduleDestinationPath);
                $this->debug("done");
            } finally {
                $this->debug("", true);
            }
        } finally {
            if (is_dir($tmpDownloadPath)) {
                $this->debug("Cleaning up {$tmpDownloadPath} ... ");
                Path::removeFolder($tmpDownloadPath);
                $this->debug("done", true);
            }
        }

        $this->ok("Module '{$moduleDirName}' successfully downloaded.", true);

        if ($install) {
            $command = $this->app()->commands()['module:install'] ?? null;
            $command && $command->execute($moduleDirName);
        }

        if ($upgrade) {
            $command = $this->app()->commands()['module:upgrade'] ?? null;
            $command && $command->execute($moduleDirName);
        }
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

        Path::moveFolder($path, Path::createPath([$backupDir, basename($path), date('d-m-Y-H-i-s')]));
    }

    private function getModuleNameSpace($src): ?string
    {
        if (preg_match('#(namespace)(\\s+)([A-Za-z0-9\\\\]+?)(\\s*);#sm', $src, $matches)) {
            return $matches[3];
        }
        return null;
    }

}
