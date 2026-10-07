<?php

namespace OSC\Downloader;

use Exception;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use ZipArchive;

class ZipDownloader implements DownloaderInterface {

    public function __construct(private string $url)
    {
    }

    public function getDownloadUrl(): string
    {
        return $this->url;
    }
    public function download(): string
    {
        // a local zip release: fail with a clear message instead of a failed read
        if (!ResourceFetcher::isUrl($this->url) && !is_file($this->url)) {
            throw new Exception("Zip file not found: '{$this->url}'");
        }

        $tmpZipDestinationPath = Path::createTempFolder('omeka-s-cli.');
        $tmpZipFilePath = Path::createTempFile('omeka-s-cli.');

        try {
            $tmpZipResource = fopen($tmpZipFilePath, "w+");

            if (!flock($tmpZipResource, LOCK_EX)) {
                throw new Exception('Failed to lock temporary file');
            }

            if (!fwrite($tmpZipResource, file_get_contents($this->url))) {
                throw new Exception("Failed to download file at '$this->url'");
            }

            $zip = new ZipArchive;
            if (true !== $zip->open($tmpZipFilePath)) {
                throw new Exception("Failed to open file '$tmpZipFilePath'");
            }

            if (!$zip->extractTo($tmpZipDestinationPath)) {
                $zip->close();
                throw new Exception("Could not unzip file '$tmpZipFilePath'");
            }

            $zip->close();

            return $tmpZipDestinationPath;
        }
        finally {
            flock($tmpZipResource, LOCK_UN);
            unlink($tmpZipFilePath);
        }
    }
}