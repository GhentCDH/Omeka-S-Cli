<?php

namespace OSC\Downloader;

use OSC\Source\GitRepository;
use OSC\Addon\GitRef;
use OSC\Helper\Path;

class GitDownloader implements DownloaderInterface {

    private string $uri;
    private ?string $tag;

    /** @throws \InvalidArgumentException When the ref is not a valid git ref (it is never passed to git as an option) */
    public function __construct(private GitRepository $repository)
    {
        $this->uri = $repository->cloneUrl();
        $this->tag = $repository->ref();
        if ($this->tag !== null) {
            GitRef::assertValid($this->tag);
        }
    }

    public function getDownloadUrl(): string
    {
        return $this->uri;
    }

    public function display(): string
    {
        return $this->repository->display();
    }

    /** The tag, branch or commit to check out, or null for the default branch. */
    public function getRef(): ?string
    {
        return $this->tag;
    }
    public function download(): string
    {
        $tmpGitDestinationPath = Path::createTempFolder('omeka-s-cli.');

        try {
            // Clone the repository
            $cloneCommand = sprintf('git clone --quiet -- %s %s 2> /dev/null', escapeshellarg($this->uri), escapeshellarg($tmpGitDestinationPath));
            exec($cloneCommand, $output, $exitCode);
            $exitCode && throw new \ErrorException("Failed to clone repository");

            // Checkout the specified tag: the ref never starts with '-' (GitRef), and '--' keeps it from
            // being read as a path. Not --end-of-options: `git checkout` only honours it from git 2.43.
            if ($this->tag) {
                $checkoutCommand = sprintf('git -C %s checkout --quiet %s -- 2> /dev/null', escapeshellarg($tmpGitDestinationPath), escapeshellarg($this->tag));
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