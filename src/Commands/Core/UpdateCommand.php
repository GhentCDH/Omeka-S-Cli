<?php
namespace OSC\Commands\Core;

use OSC\Source\HttpUrl;
use OSC\Source\GitRepository;
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
use Throwable;

class UpdateCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('core:update', 'Update Omeka S core');
        $this->argument('[version-number]', 'Core version number');
        $this->option('-f --force', 'Force download', 'boolval', false);
        $this->option('-s --skip-version-check', 'Skip installed version check', 'boolval', false);
        $this->option('--backup', 'Keep the replaced core files in ~/.omeka-s-cli/backups/core/<version>/<date-time>/ (deleted otherwise)', 'boolval', false);
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
                $this->endLine();
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
        foreach (CoreSwap::findLeftovers($destPath, $stageDir) as $leftover) {
            $this->reportLeftover("A staging directory of an earlier core:update was left at '{$leftover}'. Check its old/ files (they can be the only copy of a previous core), then remove it.");
        }

        // Download the specified version
        if ($versionNumber === "dev") {
            $url = "https://github.com/omeka/omeka-s.git";
            $downloader = new GitDownloader(GitRepository::parse($url));
        } else {
            $url = OmekaDotOrgApi::coreReleaseUrl($versionNumber);
            $downloader = new ZipDownloader(HttpUrl::fromUrl($url));
        }

        try {
            $this->info("Downloading Omeka S core version '$versionNumber' from $url ...");
            $tmpDownloadPath = $downloader->download();
            $this->info("done");
        } finally {
            $this->endLine();
        }

        // everything after the download is under one try, so the download is always removed
        $swap = null;
        $updated = false;
        try {
            try {
                $this->info("Verify core files ... ");
                $srcPath = Path::findSubpath($tmpDownloadPath, 'application');
                if (!$srcPath) {
                    throw new Exception("The downloaded core files are invalid.");
                }
                $this->info("done");
            } finally {
                $this->endLine();
            }

            // the new files are staged first and swapped in with renames: a failure leaves the previous
            // core in place (or names what could not be restored)
            $oldVersion = OmekaVersion::getVersion($destPath) ?? 'unknown';
            $swap = new CoreSwap($destPath);
            try {
                $this->info("Stage new core files ... ");
                $swap->stage($srcPath, $stageDir);
                $this->info("done in {$swap->stagePath()}");
            } finally {
                $this->endLine();
            }

            try {
                $this->info("Replace core files ... ");
                $swap->swap();
                $updated = true;
                $this->info("done");
            } finally {
                $this->endLine();
            }

            // the core is replaced from here on: a failure below is a warning, and CoreSwap then keeps the
            // previous files in the stage
            try {
                $savedHtaccess = $swap->saveChangedHtaccess();
                if ($savedHtaccess !== null) {
                    $this->warn("The release replaced your customised .htaccess; the previous one is saved as '{$savedHtaccess}'. Merge your changes back if needed.", true);
                }
            } catch (RuntimeException $e) {
                $this->reportLeftover($e->getMessage());
            }

            if ($backup) {
                $backupDir = Path::createPath([getenv('HOME') . '/.omeka-s-cli/backups/core', $oldVersion, date('d-m-Y-H-i-s')]);
                try {
                    $swap->keepBackup($backupDir);
                    $this->info("Previous core kept in '{$backupDir}'.", true);
                } catch (RuntimeException $e) {
                    $this->reportLeftover($e->getMessage());
                }
            }
        } finally {
            $warning = $swap?->cleanup();
            if ($warning !== null) {
                $this->reportLeftover($updated
                    ? "Omeka S core updated to {$versionNumber}, but a staging directory was left behind. {$warning}"
                    : $warning);
            }
            // a failure here must not hide the error that is propagating (it may say where the previous files are)
            try {
                $this->info("Cleaning up {$tmpDownloadPath} ... ");
                Path::removeFolder($tmpDownloadPath);
                $this->info("done", true);
            } catch (Throwable $e) {
                $this->endLine();
                $this->warn("Could not remove the download '{$tmpDownloadPath}': {$e->getMessage()}", true);
            }
        }

        $this->ok("Omeka S core updated to version {$versionNumber}.", true);
    }

    /**
     * Report a staging directory that was, or may be, left in or next to the web root. A warning
     * normally; under --quiet an error message, which --quiet does not hide, so a script still learns
     * about it. The exit code is not changed: the update itself succeeded.
     */
    private function reportLeftover(string $message): void
    {
        if ($this->isVerbose(self::VERBOSITY_OUTCOMES)) {
            $this->warn($message, true);
        } else {
            $this->io()->error($message, true);
        }
    }
}
