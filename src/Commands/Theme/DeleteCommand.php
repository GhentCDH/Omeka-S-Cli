<?php
namespace OSC\Commands\Theme;


use Exception;

class DeleteCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:delete', 'Delete theme');
        $this->option('-f --force', 'Force theme delete', 'boolval', false);
        $this->optionIgnoreNotFound('theme');
        $this->argumentThemeId();
    }

    public function execute(?string $themeId, ?bool $force, ?bool $ignoreNotFound = false): void
    {
        $theme = $this->requireTheme($themeId, $ignoreNotFound);

        if($this->getOmekaInstance()->getThemeApi()->isActiveOnSite($theme)) {
            if (!$force) {
                throw new Exception("The theme is currently active on a site. Use the --force flag to proceed.");
            }
        }

        $this->getOmekaInstance()->getThemeApi()->delete($theme);
        $this->ok("Theme '{$themeId}' deleted.", true);
    }
}
