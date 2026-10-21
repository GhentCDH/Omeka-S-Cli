<?php
namespace OSC\Commands\Core;

use Exception;
use OSC\Commands\Module\AbstractModuleCommand;
use OSC\Commands\Module\FormattersTrait;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Omeka\OmekaDotOrgApi;

class DownloadCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('core:download', 'Download Omeka S core');
        $this->argument('<destination-path>', 'Destination path');
        $this->argument('[version-number]', 'Core version number (latest if not specified)');
        $this->option('-c --create-destination', 'Create destination directory', 'boolval', false);
        $this->option('-f --force', 'Download even if the destination is not empty (and not an Omeka S installation)', 'boolval', false);
        $this->option('--source', 'Install from this Omeka S release ZIP (a path or URL) instead of downloading version-number');
        $this->usage(
            'core:download /var/www/omeka-s<eol/>'
            . 'core:download /var/www/omeka-s 4.1.1<eol/>'
            . 'core:download /var/www/omeka-s --source ./omeka-s-4.1.1.zip'
        );
    }

    public function execute(?string $versionNumber, string $destinationPath, ?bool $createDestination, ?bool $force = false, ?string $source = null): void
    {
        // convert destinatino path to absolute path
        $destinationPath = realpath($destinationPath) ?: $destinationPath;

        // check if destination path exists
        if (!is_dir($destinationPath) || !is_writable($destinationPath)) {
            if (!$createDestination) {
                throw new Exception("The destination directory '{$destinationPath}' does not exist or is not writable.");
            } else {
                mkdir($destinationPath, 0755, true);
            }
        }

        // check if destination path is an omeka path
        if ($this->isOmekaDir(($destinationPath))) {
            throw new Exception("The destination directory '{$destinationPath}' contains an Omeka S installation. Please use the 'core:update' command instead.");
        }

        // refuse to extract on top of an unrelated, non-empty directory (e.g. the wrong folder):
        // Omeka's files would overwrite whatever is there. --force overrides.
        if (!$force && !Path::isEmptyDir($destinationPath)) {
            throw new Exception(
                "The destination directory '{$destinationPath}' is not empty and is not an Omeka S installation. "
                . 'Choose an empty directory, or use --force to download here anyway.'
            );
        }

        if ($source !== null && $source !== '') {
            // a release ZIP given by the caller (a local file or a URL): nothing to look up
            $url = $source;
            $downloader = new ZipDownloader($source);
            $label = $versionNumber ? "version {$versionNumber}" : basename($source);
        } else {
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

            if ($versionNumber === "dev") {
                $url = "https://github.com/omeka/omeka-s.git";
                $downloader = new GitDownloader($url);
            } else {
                $url = OmekaDotOrgApi::coreReleaseUrl($versionNumber);
                $downloader = new ZipDownloader($url);
            }
            $label = "version {$versionNumber}";
        }

        try {
            $this->info("Downloading Omeka S core {$label} from {$url} ... ");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->io()->eol();
        }

        try {
            $this->info("Verify core files ... ");
            $srcPath = Path::findSubpath($tmpDownloadPath, 'application');
            if (!$srcPath) {
                throw new Exception("The downloaded core files are invalid.");
            }
            $this->info("done");
        } finally {
            $this->io()->eol();
        }

        // Copy folders
        try {
            $this->info("Copying files ...");
            Path::copyFolder($srcPath, $destinationPath);
            $this->info("done");
        } finally {
            $this->info("", true);
        }

        // Clean up temporary files
        $this->debug("Cleaning up {$tmpDownloadPath} ... ");
        Path::removeFolder($tmpDownloadPath);
        $this->debug("done", true);

        $this->ok("Omeka S core {$label} downloaded to {$destinationPath}.", true);
    }
}
