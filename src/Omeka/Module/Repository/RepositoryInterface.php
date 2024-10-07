<?php
namespace OSC\Omeka\Module\Repository;

use OSC\Omeka\Module\ModuleRepresentation;

interface RepositoryInterface
{
    public function getId(): string;
    public function getDisplayName(): string;

    /**
     * @return ModuleRepresentation[]
     */
    public function list(): array;

    public function find(string $id): ?ModuleRepresentation;

    /**
     * @return ModuleRepresentation[]
     */
    public function search(string $query): array;
}