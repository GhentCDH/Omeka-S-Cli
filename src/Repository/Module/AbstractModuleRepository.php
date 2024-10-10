<?php
namespace OSC\Repository\Module;

abstract class AbstractModuleRepository implements ModuleRepositoryInterface
{
    public function list(): array
    {
        return $this->getModules();
    }

    public function find(string $id): ?ModuleRepresentation
    {
        return $this->getModules()[$id] ?? null;
    }

    /**
     * @param string $query
     * @return ModuleRepresentation[]
     */
    public function search(string $query): array
    {
        $query = strtolower($query);
        return array_filter($this->getModules(), function (ModuleRepresentation $module) use ($query) {
            return str_contains(strtolower($module->id ?? ''), $query)
                || str_contains(strtolower($module->description ?? ''), $query)
                || str_contains(strtolower($module->owner ?? ''), $query)
                || str_contains(strtolower($module->tags ?? ''), $query);
        });
    }

    /**
     * @return ModuleRepresentation[]|null
     */
    abstract protected function getModules(): ?array;
}
