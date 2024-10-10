<?php
namespace OSC\Repository\Module;


interface ModuleRepositoryInterface
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