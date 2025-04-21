<?php
namespace OSC\Repository\Theme;

use OSC\Repository\RepositoryItemInterface;

class ThemeDetails implements RepositoryItemInterface
{
    /**
     * @param ThemeVersion[] $versions
     */
    public function __construct(
        // lowercase version of the module name
        public string  $id,
        // the name of the module
        public string  $dirname,
        public string  $latestVersion,
        public array   $versions,
        public ?string $link = null,
        public ?string $owner = null,
        public ?string $tags = null,
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

    public function getVersions(): array
    {
        return $this->versions;
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

    public function getVersion(string $versionNumber): ?ThemeVersion
    {
        return $this->versions[$versionNumber] ?? null;
    }

    public function getLatestVersion(): ThemeVersion
    {
        return $this->versions[$this->getLatestVersionNumber()];
    }

    public function matches($query): bool
    {
        $query = strtolower($query);
        return str_contains(strtolower($this->id ?? ''), $query)
            || str_contains(strtolower($this->dirname ?? ''), $query)
            || str_contains(strtolower($this->owner ?? ''), $query)
            || str_contains(strtolower($this->tags ?? ''), $query);
    }
}