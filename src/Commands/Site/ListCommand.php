<?php
namespace OSC\Commands\Site;

class ListCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:list', 'List all sites');
        $this->optionJson();
        $this->optionCSV();
        $this->optionTable();
    }

    public function execute(): void
    {
        $siteApi = $this->siteApi();

        $format = $this->getOutputFormat('table');

        $sites = $siteApi->getSites();

        if (empty($sites)) {
            $this->warn("No sites found.", true);
            $this->outputFormatted([], $format);
            return;
        }

        $defaultSiteId = $siteApi->getDefaultSiteId();
        $data = [];
        foreach ($sites as $site) {
            $data[] = $this->siteRow($site, $defaultSiteId);
        }

        $this->info("Found " . count($data) . " site(s).", true);
        $this->outputFormatted($data, $format);
    }
}
