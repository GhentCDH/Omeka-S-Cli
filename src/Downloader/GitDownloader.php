<?php

namespace OSC\Downloader;

use OSC\Helper\FileUtils;

class GitDownloader implements DownloaderInterface {

    private string $url;
    private ?string $tag;
    public function __construct(string $url)
    {
        $parsedUrl = $this->parseUrl($url);
        $this->url = $parsedUrl['url'];
        $this->tag = $parsedUrl['tag'];
    }

    public function download(): string
    {
        $tmpGitDestinationPath = FileUtils::createTempFolder('omeka-s-cli.');

        try {
            // Clone the repository
            $cloneCommand = sprintf('git clone %s %s 2> /dev/null', escapeshellarg($this->url), escapeshellarg($tmpGitDestinationPath));
            exec($cloneCommand, $output, $exitCode);
            $exitCode && throw new \ErrorException("Failed to clone repository");

            // Checkout the specified tag
            if ($this->tag) {
                $checkoutCommand = sprintf('cd %s && git checkout %s 2> /dev/null', escapeshellarg($tmpGitDestinationPath), escapeshellarg($this->tag));
                exec($checkoutCommand, $output, $exitCode);
                $exitCode && throw new \ErrorException("Failed to checkout tag/version");
            }

            // Install dependencies
            if (file_exists($tmpGitDestinationPath . '/composer.lock')) {
                $composerCommand = sprintf('cd %s && composer install -q --no-dev &> /dev/null', escapeshellarg($tmpGitDestinationPath));
                exec($composerCommand, $output, $exitCode);
                $exitCode && throw new \ErrorException("Failed to install dependencies");
            }

            return $tmpGitDestinationPath;
        } catch (\Throwable $e) {
            FileUtils::removeFolder($tmpGitDestinationPath);
            throw new \ErrorException("Failed to download and set up repository: " . $e->getMessage());
        }
    }

    private function parseUrl($url): array {
        $parts = explode('#', $url);
        return [
            'url' => $parts[0],
            'tag' => $parts[1] ?? null
        ];
    }    
}