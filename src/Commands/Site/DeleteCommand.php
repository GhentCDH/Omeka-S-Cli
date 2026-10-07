<?php
namespace OSC\Commands\Site;

class DeleteCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:delete', 'Delete a site, with its pages');
        $this->argument('<site>', 'Site ID or slug');
        $this->optionIgnoreNotFound('site');
    }

    public function execute(string $site, ?bool $ignoreNotFound = false): void
    {
        $siteRepresentation = $this->requireSite($site, $ignoreNotFound);

        $this->siteApi()->delete($siteRepresentation);

        $this->ok("Site '{$siteRepresentation->slug()}' deleted.", true);
    }
}
