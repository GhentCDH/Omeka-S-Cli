<?php
namespace OSC\Repository\Theme;

use OSC\Repository\VersionableInterface;

class ThemeVersion implements VersionableInterface
{
    public function __construct(
        public string $version,
        public string $created,
        public string $downloadUrl,
        public ?string $omekaVersionConstraint = null,
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
}