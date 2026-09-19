<?php

namespace OSC\Commands\CodeSnippets;

use Exception;
use InvalidArgumentException;

class ExportCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:export', 'Export one or all snippets in canonical portable format');
        $this->argument('[id]', 'Numeric snippet ID to export (optional)');
        $this->argument('[filename]', 'Output file path (optional)');
        $this->option('-o --output', 'Output file path', 'strval');
        $this->optionIgnoreNotFound('snippet');
        $this->usage(
            'code-snippet:export [id] [-o|--output=FILE]<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . '* Export all snippets to stdout:<eol/>'
            . 'code-snippet:export<eol/>'
            . '<eol/>'
            . '* Export single snippet 42 to stdout:<eol/>'
            . 'code-snippet:export 42<eol/>'
            . '<eol/>'
            . '* Export all snippets to a file:<eol/>'
            . 'code-snippet:export --output snippets.json<eol/>'
            . '<eol/>'
            . '* Export snippet 42 to a file:<eol/>'
            . 'code-snippet:export 42 --output snippet.json<eol/>'
        );
    }

    public function execute(
        ?string $id = null,
        ?string $filename = null,
        ?string $output = null,
        ?bool $ignoreNotFound = false
    ): void {
        $snippetId = null;
        $targetFile = $output ?? $filename;

        if ($id !== null && $id !== '') {
            if (is_numeric($id)) {
                $snippetId = (int) $id;
            } else {
                if ($targetFile === null) {
                    $targetFile = $id;
                } else {
                    throw new InvalidArgumentException("Snippet ID must be an integer, got: '{$id}'.");
                }
            }
        }

        try {
            $json = $this->getSnippetImportExport()->exportJson($snippetId);
        } catch (\Throwable $e) {
            $this->skipMissing($e, (bool) $ignoreNotFound);
        }

        if ($targetFile === null) {
            $this->echo($json, true);
        } else {
            if (file_put_contents($targetFile, $json . PHP_EOL) === false) {
                throw new Exception("Failed to write snippet export to file '{$targetFile}'.");
            }
            $this->ok("Snippet export written to '{$targetFile}'.", true);
        }
    }
}
