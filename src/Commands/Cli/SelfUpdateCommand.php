<?php

namespace OSC\Commands\Cli;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;

class SelfUpdateCommand extends AbstractCommand
{
    private const DOWNLOAD_URL = 'https://github.com/GhentCDH/Omeka-S-Cli/releases/latest/download/omeka-s-cli.phar';

    public function __construct()
    {
        parent::__construct('self-update', 'Update omeka-s-cli to the latest version');
    }

    public function execute(): void
    {
        $pharPath = $this->getPharPath();
        $this->checkWritable($pharPath);
        $tmpFile = $this->downloadLatest();

        try {
            // compared before it is opened: an identical download shares the running PHAR's alias, and
            // PHP refuses to open a second archive with that alias while this one runs
            if (self::isSameFile($tmpFile, $pharPath)) {
                @unlink($tmpFile);
                $this->ok('Already up to date (v' . OMEKA_S_CLI_VERSION . ').', true);
                return;
            }

            $newVersion = self::installableVersion($tmpFile);

            if ($newVersion === OMEKA_S_CLI_VERSION) {
                @unlink($tmpFile);
                $this->ok("Already up to date (v{$newVersion}).", true);
                return;
            }

            $this->applyPermissions($pharPath, $tmpFile);
            $this->replaceAtomically($pharPath, $tmpFile);
        } catch (Exception $e) {
            @unlink($tmpFile);
            throw $e;
        }

        $this->ok("omeka-s-cli updated to v{$newVersion}.", true);
    }

    private function getPharPath(): string
    {
        $path = \Phar::running(false);
        if ($path === '') {
            throw new Exception(
                'self-update is only available when running as a .phar file.'
            );
        }
        return $path;
    }

    private function checkWritable(string $pharPath): void
    {
        if (!is_writable($pharPath)) {
            throw new Exception(
                "Cannot write to '{$pharPath}'. Try running with elevated privileges (sudo)."
            );
        }
    }

    private function downloadLatest(): string
    {
        $this->info('Downloading latest release ... ');

        $tmpFile = Path::createTempFile('omeka-s-cli-update.');
        try {
            // streamed to disk; the exception carries the cURL/HTTP reason
            ResourceFetcher::download(self::DOWNLOAD_URL, $tmpFile);
        } catch (Exception $e) {
            @unlink($tmpFile);
            $this->io()->eol();
            throw $e;
        }

        $this->info('done', true);

        return $tmpFile;
    }

    /** Whether two files have the same contents. */
    public static function isSameFile(string $a, string $b): bool
    {
        $hashA = @hash_file('sha256', $a);
        return $hashA !== false && $hashA === @hash_file('sha256', $b);
    }

    /**
     * The version of a downloaded omeka-s-cli PHAR, which must be installable: a PHAR whose
     * composer.json names a version. Anything else (a truncated download, an error page) is refused,
     * so it never replaces the working PHAR.
     *
     * @throws Exception When the file is not such a PHAR
     */
    public static function installableVersion(string $pharFile): string
    {
        $version = self::getVersionFromPhar($pharFile);
        if (!is_string($version) || $version === '') {
            throw new Exception('The downloaded file is not a valid omeka-s-cli PHAR; nothing was changed.');
        }
        return $version;
    }

    private static function getVersionFromPhar(string $pharFile): ?string
    {
        $composerJson = @file_get_contents("phar://{$pharFile}/composer.json");
        if ($composerJson === false) {
            return null;
        }
        $composer = json_decode($composerJson, true);
        $version = is_array($composer) ? ($composer['version'] ?? null) : null;
        return is_string($version) ? $version : null;
    }

    private function applyPermissions(string $source, string $target): void
    {
        $perms = @fileperms($source);
        if ($perms !== false) {
            Path::checked(fn() => chmod($target, $perms & 0777), "Could not set the permissions of '{$target}'");
        }
    }

    private function replaceAtomically(string $pharPath, string $tmpFile): void
    {
        $this->info('Installing update ... ');

        // move() falls back to overwriting the PHAR when its directory is not writable (only the file is)
        Path::move($tmpFile, $pharPath, "Could not replace '{$pharPath}' with the downloaded update");

        $this->info('done', true);
    }
}
