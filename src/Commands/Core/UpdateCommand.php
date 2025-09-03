<?php
namespace OSC\Commands\Core;

use Exception;
use Omeka\Settings\Settings;
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
    }

    public function execute(?string $versionNumber, ?bool $force): void
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();

        // Get the current version from settings
        $settings = $serviceManager->get('Omeka\Settings');
        $currentVersion = $settings->get('version');

        // Get the latest version if no version number is provided
        $versionNumber = $versionNumber ?? $this->webApi->getLatestOmekaVersion();
        if (!$versionNumber) {
            throw new Exception("Unable to determine the latest Omeka S version.");
        }

        // Check version
        if ($currentVersion === $versionNumber) {
            if (!$force) {
                throw new WarningException("Omeka S core is already at version $versionNumber.");
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

        // Clean source folders
        $srcPath = FileUtils::createPath([$tmpDownloadPath, 'omeka-s']);

        FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'modules']));
        FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'files']));
        FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'themes']));
        FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'config']));
        FileUtils::removeFolder(FileUtils::createPath([$srcPath, 'logs']));

        // Clean destination folders
        FileUtils::removeFolder(FileUtils::createPath([$destPath, 'application']));
        FileUtils::removeFolder(FileUtils::createPath([$destPath, 'vendor']));

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
