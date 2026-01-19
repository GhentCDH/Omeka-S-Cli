<?php
namespace OSC\Repository\Theme;

use OSC\Repository\RepositoryItemInterface;

class ThemeDetails implements RepositoryItemInterface
{
    /**
     * @param ThemeVersion[] $versions
     */
    public function __construct(
        public string  $name,
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
        return $this->dirname;
    }

    public function getIdentifiers(): array
    {
        return [
            'id' => $this->dirname,
        ];
    }

    public function matches($query): bool
    {
        $query = strtolower($query);
        return str_contains(strtolower($this->dirname ?? ''), $query)
            || str_contains(strtolower($this->name ?? ''), $query)
            || str_contains(strtolower($this->owner ?? ''), $query)
            || str_contains(strtolower($this->tags ?? ''), $query);
    }

    public function resolves(string $identifier, ?string $type = null): bool
    {
        $identifiers = $this->getIdentifiers();
        return $type === null ?
            (strcasecmp(current($identifiers), $identifier)) === 0 :
            isset($identifiers[$type]) && (strcasecmp($identifiers[$type], $identifier) === 0);
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
}