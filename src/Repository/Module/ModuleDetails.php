<?php
namespace OSC\Repository\Module;

use OSC\Repository\RepositoryItemInterface;
use OSC\Repository\VersionableInterface;

class ModuleDetails implements RepositoryItemInterface
{
    /**
     * @param ModuleVersion[] $versions
     */
    public function __construct(
        // lowercase version of the module name
        public string  $id,
        // the name of the module
        public string  $dirname,
        public string  $latestVersion,
        public array   $versions,
        public ?string $description = null,
        public ?string $link = null,
        public ?string $owner = null,
        public ?string $tags = null,
        public ?array  $dependencies = []
    ) {

    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDirname(): string
    {
        return $this->dirname;
    }

    public function getLatestVersionNumber(): string
    {
        return $this->latestVersion;
    }

    /**
     * @return VersionableInterface[]
     */
    public function getVersions(): array
    {
        return $this->versions;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function getTags(): ?string
    {
        return $this->tags;
    }

    public function getDependencies(): ?array
    {
        return $this->dependencies;
    }

    public function getVersion(string $versionNumber): ?ModuleVersion
    {
        return $this->versions[$versionNumber] ?? null;
    }

    public function getLatestVersion(): ModuleVersion
    {
        return $this->versions[$this->latestVersion];
    }

    public function matches($query): bool
    {
        $query = strtolower($query);
        return str_contains(strtolower($this->id ?? ''), $query)
            || str_contains(strtolower($this->description ?? ''), $query)
            || str_contains(strtolower($this->owner ?? ''), $query)
            || str_contains(strtolower($this->tags ?? ''), $query);
    }
}