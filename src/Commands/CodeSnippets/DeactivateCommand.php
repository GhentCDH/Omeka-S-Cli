<?php

namespace OSC\Commands\CodeSnippets;

use CodeSnippets\Exception\SnippetNotFoundException;

class DeactivateCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:deactivate', 'Deactivate a snippet');
        $this->argument('<id>', 'Numeric snippet ID');
        $this->optionJson();
        $this->optionIgnoreNotFound('snippet');
    }

    public function execute(string $id, ?bool $json = false, ?bool $ignoreNotFound = false): void
    {
        $snippetId = $this->parseSnippetId($id);
        $updated = null;

        try {
            $updated = $this->getSnippetService()->deactivate($snippetId);
        } catch (SnippetNotFoundException $e) {
            $this->skipMissing($e, (bool) $ignoreNotFound);
        }

        $format = $this->getOutputFormat();
        if ($format === 'json') {
            $this->outputFormatted($updated, 'json');
            return;
        }

        $this->ok("Snippet #{$updated['id']} ('{$updated['name']}') deactivated.", true);
    }
}
