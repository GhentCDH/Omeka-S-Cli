<?php

namespace OSC\Commands\CodeSnippets;

use InvalidArgumentException;

class ShowCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:show', 'Show one snippet');
        $this->argument('<id>', 'Numeric snippet ID');
        $this->optionJson();
        $this->optionIgnoreNotFound('snippet');
    }

    public function execute(string $id, ?bool $json = false, ?bool $ignoreNotFound = false): void
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Snippet ID must be an integer, got: '{$id}'.");
        }

        $snippet = $this->requireSnippet((int) $id, (bool) $ignoreNotFound);

        $format = $this->getOutputFormat('table');
        if ($format === 'json') {
            $this->outputFormatted($snippet, 'json');
            return;
        }

        $tableData = [
            ['Property' => 'id', 'Value' => $snippet['id']],
            ['Property' => 'name', 'Value' => $snippet['name']],
            ['Property' => 'description', 'Value' => $snippet['description'] ?? ''],
            ['Property' => 'code', 'Value' => $snippet['code']],
            ['Property' => 'priority', 'Value' => $snippet['priority']],
            ['Property' => 'run_scope', 'Value' => $snippet['run_scope']],
            ['Property' => 'active', 'Value' => $snippet['active']],
            ['Property' => 'created', 'Value' => $snippet['created']],
            ['Property' => 'modified', 'Value' => $snippet['modified']],
            ['Property' => 'last_error_type', 'Value' => $snippet['last_error_type'] ?? ''],
            ['Property' => 'last_error_message', 'Value' => $snippet['last_error_message'] ?? ''],
            ['Property' => 'last_error_line', 'Value' => $snippet['last_error_line'] ?? ''],
            ['Property' => 'last_error_at', 'Value' => $snippet['last_error_at'] ?? ''],
        ];

        $this->outputFormatted($tableData, 'table');
    }
}
