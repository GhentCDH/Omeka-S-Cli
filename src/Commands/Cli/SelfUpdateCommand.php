<?php

namespace OSC\Commands\Cli;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Path;

class SelfUpdateCommand extends AbstractCommand
{
    private const DOWNLOAD_URL = 'https://github.com/GhentCDH/Omeka-S-Cli/releases/latest/download/omeka-s-cli.phar';
    private const DOWNLOAD_TIMEOUT = 60;

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
            $newVersion = $this->getVersionFromPhar($tmpFile);

            if ($newVersion !== null && $newVersion === OMEKA_S_CLI_VERSION) {
                $this->ok("Already up to date (v{$newVersion}).", true);
                return;
            }

            $this->applyPermissions($pharPath, $tmpFile);
            $this->replaceAtomically($pharPath, $tmpFile);
        } catch (Exception $e) {
            @unlink($tmpFile);
            throw $e;
        }

        $versionLabel = $newVersion !== null ? " to v{$newVersion}" : '';
        $this->ok("omeka-s-cli successfully updated{$versionLabel}.", true);
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

        $context = stream_context_create([
            'http' => [
                'timeout'         => self::DOWNLOAD_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => 'omeka-s-cli/' . OMEKA_S_CLI_VERSION,
            ],
        ]);

        $data = @file_get_contents(self::DOWNLOAD_URL, false, $context);

        if ($data === false || strlen($data) === 0) {
            throw new Exception('Failed to download latest release. Check your internet connection.');
        }

        $tmpFile = Path::createTempFile('omeka-s-cli-update.');

        if (file_put_contents($tmpFile, $data) === false) {
            @unlink($tmpFile);
            throw new Exception("Could not write to temporary file '{$tmpFile}'.");
        }

        $this->info('done', true);

        return $tmpFile;
    }

    private function getVersionFromPhar(string $pharFile): ?string
    {
        $composerJson = @file_get_contents("phar://{$pharFile}/composer.json");
        if ($composerJson === false) {
            return null;
        }
        $composer = json_decode($composerJson, true);
        return $composer['version'] ?? null;
    }

    private function applyPermissions(string $source, string $target): void
    {
        $perms = fileperms($source);
        if ($perms !== false) {
            chmod($target, $perms & 0777);
        }
    }

    private function replaceAtomically(string $pharPath, string $tmpFile): void
    {
        $this->info('Installing update ... ');

        if (!rename($tmpFile, $pharPath)) {
            // rename() fails across filesystem boundaries (e.g. /tmp on tmpfs vs /usr/local/bin)
            if (!copy($tmpFile, $pharPath)) {
                throw new Exception("Could not replace '{$pharPath}' with the downloaded update.");
            }
            @unlink($tmpFile);
        }

        $this->info('done', true);
    }
}
