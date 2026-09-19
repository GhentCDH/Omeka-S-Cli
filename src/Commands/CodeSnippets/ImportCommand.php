<?php

namespace OSC\Commands\CodeSnippets;

use OSC\Helper\ResourceFetcher;

class ImportCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:import', 'Import snippets from a canonical export document');
        $this->argument('<source>', 'File path or URL to import from');
        $this->optionJson();
        $this->usage(
            'code-snippet:import <source> [--json]<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . '* Import from local file:<eol/>'
            . 'code-snippet:import snippets.json<eol/>'
            . '<eol/>'
            . '* Import from URL:<eol/>'
            . 'code-snippet:import https://example.com/snippets.json<eol/>'
            . '<eol/>'
            . '* Import and output JSON created records:<eol/>'
            . 'code-snippet:import snippets.json --json<eol/>'
        );
    }

    public function execute(string $source, ?bool $json = false): void
    {
        $rawText = ResourceFetcher::fetch($source);
        $created = $this->getSnippetImportExport()->importJson($rawText);

        $format = $this->getOutputFormat();
        if ($format === 'json') {
            $this->outputFormatted($created, 'json');
            return;
        }

        $count = count($created);
        $noun = $count === 1 ? 'snippet' : 'snippets';
        $this->ok("{$count} {$noun} imported.", true);
    }
}
