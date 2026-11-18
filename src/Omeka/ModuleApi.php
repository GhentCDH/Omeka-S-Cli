<?php
namespace OSC\Omeka;

use Laminas\ServiceManager\ServiceManager;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Module\Module;
use Omeka\Service\ModuleManagerFactory;
use Exception;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\Path;
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
            throw new NotFoundException("Module '{$moduleId}' not found");
        }
        return $module;
    }

    public function isActive($moduleId): bool
    {
        try {
            $module = $this->getModule($moduleId);
            return $module->getState() === ModuleManager::STATE_ACTIVE;
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
        // Omeka S removes the row but keeps its copy on the module; isRegistered() reads that copy
        $module->setDb(null);
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

    /**
     * Whether the module has a row in Omeka's module table, whatever its state.
     *
     * Besides an installed module (active or not), this covers modules Omeka S can not uninstall:
     * files gone (not_found), awaiting an upgrade, or invalid for the current Omeka S version.
     *
     * @param Module $module The module
     *
     * @return bool True when the module table has a row for it
     */
    public function isRegistered(Module $module): bool
    {
        return $module->getDb() !== null;
    }

    /**
     * Remove the module's row from Omeka's module table without running its uninstaller.
     *
     * Meant for a module Omeka S can not uninstall (see isRegistered()). The module's own tables
     * and settings stay in place. An installed module is refused: uninstall() runs its uninstaller.
     *
     * @param Module $module The module to unregister
     *
     * @return void
     *
     * @throws Exception If the module is installed or not registered
     */
    public function unregister(Module $module): void
    {
        if (in_array($module->getState(), [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE], true)) {
            throw new Exception("Module '{$module->getId()}' is installed; uninstall it instead.");
        }
        if (!$this->isRegistered($module)) {
            throw new Exception("Module '{$module->getId()}' is not registered in the database.");
        }

        // untyped: Omeka's connection is an unprefixed Doctrine object (see scoper.inc.php)
        $connection = $this->serviceLocator->get('Omeka\Connection');
        $connection->delete('module', ['id' => $module->getId()]);

        $module->setDb(null);
    }

    /**
     * Remove the folder of a module that is not registered in the database.
     *
     * @param Module $module The module to delete
     *
     * @return void
     *
     * @throws Exception If the module is installed, still registered or its folder is missing
     */
    public function delete(Module $module): void
    {
        if ($module->getState() === ModuleManager::STATE_NOT_FOUND) {
            throw new NotFoundException('The module can\'t be removed because its source files can not be found on disc.');

        }
        if ($module->getState() === ModuleManager::STATE_ACTIVE || $module->getState() === ModuleManager::STATE_NOT_ACTIVE ) {
            throw new Exception('The module can\'t be removed because it seems to be installed.');
        }
        if ($this->isRegistered($module)) {
            throw new Exception('The module can\'t be removed because it is still registered in the database.');
        }

        // Omeka S only knows Module.php's path once the ini is valid and the file exists; the
        // folder itself is always modules/<id>
        $moduleFilePath = $module->getModuleFilePath();
        $path = $moduleFilePath ? dirname($moduleFilePath) : OMEKA_PATH . '/modules/' . $module->getId();
        if (empty($path) || $path == '/' || !(str_contains($path, 'modules')))
            throw new Exception('Incorrect or dangerous path detected. Please remove the folder manually.');

        Path::removeFolder($path);
    }
}