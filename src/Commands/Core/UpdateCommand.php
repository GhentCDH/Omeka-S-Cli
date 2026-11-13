<?php
namespace OSC\Commands\Core;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Core\CoreSwap;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\WarningException;
use OSC\Helper\Offline;
use OSC\Helper\OmekaVersion;
use OSC\Helper\Path;
use OSC\Omeka\OmekaDotOrgApi;
use RuntimeException;

class UpdateCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('core:update', 'Update Omeka S core');
        $this->argument('[version-number]', 'Core version number');
        $this->option('-f --force', 'Force download', 'boolval', false);
        $this->option('-s --skip-version-check', 'Skip installed version check', 'boolval', false);
        $this->option('-b --backup', 'Keep the replaced core files in ~/.omeka-s-cli/backups/core/<version>/<date-time>/ (deleted otherwise)', 'boolval', false);
        $this->option('--stage-dir', 'Directory to stage the new core files in, outside every web root and on the same filesystem as Omeka S (default: next to the Omeka S directory, or inside it)');
    }

    public function execute(?string $versionNumber, ?bool $force, ?bool $skipVersionCheck, ?bool $backup = false, ?string $stageDir = null): void
    {
        // Get the latest version if no version number is provided
        if (!$versionNumber) {
            Offline::assertOnline('look up the latest Omeka S version', 'pass a version number instead');
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
        if ($versionNumber === 'dev') {
            $skipVersionCheck = true;
        }
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
        // checked before the download
        if ($stageDir !== null && $stageDir !== '') {
            $stageDir = Path::toAbsolutePath($stageDir, $this->getCwd());
            CoreSwap::assertStageDir($stageDir, $destPath);
        } else {
            $stageDir = null;
        }

        // Download the specified version
        if ($versionNumber === "dev") {
            $url = "https://github.com/omeka/omeka-s.git";
            $downloader = new GitDownloader($url);
        } else {
            $url = OmekaDotOrgApi::coreReleaseUrl($versionNumber);
            $downloader = new ZipDownloader($url);
        }

        try {
            $this->info("Downloading Omeka S core version '$versionNumber' from $url ...");
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

        // the new files are staged first and swapped in with renames: a failure leaves the previous
        // core in place (or names what could not be restored)
        $oldVersion = OmekaVersion::getVersion($destPath) ?? 'unknown';
        $swap = new CoreSwap($destPath);
        try {
            try {
                $this->info("Stage new core files ... ");
                $swap->stage($srcPath, $stageDir);
                $this->info("done in {$swap->stagePath()}");
            } finally {
                $this->io()->eol();
            }

            try {
                $this->info("Replace core files ... ");
                $swap->swap();
                $this->info("done");
            } finally {
                $this->io()->eol();
            }

            // the core is replaced from here on: a failure below is a warning, and CoreSwap then keeps the
            // previous files in the stage
            try {
                $savedHtaccess = $swap->saveChangedHtaccess();
                if ($savedHtaccess !== null) {
                    $this->warn("The release replaced your customised .htaccess; the previous one is saved as '{$savedHtaccess}'. Merge your changes back if needed.", true);
                }
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage(), true);
            }

            if ($backup) {
                $backupDir = Path::createPath([getenv('HOME') . '/.omeka-s-cli/backups/core', $oldVersion, date('d-m-Y-H-i-s')]);
                try {
                    $swap->keepBackup($backupDir);
                    $this->info("Previous core kept in '{$backupDir}'.", true);
                } catch (RuntimeException $e) {
                    $this->warn($e->getMessage(), true);
                }
            }
        } finally {
            $warning = $swap->cleanup();
            if ($warning !== null) {
                $this->warn($warning, true);
            }
            // Clean up temporary files
            $this->info("Cleaning up {$tmpDownloadPath} ... ");
            Path::removeFolder($tmpDownloadPath);
            $this->info("done", true);
        }

        $this->ok("Omeka S core updated to version {$versionNumber}.", true);
    }
}
