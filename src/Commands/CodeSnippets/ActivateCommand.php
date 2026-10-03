<?php

namespace OSC\Commands\CodeSnippets;

use CodeSnippets\Exception\SnippetNotFoundException;

class ActivateCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:activate', 'Activate a snippet');
        $this->argument('<id>', 'Numeric snippet ID');
        $this->optionJson();
        $this->optionIgnoreNotFound('snippet');
    }

    public function execute(string $id, ?bool $json = false, ?bool $ignoreNotFound = false): void
    {
        $snippetId = $this->parseSnippetId($id);
        $updated = null;

        try {
            $updated = $this->getSnippetService()->activate($snippetId);
        } catch (SnippetNotFoundException $e) {
            $this->skipMissing($e, (bool) $ignoreNotFound);
        }

        $format = $this->getOutputFormat();
        if ($format === 'json') {
            $this->outputFormatted($updated, 'json');
            return;
        }

        $this->ok("Snippet #{$updated['id']} ('{$updated['name']}') activated.", true);
    }
}
