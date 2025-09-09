<?php
namespace OSC\Commands\Core;

use Exception;
use OSC\Commands\Module\AbstractModuleCommand;
use OSC\Commands\Module\FormattersTrait;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\WarningException;
use OSC\Helper\FileUtils;

class UpdateCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('core:update', 'Update Omeka S core');
        $this->argument('[version-number]', 'Core version number');
        $this->option('-f --force', 'Force download', 'boolval', false);
        $this->option('-s --skip-version-check', 'Skip installed version check', 'boolval', false);
    }

    public function execute(?string $versionNumber, ?bool $force, ?bool $skipVersionCheck): void
    {
        // Get the latest version if no version number is provided
        if (!$versionNumber) {
            try {
                $this->info("No version number supplied, getting latest core version number ... ");
                $versionNumber = $this->webApi->getLatestOmekaVersion();
                if (!$versionNumber) {
                    throw new Exception("Unable to determine the latest Omeka S version.");
                }
                $this->info('done');
            } finally {
                $this->io()->eol();
            }
        }

        // Check version
        if (!$skipVersionCheck) {
            $serviceManager = $this->getOmekaInstance(false)->getServiceManager();

            // Get the current version from settings
            $settings = $serviceManager->get('Omeka\Settings');
            $currentVersion = $settings->get('version');

            if ($currentVersion === $versionNumber) {
                if (!$force) {
                    throw new WarningException("Omeka S core is already at version $versionNumber.");
                }
            }
        }


        // check if destination path exists and is writable
        $destPath = $this->getOmekaPath();
        if (!is_dir($destPath) || !is_writable($destPath)) {
            throw new Exception("The destination path '{$destPath}' does not exist or is not writable.");
        }

        // Download the specified version
        $url = "https://github.com/omeka/omeka-s/releases/download/v$versionNumber/omeka-s-$versionNumber.zip";
        $downloader = new ZipDownloader($url);

        try {
            $this->info("Downloading Omeka S core version $versionNumber from $url ...");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->io()->eol();
        }

        try {
            // Clean source folders
            $this->info("Clean downloaded core files ... ");
            $srcPath = FileUtils::createPath([$tmpDownloadPath, 'omeka-s']);

            FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'modules']));
            FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'files']));
            FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'themes']));
            FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'config']));
            FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'logs']));

            // Clean destination folders
            FileUtils::removeFolder(FileUtils::createPath([$destPath, 'application']));
            FileUtils::removeFolder(FileUtils::createPath([$destPath, 'vendor']));
            $this->info('done', true);

            // Install the new Omeka S core files
            try {
                $this->info("Install new core files ...");
                FileUtils::copyFolder($srcPath, $destPath);
                $this->info("done");
            } finally {
                $this->io()->eol();
            }
        } finally {
            // Clean up temporary files
            $this->info("Cleaning up {$tmpDownloadPath} ... ");
            FileUtils::removeFolder($tmpDownloadPath);
            $this->info("done", true);
        }
    }
}
