<?php
namespace OSC\Commands\Controller;

use OSC\Cli\Application;
use Ahc\Cli\Input\Command;
use Exception;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Site\Theme\Manager as ThemeManager;
use Omeka\Site\Theme\Theme;
use OSC\Omeka\ThemeApi;
use OSC\Omeka\OmekaDotOrgApi;
use Throwable;


class ThemeController extends AbstractCommandController
{
    protected OmekaDotOrgApi $webApi;
    protected ThemeApi $themeApi;

    public function __construct(Application $app, ServiceManager $serviceLocator)
    {
        parent::__construct($app, $serviceLocator);
        $this->webApi = OmekaDotOrgApi::getInstance();
        $this->themeApi = ThemeApi::getInstance($serviceLocator);
    }

    public function getCommands(): array
    {
        return [
            (new Command('theme:list', 'List downloaded themes', 'l'))
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Outputs theme path', 'boolval', false)
                ->action([$this, 'list']),
            (new Command('theme:status', 'Get theme status', 's'))
                ->argument('<theme-id>','The theme ID')
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Outputs theme path', 'boolval', false)
                ->action([$this, 'status']),
            (new Command('theme:available', 'List available themes', 'a'))
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->action([$this,'available']),
            (new Command('theme:download', 'Download theme', 'd'))
                ->argument('<theme-id>', 'The theme ID (or id:version)')
                ->option('-f --force', 'Force theme overwrite', 'boolval', false)
                ->action([$this,'download']),
            (new Command('theme:delete', 'Delete theme', 'i'))
                ->argument('<theme-id>', 'The theme ID (or id:version)')
                ->option('-f --force', 'Force theme overwrite', 'boolval', false)
                ->action([$this,'delete']),
        ];
    }

    public function list(?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        $themes = $this->themeApi->getThemes();

        $result_array = [];
        foreach ($themes as $theme) {
            $result_array[] = $this->formatThemeStatus($theme, $extended);
        }

        $this->outputFormatted($result_array, $format);
    }

    public function available(?bool $json = false): void
    {
        $format = $json ? 'json' : 'table';

        $api_themes = $this->webApi->getThemes();
        $result = [];
        foreach ($api_themes as $theme) {
            $result[] = [
                'id' =>  $theme['dirname'],
                'latestVersion' => $theme['latest_version'],
                'owner' => $theme['owner'],
            ];
        }
        $this->outputFormatted($result, $format);
    }

    public function download(?string $themeId, $force): void
    {
        try {
            ["id" => $themeId, "version" => $themeVersion] = $this->parseThemeVersionString($themeId);

            if(str_starts_with($themeId, 'http')){ // URL
                $downloadUrl = $themeId;
            }else{
                $apiTheme = $this->webApi->getTheme($themeId);
                if(!$apiTheme){
                    throw new Exception("Theme '{$themeId}' is not found in the official theme list.");
                }

                $apiThemeVersion = $this->webApi->getThemeVersion($themeId, $themeVersion);
                if(!$apiThemeVersion){
                    throw new Exception("Theme '{$themeId}' with version '{$themeVersion}' is not found in the official theme list.");
                }

                $theme = $this->themeApi->getTheme($themeId);
                if ( $theme && !in_array($theme->getState(), [ThemeManager::STATE_NOT_FOUND], true) ) {
                    if ( !$force) {
                        throw new Exception('The theme seems to be already downloaded. Use the flag --force in order to download it anyway.');
                    }
                }

                $downloadUrl = $apiThemeVersion['download_url'];
            }

            // Download and unzip
            $this->io()->white("Downloading {$downloadUrl} ... ");
            $this->webApi->downloadUnzip($downloadUrl, $this->app()->omeka()->path().'/themes/');
            $this->io()->white("done", true);
        } catch (Exception $e) {
            $this->io()->error("Could not download theme '{$themeId}'. {$e->getMessage()}", true);
        }
    }

    public function delete(?string $themeId, ?bool $force): void
    {
        $this->app()->omeka()->elevatePrivileges();

        try {
            $theme = $this->themeApi->getTheme($themeId);
            if(!$theme) {
                throw new Exception("Theme '{$themeId}' not found");
            }

            if($this->themeApi->isActiveOnSite($theme)) {
                if (!$force) {
                    throw new Exception("The theme is currently active on a site. Use the --force flag to uninstall the theme.");
                }
            }

            // Delete
            $this->themeApi->delete($theme);
            $this->io()->ok("Successfully deleted theme '{$themeId}.");
        } catch(Throwable $e) {
            $this->io()->error("Could not delete theme '{$themeId}'. {$e->getMessage()}", true);
        }
    }

    public function status(?string $themeId, ?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        try {
            $theme = $this->themeApi->getTheme($themeId);
            if(!$theme) {
                throw new Exception("Theme '{$themeId}' not found");
            }

            $this->outputFormatted([$this->formatThemeStatus($theme, $extended)], $format);
        } catch(Throwable $e) {
            $this->io()->error("Could not fetch status of theme '{$themeId}'. {$e->getMessage()}", true);
        }
    }

    private function formatThemeStatus(Theme $theme, bool $extended = false): array {
        $api_theme = $this->webApi->getTheme($theme->getId());

        $status = [
            'id' => $theme->getId(),
            'name' => $theme->getName(),
            'state' => $theme->getState(),
            'version' => null,
            'author' => null,
            'path' => null,
            'upgradeAvailable' => null,
            'isConfigurable' => null,
            'isConfigurableResourcePageBlocks' => null,
        ];
        if ( !$this->themeApi->hasErrors($theme) ) {
            $status['version'] = $theme->getIni()['version'];
            $status['author'] = $theme->getIni()['author'];
            $status['isConfigurable'] = $theme->isConfigurable();
            $status['isConfigurableResourcePageBlocks'] = $theme->isConfigurableResourcePageBlocks();
            $status['path'] = $theme->getPath();
            $status['upgradeAvailable'] = isset($api_theme['latest_version']) ? ($theme->getIni()['version']!==$api_theme['latest_version'] ? $api_theme['latest_version']: 'up to date') : 'unknown';
        }
        if (!$extended) {
            unset($status['path']);
            unset($status['isConfigurable']);
            unset($status['isConfigurableResourcePageBlocks']);
        }
        return $status;
    }

    private function parseThemeVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }

}