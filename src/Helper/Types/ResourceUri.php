<?php
namespace OSC\Helper\Types;

class ResourceUri
{
    public string $id;
    public ?string $version;
    public ResourceUriType $type;

    public function __construct(ResourceUriType $type, string $id, ?string $version)
    {
        $this->id = $id;
        $this->version = $version;
        $this->type = $type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getType(): ResourceUriType
    {
        return $this->type;
    }
}