<?php
namespace OSC\Commands\Resource;

use OSC\Resource\ResourceType;

class SearchCommand extends AbstractResourceCommand
{
    public function __construct(ResourceType $type)
    {
        parent::__construct($type, 'search', "Search {$type->label()}s by text");
        $this->argument('<text>', 'Text to search for in all properties (fulltext search)');
        $this->optionFilters();
        $this->option('--limit', 'Maximum number of results (default: ' . self::DEFAULT_LIMIT . ')');
        $this->option('--page', 'Page of results to show (with --limit)');
        $this->optionJson();
        $this->optionCSV();
        $prefix = $type->commandPrefix();
        $this->usage(
            "{$prefix}:search TEXT [options]<eol/>"
            . '<eol/>Examples:<eol/><eol/>'
            . "* Search {$type->label()}s for a word:<eol/>"
            . "{$prefix}:search leonardo<eol/>"
            . '<eol/>'
            . '* Search only private ones:<eol/>'
            . "{$prefix}:search leonardo --private<eol/>"
        );
    }

    public function execute(string $text): void
    {
        $this->listResources($text);
    }
}
