<?php
namespace OSC\Repository\Module;

use OSC\Repository\VersionableInterface;

class ModuleVersion implements VersionableInterface
{
    public function __construct(
        public string $version,
        public string $created,
        public string $downloadUrl,
        public ?string $omekaVersionConstraint = null,
        public array $dependencies = [],
    ) {
    }

    public function getVersionNumber(): string
    {
        return $this->version;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function getOmekaVersionConstraint(): ?string
    {
        return $this->omekaVersionConstraint;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }
}