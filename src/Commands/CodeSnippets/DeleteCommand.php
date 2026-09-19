<?php

namespace OSC\Commands\CodeSnippets;

use CodeSnippets\Exception\SnippetNotFoundException;

class DeleteCommand extends AbstractCodeSnippetCommand
{
    public function __construct()
    {
        parent::__construct('code-snippet:delete', 'Delete a snippet');
        $this->argument('<id>', 'Numeric snippet ID');
        $this->optionIgnoreNotFound('snippet');
    }

    public function execute(string $id, ?bool $ignoreNotFound = false): void
    {
        $snippetId = $this->parseSnippetId($id);

        try {
            $this->getSnippetService()->delete($snippetId);
        } catch (SnippetNotFoundException $e) {
            $this->skipMissing($e, (bool) $ignoreNotFound);
        }

        $this->ok("Snippet #{$snippetId} deleted.", true);
    }
}
