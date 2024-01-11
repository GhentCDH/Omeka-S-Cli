<?php
namespace OSC\Omeka;

use Exception;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Entity\Site;
use Omeka\Service\ThemeManagerFactory;
use Omeka\Site\Theme\Manager as ThemeManager;
use Omeka\Site\Theme\Theme;
use Throwable;

class ThemeApi
{
    private ThemeManager $themeManager;

    private static array $instances = [];

    public static function getInstance(ServiceManager $serviceLocator): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static($serviceLocator);
        }

        return self::$instances[$cls];
    }

    public function __construct(private ServiceManager $serviceLocator)
    {
        $this->themeManager = $this->serviceLocator->get('Omeka\Site\ThemeManager');
    }

    public function reloadThemeManager(): void
    {
        $factory = new ThemeManagerFactory();
        $this->themeManager = $factory->__invoke($this->serviceLocator, '');
    }

    public function getThemes(): array
    {
        return $this->themeManager->getThemes();
    }

    public function getTheme(string $theme_id, bool $reload = false): ?Theme
    {
        $reload && $this->reloadThemeManager();
        $themes = $this->getThemes();
        return $themes[$theme_id] ?? null;
    }

    public function isInstalled(string $theme_id): bool
    {
        return (bool)($this->getTheme($theme_id));
    }

    public function hasErrors(Theme $theme): bool
    {
        return in_array($theme->getState(),
            [
                ThemeManager::STATE_NOT_FOUND,
                ThemeManager::STATE_INVALID_INI,
                ThemeManager::STATE_INVALID_OMEKA_VERSION,
            ],
            true
        );
    }

    public function upgrade(Theme $theme): void
    {
        $this->themeManager->upgrade($theme);
    }

    public function delete(Theme $theme): void
    {
        try {
            if ($theme->getState() === ThemeManager::STATE_NOT_FOUND) {
                throw new Exception('The theme can\'t be removed because its source files can not be found on disc.');
            }

            $path = $theme->getPath();
            if (empty($path) || $path == '/' || !(str_contains($path, 'themes')))
                throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');
            system("rm -rf " . escapeshellarg($path));
        } catch (Throwable $e) {
            throw new Exception("Could not delete theme: \n" . $e->getMessage());
        }
    }

    public function isActiveOnSite(Theme $theme): bool
    {
        $api = $this->serviceLocator->get('Omeka\ApiManager');
        /** @var Site[] $sites */
        $sites = $api->search('sites', [], ['responseContent' => 'resource'])->getContent();
        foreach($sites as $site) {
            if ($site->getTheme() === $theme->getIni()) {
                return true;
            }
        }
        return false;
    }
}