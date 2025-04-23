<?php

namespace OSC\Commands\Theme\Types;

class DownloadInfo
{
    public function __construct(
        public string  $dirname,
        public string  $name,
        public ?string $description = null,
        public string  $version,
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

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getOmekaVersionConstraint(): ?string
    {
        return $this->omekaVersionConstaint;
    }
}