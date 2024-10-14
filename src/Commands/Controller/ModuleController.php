<?php
namespace OSC\Commands\Controller;

use Exception;
use OSC\Cli\Application;
use Ahc\Cli\Input\Command;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Module\Module;
use OSC\Omeka\ModuleApi;
use OSC\Omeka\OmekaDotOrgApi;
use OSC\Manager\Module\Manager as ModuleRepositoryManager;
use Throwable;


class ModuleController extends AbstractCommandController
{
    protected OmekaDotOrgApi $webApi;
    protected ModuleApi $moduleApi;
    protected ModuleRepositoryManager $moduleRepo;

    public function __construct(Application $app, ServiceManager $serviceLocator)
    {
        parent::__construct($app, $serviceLocator);
        $this->webApi = OmekaDotOrgApi::getInstance();
        $this->moduleApi = ModuleApi::getInstance($serviceLocator);
        $this->moduleRepo = ModuleRepositoryManager::getInstance();
    }

    public function getCommands(): array
    {
        return [
            (new Command('module:list', 'List downloaded modules', 'l'))
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Outputs module path', 'boolval', false)
                ->action([$this, 'list']),
            (new Command('module:status', 'Get module status', 's'))
                ->argument('<module-id>','The module ID')
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Outputs module path', 'boolval', false)
                ->action([$this, 'status']),
            (new Command('module:available', 'List available modules', 'a'))
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Extended output', 'boolval', false)
                ->action([$this,'available']),
            (new Command('module:search', 'Search available modules', 'a'))
                ->argument('<query>', 'Part of the module name or description')
                ->option('-j --json', 'Outputs json', 'boolval', false)
                ->option('-x --extended', 'Extended output', 'boolval', false)
                ->action([$this,'search']),
            (new Command('module:download', 'Download module', 'd'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->option('-f --force', 'Force module overwrite', 'boolval', false)
                ->action([$this,'download']),
            (new Command('module:install', 'Install module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->usage("omeka-s-cli module:install Log<eol>omeka-s-cli module:install Log:3.4.19")
                ->action([$this,'install']),
            (new Command('module:uninstall', 'Uninstall module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->action([$this,'uninstall']),
            (new Command('module:enable', 'Enable module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->action([$this,'enable']),
            (new Command('module:disable', 'Disable module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->action([$this,'disable']),
            (new Command('module:upgrade', 'Upgrade module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->action([$this,'upgrade']),
            (new Command('module:delete', 'Delete module', 'i'))
                ->argument('<module-id>', 'The module ID (or id:version)')
                ->option('-f --force', 'Force module overwrite', 'boolval', false)
                ->action([$this,'delete']),
        ];
    }

    private function parseModuleVersionString($module_string): array {
        $parts = explode(':', $module_string);
        return [
            'id' => $parts[0],
            'version' => $parts[1] ?? null
        ];
    }

    public function list(?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        $modules = $this->moduleApi->getModules();

        $result_array = [];
        foreach ($modules as $module) {
            $result_array[] = $this->formatModuleStatus($module, $extended);
        }

        $this->outputFormatted($result_array, $format);
    }

    public function search(string $query, ?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        $moduleResults = $this->moduleRepo->search($query);
        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        $this->outputFormatted($moduleList, $format);
    }


    public function available(?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        $moduleResults = $this->moduleRepo->list();
        $moduleList = $this->formatModuleResults($moduleResults, $extended);
        $this->outputFormatted($moduleList, $format);
    }

    public function download(?string $moduleId, ?bool $force = false): void
    {
        try {
            ["id" => $moduleId, "version" => $moduleVersion] = $this->parseModuleVersionString($moduleId);

            if(str_starts_with($moduleId, 'http')){ // URL
                $downloadUrl = $moduleId;
            }else{               
                // find module in repositories
                $repoResult = $this->moduleRepo->find($moduleId, $moduleVersion);
                if(!$repoResult){
                    throw new Exception("Could not find module '{$moduleId}' in any repository.");
                }
                // set moduleId to dirname
                $moduleId = $repoResult->module->dirname;

                // check if version exists
                if ($moduleVersion && !$repoResult->version) {
                    throw new Exception("Module '{$moduleId}' has no version '{$moduleVersion}'.");
                }

                // check if module is already downloaded
                $module = $this->moduleApi->getModule($moduleId);
                if ( $module && !in_array($module->getState(), [ModuleManager::STATE_NOT_FOUND], true) ) {
                    if ( !$force) {
                        throw new Exception('The module seems to be already downloaded. Use the flag --force in order to download it anyway.');
                    }
                }

                // set download url
                $downloadUrl = $repoResult->version->downloadUrl;
            }

            // Todo: remove previous module data

            // Download and unzip
            $this->io()->white("Downloading {$downloadUrl} ... ");
            $this->webApi->downloadUnzip($downloadUrl, $this->app()->omeka()->path().'/modules/');
            $this->io()->white("done", true);

            // Check if module is available
            $module = $this->moduleApi->getModule($moduleId, true);
            if(!$module) {
                throw new Exception("Module '{$moduleId}' not found after download.");
            }
        } catch(Throwable $e) {
            $this->io()->error("Could not download module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function install(?string $moduleId): void
    {
        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module){
                $this->io()->white("Module not found. Trying to download it...", true);
                $this->download($moduleId);
                $module = $this->moduleApi->getModule($moduleId, true);
            }
            elseif(($module->getState() === ModuleManager::STATE_ACTIVE) || ($module->getState() === ModuleManager::STATE_NOT_ACTIVE)){
                throw new Exception('The module seems to be already installed');
            }

            // Check module dependencies


            // Downloaded and can be installed. Install
            $this->app()->omeka()->elevatePrivileges();
            $this->moduleApi->install($module);
            $this->io()->ok("Module '{$moduleId}' successfully installed.", true);
        } catch(Throwable $e) {
            $this->io()->error("Could not install module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function uninstall(?string $moduleId): void
    {
        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module) {
                throw new Exception("Module '{$moduleId}' not found.");
            }

            $this->app()->omeka()->elevatePrivileges();
            $this->moduleApi->uninstall($module);
            $this->io()->ok("Module '{$moduleId}' successfully uninstalled.", true);
        } catch(Throwable $e) {
            $this->io()->error("Could not uninstall module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function enable(?string $moduleId): void
    {
        try {
            $module = $this->moduleApi->getModule($moduleId);

            $this->app()->omeka()->elevatePrivileges();

            if(!$module){
                $this->io()->warn("Module '{$moduleId}' not found. Trying to download it...");
                $this->download($moduleId);
                $module = $this->moduleApi->getModule($moduleId, true);
                $this->moduleApi->install($module);
                $this->io()->ok("Module '{$moduleId}' successfully installed and activated.", true);
            } else {
                $this->moduleApi->enable($module);
                $this->io()->ok("Module '{$moduleId}' successfully activated.", true);
            }
        } catch(Throwable $e) {
            $this->io()->error("Could not enable module '{$moduleId}'. {$e->getMessage()}", true);
        }

    }

    public function disable(?string $moduleId): void
    {
        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module){
                throw new Exception("Module '{$moduleId}' not found");
            }

            $this->app()->omeka()->elevatePrivileges();
            $this->moduleApi->disable($module);
            $this->io()->ok("Successfully disabled module '{$moduleId}'.", true);
        } catch(Throwable $e) {
            $this->io()->error("Could not deactivate module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function upgrade(?string $moduleId): void
    {
        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module) {
                throw new Exception("Module '{$moduleId}' not found");
            }

            $this->app()->omeka()->elevatePrivileges();
            $this->moduleApi->upgrade($module);
            $this->io()->ok("Successfully upgraded module '{$moduleId}'.");
        } catch(Throwable $e) {
            $this->io()->error("Could not upgrade module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function delete(?string $moduleId, ?bool $force): void
    {
        $this->app()->omeka()->elevatePrivileges();

        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module) {
                throw new Exception("Module '{$moduleId}' not found");
            }

            if($module->getState() === ModuleManager::STATE_ACTIVE || $module->getState() === ModuleManager::STATE_NOT_ACTIVE) {
                if (!$force) {
                    throw new Exception("The module is currently installed. Use the --force flag to uninstall the module.");
                }
                // Uninstall the module
                $this->moduleApi->uninstall($module);
                $this->io()->ok("Successfully uninstalled module '{$moduleId}'.", true);
            }

            // Delete
            $this->moduleApi->delete($module);
            $this->io()->ok("Successfully deleted module '{$moduleId}'.", true);
        } catch(Throwable $e) {
            $this->io()->error("Could not delete module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    public function status(?string $moduleId, ?bool $json = false, ?bool $extended = false): void
    {
        $format = $json ? 'json' : 'table';

        try {
            $module = $this->moduleApi->getModule($moduleId);
            if(!$module) {
                throw new Exception("Module '{$moduleId}' not found");
            }

            $this->outputFormatted([$this->formatModuleStatus($module, $extended)], $format);
        } catch(Throwable $e) {
            $this->io()->error("Could not fetch status of module '{$moduleId}'. {$e->getMessage()}", true);
        }
    }

    private function formatModuleStatus(Module $module, bool $extended = false): array {
        $api_module = $this->moduleRepo->find($module->getId());

        $status = [
            'id' => $module->getId(),
            'name' => $module->getName(),
            'state' => $module->getState(),
            'version' => null,
            'upgradeAvailable' => null,
            'path' => null,
            'isConfigurable' => null,
        ];
        if ( !$this->moduleApi->hasErrors($module) ) {
            if ( $module->getState() === ModuleManager::STATE_NOT_INSTALLED ) {
                $status['version'] = $module->getIni()['version'].' downloaded';
            } else {
                $status['version'] = ($module->getDb()['version']==$module->getIni()['version']||!$module->getDb()['version'])?$module->getIni()['version']:($module->getIni()['version'].' ('.$module->getDb()['version'].' in database)')??'';
            }
            $latestVersion = $api_module?->module?->latestVersion;
            $status['upgradeAvailable'] = $latestVersion ? ($module->getIni()['version']!==$latestVersion ? $latestVersion: 'up to date') : 'unknown';
            $status['path'] = $module->getModuleFilePath();
            $status['isConfigurable'] = $module->isConfigurable();
        }

        if (!$extended) {
            unset($status['path']);
            unset($status['isConfigurable']);
        }
        return $status;
    }

    private function formatModuleResults($moduleResults, ?bool $extended = false): array {
        $moduleList = [];
        foreach ($moduleResults as $moduleResult) {
            $result = [
                'ID' =>  $moduleResult->module->dirname,
                'Latest version' => $moduleResult->module->latestVersion,
            ];
            if ($extended) {
                $result = array_merge($result, [
                    'Url' => $moduleResult->module->link,
                    'Description' => $moduleResult->module->description ?? '',
                    'Owner' => $moduleResult->module->owner ?? '',
                    'Repository' => $moduleResult->repository->getDisplayName(),
                ]);
            }
            $moduleList[] = $result;
        }
        return $moduleList;
    }
}