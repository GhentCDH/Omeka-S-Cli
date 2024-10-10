<?php
namespace OSC\Manager\Module;

use OSC\Repository\Module\ModuleRepositoryInterface;

class Manager
{
    /** @var ModuleRepositoryInterface[] $repositories */
    private array $repositories = [];

    private static array $instances = [];

    public static function getInstance(): static
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }
        return self::$instances[$cls];
    }


    public function addRepository(ModuleRepositoryInterface $repository): void
    {
        $this->repositories[] = $repository;
    }

    public function find(string $moduleId, ?string $version = null): ?ModuleResult
    {
        foreach($this->repositories as $repository) {
            $module = $repository->find($moduleId);
            if ($module) {
                $res = new ModuleResult($module, $repository);
                // todo: implement semantic version comparison
                if ($version) {
                    $res->version = $module->versions[$version] ?? null;
                } else {
                    $res->version = $module->versions[$module->latestVersion] ?? null;
                }
                return $res;
            }
        }
        return null;
    }

    /**
     * @return ModuleResult[]
     */
    public function list(): array
    {
        $ret = [];
        foreach ($this->repositories as $repository) {
            $modules = $repository->list();
            foreach ($modules as $module) {
                $key = strtolower($module->id);
                if (!isset($ret[$key])) {
                    $ret[$key] = new ModuleResult($module, $repository);
                }
            }
        }
        ksort($ret, SORT_NATURAL);
        return $ret ? array_values($ret) : [];
    }

    /**
     * @return ModuleResult[]
     */
    public function search(string $query): array
    {
        $ret = [];
        foreach ($this->repositories as $repository) {
            $modules = $repository->search($query);
            foreach ($modules as $module) {
                $key = strtolower($module->id);
                if (!isset($ret[$key])) {
                    $ret[$key] = new ModuleResult($module, $repository);
                }
            }
        }
        ksort($ret, SORT_NATURAL);
        return $ret ? array_values($ret) : [];
    }
}