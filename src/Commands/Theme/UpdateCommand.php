<?php
namespace OSC\Commands\Theme;

use Exception;
use InvalidArgumentException;
use OSC\Addon\AddonType;
use OSC\Helper\Offline;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUriType;

class UpdateCommand extends AbstractThemeCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('theme:update', 'Update theme');
        $this->argumentThemeId(true);
        $this->option('-a --all', 'Update all themes', null, false);
        $this->option('--backup', 'Backup current theme before download (delete otherwise)', 'boolval', false);
        $this->optionIgnoreNotFound('theme');
        $this->optionDryRun();
        $this->usage(
            'theme:update freedom<eol>' .
            'theme:update freedom:1.1.0<eol>' .
            'theme:update --all --dry-run'
        );
    }

    public function execute(?string $themeId, ?bool $all = false, ?bool $backup = false, ?bool $ignoreNotFound = false): void
    {
        if ($themeId && $all) {
            throw new InvalidArgumentException("You cannot specify both a theme ID and the --all option.");
        }
        if (!$themeId && !$all) {
            throw new InvalidArgumentException("Specify a theme ID or the --all option.");
        }

        if ($themeId) {
            // the update is looked up in the repositories (also for --dry-run, which reports it)
            Offline::assertOnline('check for an update of the theme');

            $themeUri = ResourceUriParser::parse($themeId);
            if ($themeUri->getType() !== ResourceUriType::IdVersion) {
                throw new InvalidArgumentException("The theme-id argument must be in the format 'theme-id' or 'theme-id:version'.");
            }
            $theme = $this->requireTheme($themeUri->getId(), (bool) $ignoreNotFound);

            if ($this->isDryRun()) {
                $this->reportDryRun("Theme '{$theme->getId()}' would be updated.");
                return;
            }

            // into the theme's current folder: the repository's folder name can differ (#62)
            $this->download($themeId, $theme->getId(), (bool) $backup);
            return;
        }

        Offline::assertOnline('check for theme updates');
        $themesToUpdate = $this->collectThemesToUpdate();
        if (!count($themesToUpdate)) {
            $this->info("No themes to update.", true);
            return;
        }

        $hasErrors = !$this->runBulkOperation(
            $themesToUpdate,
            fn(string $id) => $this->download($id, $id, (bool) $backup),
            'Updating',
            'updated',
            AddonType::Theme
        );
        if ($this->isDryRun()) {
            return;
        }
        if ($hasErrors) {
            throw new Exception("Some themes could not be updated. Please check the error messages above.");
        }
        $this->info("All themes updated.", true);
    }

    /** Download $uri over the theme in themes/$folder. */
    private function download(string $uri, string $folder, bool $backup): void
    {
        /** @var DownloadCommand $command */
        $command = $this->app()->commands()['theme:download'] ?? null;
        $command && $command->execute($uri, true, $backup, $folder);
    }

    /**
     * The themes a bulk update would affect: those for which the repository has a newer release
     * (a network lookup per theme, through formatThemeStatus()).
     *
     * @return string[] Theme ids
     */
    private function collectThemesToUpdate(): array
    {
        $ids = [];
        foreach ($this->getOmekaInstance()->getThemeApi()->getThemes() as $theme) {
            if ($this->formatThemeStatus($theme)['updateAvailable']) {
                $ids[] = $theme->getId();
            }
        }
        return $ids;
    }
}
