<?php

namespace OSC\Commands\Module;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Argument;
use OSC\Commands\AbstractCommand;

abstract class AbstractModuleCommand extends AbstractCommand
{
    public function __construct(string $_name, string $_desc = '', bool $_allowUnknown = false, ?App $_app = null)
    {
        parent::__construct($_name, $_desc, $_allowUnknown, $_app);
    }

    public function argumentModuleId(bool $optional = false): self
    {
        $argument = new Argument($optional ? '[module-id]' : '<module-id>', 'Module id', null, fn($raw) => trim($raw));
        $this->register($argument);
        return $this;
    }
}