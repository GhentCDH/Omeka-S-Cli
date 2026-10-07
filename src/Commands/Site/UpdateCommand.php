<?php
namespace OSC\Commands\Site;

use InvalidArgumentException;
use OSC\Helper\SiteConfig;

class UpdateCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:update', 'Update site details');
        $this->argument('<site>', 'Site ID or slug');
        $this->option('--title', 'New title');
        $this->option('--slug', 'New URL slug (letters, digits, _ and -)');
        $this->option('--summary', 'New summary (an empty value clears it)');
        $this->option('--theme', 'New theme');
        $this->option('--public', 'Make the site public', 'boolval', false);
        $this->option('--private', 'Make the site private', 'boolval', false);
        $this->option('--default', 'Make it the default site', 'boolval', false);
        $this->option('--assign-new-items', 'Add newly created items to the site automatically', 'boolval', false);
        $this->option('--stop-assigning-new-items', 'Stop adding newly created items to the site', 'boolval', false);
        $this->optionIgnoreNotFound('site');
        $this->optionJson();
    }

    public function execute(
        string $site,
        ?string $title = null,
        ?string $slug = null,
        ?string $summary = null,
        ?string $theme = null,
        ?bool $public = false,
        ?bool $private = false,
        ?bool $default = false,
        ?bool $assignNewItems = false,
        ?bool $stopAssigningNewItems = false,
        ?bool $ignoreNotFound = false,
        ?bool $json = false
    ): void {
        // validate the input first, so --ignore-not-found never hides a mistake in the command
        if ($public && $private) {
            throw new InvalidArgumentException("Cannot use --public and --private together.");
        }
        if ($assignNewItems && $stopAssigningNewItems) {
            throw new InvalidArgumentException("Cannot use --assign-new-items and --stop-assigning-new-items together.");
        }

        // prepare data for update
        $data = SiteConfig::forUpdate([
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'theme' => $theme !== null ? $this->resolveTheme($theme) : null,
            'isPublic' => $public || $private ? (bool) $public : null,
            'assignNewItems' => $assignNewItems || $stopAssigningNewItems ? (bool) $assignNewItems : null,
        ])->toApiPatch();

        // fetch site
        $siteApi = $this->siteApi();
        $siteRepresentation = $this->requireSite($site, $ignoreNotFound);

        // update site
        if ($data) {
            $siteRepresentation = $siteApi->update($siteRepresentation, $data);
        }

        // set default site if requested
        if ($default) {
            $siteApi->setDefaultSite($siteRepresentation->id());
        }

        // prepare output for JSON format, if requested
        if ($json) {
            $this->outputFormatted($this->siteRow($siteRepresentation, $siteApi->getDefaultSiteId()), 'json');
        }

        if (!$data && !$default) {
            $this->info("Nothing to update for site '{$siteRepresentation->slug()}'.", true);
            return;
        }
        $this->ok("Site '{$siteRepresentation->slug()}' updated.", true);
    }
}
