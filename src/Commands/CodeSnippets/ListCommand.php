<?php

namespace OSC\Commands\CodeSnippets;

class ListCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:list', 'List stored CodeSnippets snippets');
        $this->optionJson();
        $this->optionCSV();
    }

    public function execute(): void
    {
        $format = $this->getOutputFormat('table');
        $snippets = $this->getSnippetService()->findAll();

        if (empty($snippets)) {
            if ($format === 'table') {
                $this->info('No snippets found.', true);
                return;
            }
            $this->outputFormatted([], $format);
            return;
        }

        if ($format === 'json') {
            $this->outputFormatted($snippets, 'json');
            return;
        }

        $data = [];
        foreach ($snippets as $snippet) {
            $data[] = [
                'id' => $snippet['id'],
                'name' => $snippet['name'],
                'active' => $snippet['active'],
                'priority' => $snippet['priority'],
                'run_scope' => $snippet['run_scope'],
                'description' => $snippet['description'] ?? '',
            ];
        }

        $this->outputFormatted($data, $format);
    }
}
