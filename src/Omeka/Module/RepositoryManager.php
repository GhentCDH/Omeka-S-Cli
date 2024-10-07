<?php
namespace OSC\Omeka\Module;

use OSC\Omeka\Module\ModuleResult;
use OSC\Omeka\Module\Repository\RepositoryInterface;


class RepositoryManager
{
    /** @var RepositoryInterface[] $repositories */
    private array $repositories = [];

    public function __construct(
        private array $repositoriesList
    ) {
        foreach ($this->repositoriesList as $repository) {
            $this->repositories[$repository->getId()] = $repository;
        }
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
                if (!isset($ret[$module->id])) {
                    $ret[$module->id] = new Moduleresult($module, $repository);
                }
            }
        }
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
                if (!isset($ret[$module->id])) {
                    $ret[$module->id] = new Moduleresult($module, $repository);
                }
            }
        }
        return $ret ? array_values($ret) : [];
    }
}