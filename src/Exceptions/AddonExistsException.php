<?php

namespace OSC\Exceptions;

use OSC\Addon\AddonType;

/** A module or theme download found the add-on already on disk (and was not forced). */
class AddonExistsException extends WarningException
{
    public function __construct(private AddonType $type, private string $folder, string $path)
    {
        parent::__construct(
            ucfirst($type->value) . " '{$folder}' already exists in '{$path}'. Use the --force option to download anyway."
        );
    }

    public function type(): AddonType
    {
        return $this->type;
    }

    /** The add-on's folder name. */
    public function folder(): string
    {
        return $this->folder;
    }
}
