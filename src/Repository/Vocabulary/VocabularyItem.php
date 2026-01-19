<?php
namespace OSC\Repository\Vocabulary;

use OSC\Repository\RepositoryItemInterface;
use OSC\Repository\VersionableInterface;

class VocabularyItem implements RepositoryItemInterface
{
    public function __construct(
        public string $id,
        public string $label,
        public string $url,
        public string $namespaceUri,
        public string $prefix,
        public string $format,
        public ?string $comment = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIdentifiers(): array
    {
        return [
            'id' => $this->id,
            'namespaceUri' => $this->namespaceUri,
        ];
    }

    public function matches($query): bool
    {
        $query = strtolower($query);
        return str_contains(strtolower($this->label ?? ''), $query)
            || str_contains(strtolower($this->id ?? ''), $query)
            || str_contains(strtolower($this->prefix ?? ''), $query)
            || str_contains(strtolower($this->namespaceUri ?? ''), $query);
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
        return $this->label;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getNamespaceUri(): string
    {
        return $this->namespaceUri;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getVersions(): array
    {
        return [];
    }

    public function getVersion(string $versionNumber): ?VersionableInterface
    {
        return null;
    }

    public function getLatestVersion(): ?VersionableInterface
    {
        return null;
    }

    public function getLatestVersionNumber(): ?string
    {
        return null;
    }
}
