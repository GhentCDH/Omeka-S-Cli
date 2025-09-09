<?php

namespace OSC\Commands\Theme;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Argument;
use OSC\Commands\AbstractCommand;

abstract class AbstractThemeCommand extends AbstractCommand
{
    public function __construct(string $_name, string $_desc = '', bool $_allowUnknown = false, ?App $_app = null)
    {
        parent::__construct($_name, $_desc, $_allowUnknown, $_app);
    }

    public function argumentThemeId(): static {
        $argument = new Argument('<theme-id>', 'The theme ID (or id:version)', null, fn($raw) => trim($raw));
        $this->register($argument);
        return $this;
    }
}