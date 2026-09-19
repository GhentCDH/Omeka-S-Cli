<?php

namespace OSC\Commands\CodeSnippets;

use InvalidArgumentException;

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
        if (!is_numeric($id)) {
            throw new InvalidArgumentException("Snippet ID must be an integer, got: '{$id}'.");
        }

        $snippetId = (int) $id;

        try {
            $this->getSnippetService()->delete($snippetId);
        } catch (\Throwable $e) {
            $this->skipMissing($e, (bool) $ignoreNotFound);
        }

        $this->ok("Snippet #{$snippetId} deleted.", true);
    }
}
