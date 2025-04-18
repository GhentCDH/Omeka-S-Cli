<?php
namespace OSC\Omeka;

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
}