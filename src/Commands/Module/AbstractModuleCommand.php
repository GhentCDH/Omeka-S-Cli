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

    public function argumentModuleId(): self
    {
        $argument = new Argument('<module-id>', 'The module ID (or id:version)', null, fn($raw) => trim($raw));
        $this->register($argument);
        return $this;
    }
}