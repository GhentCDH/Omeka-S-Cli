<?php
namespace OSC\Omeka;

use Exception;
use ZipArchive;

class OmekaDotOrgApi
{
    private const THEME_API_URL = 'https://omeka.org/add-ons/json/s_theme.json';
    private const OMEKA_VERSION_API_URL = 'https://api.omeka.org/latest-version-s';

    private ?array $modules = null;
    private ?array $themes = null;

    private static array $instances = [];


    public static function getInstance(): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    public function getThemes(): ?array
    {
        if ( !$this->themes ) {
            $this->themes = json_decode(file_get_contents(self::THEME_API_URL), true) ?? [];
        }
        return $this->themes;
    }

    public function getTheme(string $id) {
        return $this->getThemes()[$id] ?? null;
    }

    public function getThemeVersion(string $id, string $version = null): ?array {
        $themeInfo = $this->getTheme($id);
        if (!$themeInfo) {
            return null;
        }

        $version = $version ?? $themeInfo['latest_version'];
        return $themeInfo['versions'][$version] ?? null;
    }

    public function getLatestOmekaVersion(): string
    {
        return file_get_contents(self::OMEKA_VERSION_API_URL);
    }

    public function downloadUnzip(string $url, string $destination): void {
        $tmpZipPath = tempnam(sys_get_temp_dir(), 'omeka-s-cli.');
        if (!isset($tmpZipPath)) {
            throw new Exception('Failed to create temporary file');
        }

        if (!is_writable($tmpZipPath)) {
            throw new Exception('Temporary file is not writable');
        }

        if (!is_writable($destination)) {
            throw new Exception("Destination directory '$destination' is not writable");
        }

        try {
            $tmpZipResource = fopen($tmpZipPath, "w+");

            if (!flock($tmpZipResource, LOCK_EX)) {
                throw new Exception('Failed to lock temporary file');
            }

            if (!fwrite($tmpZipResource, file_get_contents($url))) {
                throw new Exception("Failed to download file at '$url'");
            }

            $zip = new ZipArchive;
            if (true !== $zip->open($tmpZipPath)) {
                throw new Exception("Failed to open file '$tmpZipPath'");
            }

            if (!$zip->extractTo($destination)) {
                $zip->close();
                throw new Exception("Could not unzip file '$tmpZipPath'");
            }

            $zip->close();
        }
        finally {
            flock($tmpZipResource, LOCK_UN);
            unlink($tmpZipPath);
        }
    }
}