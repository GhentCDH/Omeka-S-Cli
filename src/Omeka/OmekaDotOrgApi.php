<?php
namespace OSC\Omeka;

use Exception;
use ZipArchive;

class OmekaDotOrgApi
{
    private const MODULE_API_URL = 'https://omeka.org/add-ons/json/s_module.json';
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

    public function getModules(): ?array
    {
        if ( !$this->modules ) {
            $this->modules = json_decode(file_get_contents(self::MODULE_API_URL), true) ?? [];
        }
        return $this->modules;
    }

    public function getThemes(): ?array
    {
        if ( !$this->themes ) {
            $this->themes = json_decode(file_get_contents(self::THEME_API_URL), true) ?? [];
        }
        return $this->themes;
    }

    public function getModule(string $id) {
        return $this->getModules()[$id] ?? null;
    }

    public function getModuleVersion(string $id, string $version = null): ?array {
        $moduleInfo = $this->getModule($id);
        if (!$moduleInfo) {
            return null;
        }

        $version = $version ?? $moduleInfo['latest_version'];
        return $moduleInfo['versions'][$version] ?? null;
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

        try {
            $tmpZipResource = fopen($tmpZipPath, "w+");

            if (!flock($tmpZipResource, LOCK_EX)) {
                throw new Exception('Failed to lock temporary file');
            }

            if (!fwrite($tmpZipResource, file_get_contents($url))) {
                throw new Exception("Failed to download ZIP file at '$url'");
            }

            $zip = new ZipArchive;
            if (true !== $zip->open($tmpZipPath)) {
                throw new Exception("Failed to open ZIP file '$tmpZipPath'");
            }

            if (!$zip->extractTo($destination)) {
                $zip->close();
                throw new Exception("Could not unzip the file '$tmpZipPath'");
            }

            $zip->close();
        }
        finally {
            flock($tmpZipResource, LOCK_UN);
            unlink($tmpZipPath);
        }
    }
}