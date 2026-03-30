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
        public string  $name,
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
        return $this->dirname;
    }

    public function getIdentifiers(): array
    {
        return [
            'id' => $this->dirname,
        ];
    }

    public function resolves(string $identifier, ?string $type = null): bool
    {
        $identifiers = $this->getIdentifiers();
        return $type === null ?
            (strcasecmp(current($identifiers), $identifier)) === 0 :
            isset($identifiers[$type]) && (strcasecmp($identifiers[$type], $identifier) === 0);
    }

    public function matches($query): bool
    {
        $query = strtolower($query);
        return str_contains(strtolower($this->dirname ?? ''), $query)
            || str_contains(strtolower($this->name ?? ''), $query)
            || str_contains(strtolower($this->description ?? ''), $query)
            || str_contains(strtolower($this->tags ?? ''), $query);
    }

    public function getName(): string
    {
        return $this->name;
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
     * @return ModuleVersion[]
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
}