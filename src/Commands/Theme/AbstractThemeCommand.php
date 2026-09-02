<?php

namespace OSC\Commands\Theme;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Argument;
use Omeka\Site\Theme\Theme;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\NotFoundException;

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

    /**
     * Resolve a single theme by id, honouring --ignore-not-found.
     *
     * @param string $themeId        Theme id
     * @param bool   $ignoreNotFound Report a missing theme instead of failing on it
     *
     * @return Theme The resolved theme (always; absence throws or is reported and aborts)
     *
     * @throws NotFoundException If the theme does not exist
     */
    protected function requireTheme(string $themeId, bool $ignoreNotFound = false): Theme
    {
        try {
            return $this->getOmekaInstance()->getThemeApi()->getTheme($themeId);
        } catch (NotFoundException $e) {
            $this->skipMissing($e, $ignoreNotFound);
        }
    }
}