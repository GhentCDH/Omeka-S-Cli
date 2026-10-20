<?php
namespace OSC\Commands\Resource;

use OSC\Resource\ResourceType;

class ListCommand extends AbstractResourceCommand
{
    public function __construct(ResourceType $type)
    {
        parent::__construct($type, 'list', "List {$type->label()}s");
        $this->optionFilters();
        $this->option('--limit', 'Maximum number of results (default: ' . self::DEFAULT_LIMIT . ')');
        $this->option('--page', 'Page of results to show (with --limit)');
        $this->optionJson();
        $this->optionCSV();
        $prefix = $type->commandPrefix();
        $this->usage(
            "{$prefix}:list [options]<eol/>"
            . '<eol/>Examples:<eol/><eol/>'
            . "* List the first {$type->label()}s:<eol/>"
            . "{$prefix}:list<eol/>"
            . '<eol/>'
            . '* Filter by class and property value:<eol/>'
            . "{$prefix}:list --class dctype:StillImage --property dcterms:creator=Leonardo<eol/>"
            . '<eol/>'
            . '* As JSON, the second page of 10:<eol/>'
            . "{$prefix}:list --limit 10 --page 2 --json<eol/>"
        );
    }

    public function execute(): void
    {
        $this->listResources();
    }
}
