<?php

namespace OSC\Commands\Module\Types;

class DownloadInfo
{
    public function __construct(
        public string $dirname,
        public string $name,
        public ?string $description = null,
        public string $version,
        public ?array  $dependencies = [],
        public ?string $omekaVersionConstaint = null
    ) {
    }

    public function getDirname(): string
    {
        return $this->dirname;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDependencies(): ?array
    {
        return $this->dependencies;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getOmekaVersionConstraint(): ?string
    {
        return $this->omekaVersionConstaint;
    }
}