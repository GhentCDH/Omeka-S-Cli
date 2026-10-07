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

        // get sites
        $sites = $siteApi->getSites();
        if (empty($sites)) {
            $this->warn("No sites found.", true);
            $this->outputFormatted([], $format);
            return;
        }

        // get default site ID
        $defaultSiteId = $siteApi->getDefaultSiteId();

        // prepare data for output
        $data = [];
        foreach ($sites as $site) {
            $data[] = $this->siteRow($site, $defaultSiteId);
        }

        // output
        $this->info("Found " . count($data) . " site(s).", true);
        $this->outputFormatted($data, $format);
    }
}
