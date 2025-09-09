<?php
namespace OSC\Commands\Core;

use Exception;
use OSC\Commands\Module\AbstractModuleCommand;
use OSC\Commands\Module\FormattersTrait;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\FileUtils;

class DownloadCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('core:download', 'Download Omeka S core');
        $this->argument('<destination-path>', 'Destination path');
        $this->argument('[version-number]', 'Core version number (latest if not specified)');
        $this->option('-c --create-destination', 'Create destination directory', 'boolval', false);
    }

    public function execute(?string $versionNumber, string $destinationPath, ?bool $createDestination): void
    {
        // convert destinatino path to absolute path
        $destinationPath = realpath($destinationPath) ?: $destinationPath;

        // check if destination path exists
        if (!is_dir($destinationPath) || !is_writable($destinationPath)) {
            if (!$createDestination) {
                throw new Exception("The destination directory '{$destinationPath}' does not exist or is not writable.");
            } else {
                mkdir($destinationPath, 755, true);
            }
        }

        // check if destination path is an omeka path
        if ($this->isOmekaDir(($destinationPath))) {
            throw new Exception("The destination directory '{$destinationPath}' contains an Omeka S installation. Please use the 'core:update' command instead.");
        }

        // Get the latest version if no version number is provided
        if (!$versionNumber) {
            try {
                $this->debug("No version number supplied, getting latest core version number ... ");
                $versionNumber = $this->webApi->getLatestOmekaVersion();
                if (!$versionNumber) {
                    throw new Exception("Unable to determine the latest Omeka S version.");
                }
                $this->debug('done');
            } finally {
                $this->debug("", true);
            }
        }

        // Download the specified version
        $url = "https://github.com/omeka/omeka-s/releases/download/v$versionNumber/omeka-s-$versionNumber.zip";
        $downloader = new ZipDownloader($url);

        try {
            $this->info("Downloading Omeka S core version $versionNumber from $url ... ");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->io()->eol();
        }

        // Copy folders
        try {
            $this->info("Copying files ...");
            $srcPath = FileUtils::createPath([$tmpDownloadPath, 'omeka-s']);
            FileUtils::copyFolder($srcPath, $destinationPath);
            $this->info("done");
        } finally {
            $this->info("", true);
        }

        // Clean up temporary files
        $this->debug("Cleaning up {$tmpDownloadPath} ... ");
        FileUtils::removeFolder($tmpDownloadPath);
        $this->debug("done", true);

        $this->ok("Omeka S core version $versionNumber successfully downloaded to {$destinationPath}", true);
    }
}
