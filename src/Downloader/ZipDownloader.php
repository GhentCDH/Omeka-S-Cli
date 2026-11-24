<?php

namespace OSC\Downloader;

use OSC\Source\Location;
use Closure;
use Exception;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use ZipArchive;

/**
 * Download a ZIP release (a URL or a local file) and unpack it into a temp folder. A URL is
 * streamed to disk through ResourceFetcher::download(), so an archive never has to fit in memory;
 * a local ZIP is unpacked in place. extract() is also used by blueprint:bundle, which keeps the
 * ZIP itself.
 */
class ZipDownloader implements DownloaderInterface {

    /** @var (Closure(string, string): ?string)|null */
    private ?Closure $download;

    /**
     * @param (Closure(string, string): ?string)|null $download fn(url, target): Content-Type; downloads a URL
     *                                                         to a file (default ResourceFetcher::download)
     */
    public function __construct(private Location $zip, ?Closure $download = null)
    {
        $this->download = $download;
    }

    public function getDownloadUrl(): string
    {
        return $this->zip->value();
    }

    public function display(): string
    {
        return $this->zip->display();
    }

    /**
     * @return string The temp folder the ZIP was unpacked into (the caller removes it)
     * @throws Exception When the ZIP is missing, cannot be downloaded or cannot be unpacked; nothing is left behind
     */
    public function download(): string
    {
        if (!$this->zip->isRemote()) {
            // a local zip release: fail with a clear message instead of a failed read
            if (!is_file($this->zip->value())) {
                throw new Exception("Zip file not found: {$this->zip->display()}");
            }
            return self::extract($this->zip->value(), $this->zip->display());
        }

        $tmpZipFilePath = Path::createTempFile('omeka-s-cli.');
        try {
            if ($this->download !== null) {
                ($this->download)($this->zip->value(), $tmpZipFilePath);
            } else {
                ResourceFetcher::download($this->zip, $tmpZipFilePath);
            }
            return self::extract($tmpZipFilePath, $this->zip->display());
        } finally {
            @unlink($tmpZipFilePath);
        }
    }

    /**
     * Unpack a ZIP into a new temp folder.
     *
     * @param string|null $name What messages call the ZIP (e.g. the URL it came from); default $zipPath
     * @return string The temp folder (the caller removes it)
     * @throws Exception When it is no ZIP or cannot be unpacked; no folder is left behind then
     */
    public static function extract(string $zipPath, ?string $name = null): string
    {
        $name ??= $zipPath;
        $zip = new ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new Exception("{$name} is not a ZIP archive");
        }
        try {
            $folder = Path::createTempFolder('omeka-s-cli.');
            if (!@$zip->extractTo($folder)) {
                Path::removeFolder($folder);
                throw new Exception("could not unpack {$name}");
            }
            return $folder;
        } finally {
            $zip->close();
        }
    }
}
