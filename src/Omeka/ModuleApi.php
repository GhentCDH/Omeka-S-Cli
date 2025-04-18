<?php
namespace OSC\Omeka;

use Laminas\ServiceManager\ServiceManager;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Module\Module;
use Omeka\Service\ModuleManagerFactory;
use Exception;
use OSC\Helper\FileUtils;
use Throwable;

class ModuleApi
{
    private ModuleManager $moduleManager;

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
        $this->moduleManager = $this->serviceLocator->get('Omeka\ModuleManager');
    }

    public function reload(): void
    {
        $factory = new ModuleManagerFactory();
        $this->moduleManager = $factory->__invoke($this->serviceLocator, '');
    }

    /**
     * @return Module[]
     */
    public function getModules(): array
    {
        return $this->moduleManager->getModules();
    }

    /**
     * @throws Exception
     */
    public function getModule($moduleId, $reload = false): ?Module
    {
        // reload modules?
        $reload && $this->reload();

        // find module
        $modules = $this->getModules();
        foreach($modules as $tmpModuleId => $module) {
            $modules[strtolower($tmpModuleId)] = $module;
        }

        $module = $modules[strtolower($moduleId)] ?? null;
        if (!$module) {
            throw new Exception("Module '{$moduleId}' not found");
        }
        return $module;
    }

    public function isInstalled(string $moduleId): bool
    {
        try {
            $this->getModule($moduleId);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function isActive($moduleId): bool
    {
        try {
            $module = $this->getModule($moduleId);
            return in_array($module->getState(), [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function hasErrors(Module $module): bool
    {
        return !in_array($module->getState(),
            [
                ModuleManager::STATE_ACTIVE,
                ModuleManager::STATE_NOT_ACTIVE,
                ModuleManager::STATE_NEEDS_UPGRADE,
                ModuleManager::STATE_NOT_INSTALLED,
            ],
            true
        );
    }

    public function install(Module $module): void
    {
        $this->moduleManager->install($module);
    }

    public function uninstall(Module $module): void
    {
        $this->moduleManager->uninstall($module);
    }

    public function enable(Module $module): void
    {
        $this->moduleManager->activate($module);
    }

    public function disable(Module $module): void
    {
        $this->moduleManager->deactivate($module);
    }

    public function upgrade(Module $module): void
    {
        $this->moduleManager->upgrade($module);
    }

    public function delete(Module $module): void
    {
        if ($module->getState() === ModuleManager::STATE_NOT_FOUND) {
            throw new Exception('The module can\'t be removed because its source files can not be found on disc.');

        }
        if ($module->getState() === ModuleManager::STATE_ACTIVE || $module->getState() === ModuleManager::STATE_NOT_ACTIVE ) {
            throw new Exception('The module can\'t be removed because it seems to be installed.');
        }

        $path = dirname($module->getModuleFilePath());
        if (empty($path) || $path == '/' || !(str_contains($path, 'modules')))
            throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');

        FileUtils::removeFolder($path);
    }
}