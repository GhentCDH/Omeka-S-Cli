<?php

namespace OSC\Downloader;

use OSC\Helper\Path;

class GitDownloader implements DownloaderInterface {

    public function __construct(private string $uri, private ?string $tag = null)
    {
    }

    public function getDownloadUrl(): string
    {
        return $this->uri;
    }
    public function download(): string
    {
        $tmpGitDestinationPath = Path::createTempFolder('omeka-s-cli.');

        try {
            // Clone the repository
            $cloneCommand = sprintf('git clone %s %s 2> /dev/null', escapeshellarg($this->uri), escapeshellarg($tmpGitDestinationPath));
            exec($cloneCommand, $output, $exitCode);
            $exitCode && throw new \ErrorException("Failed to clone repository");

            // Checkout the specified tag
            if ($this->tag) {
                $checkoutCommand = sprintf('cd %s && git checkout %s 2> /dev/null', escapeshellarg($tmpGitDestinationPath), escapeshellarg($this->tag));
                exec($checkoutCommand, $output, $exitCode);
                $exitCode && throw new \ErrorException("Failed to checkout tag/version");
            }

            return $tmpGitDestinationPath;
        } catch (\Throwable $e) {
            Path::removeFolder($tmpGitDestinationPath);
            throw new \ErrorException("Failed to download and set up repository: " . $e->getMessage());
        }
    }
}