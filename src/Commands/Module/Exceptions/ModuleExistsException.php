<?php
namespace OSC\Commands\Module\Exceptions;

class ModuleExistsException extends \Exception
{
    public function __construct(string $moduleId)
    {
        parent::__construct("Module '{$moduleId}' already exists.");
    }
}