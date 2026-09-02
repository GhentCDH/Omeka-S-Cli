<?php
namespace OSC\Commands\Vocabulary;

use InvalidArgumentException;

class DeleteCommand extends AbstractVocabularyCommand
{
    public function __construct()
    {
        parent::__construct('vocabulary:delete', 'Delete a vocabulary');
        $this->optionIgnoreNotFound('vocabulary');
        $this->argument('<identifier>', 'Vocabulary ID or prefix');
    }

    public function execute(string $identifier, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();

        // Try to find vocabulary by ID or prefix
        $vocabularyRepresentation = $this->requireVocabulary($identifier, $api, $ignoreNotFound);

        // Check if vocabulary is protected
        if ($vocabularyRepresentation->isPermanent()) {
            throw new InvalidArgumentException("Vocabulary '{$vocabularyRepresentation->label()}' is protected and cannot be deleted.");
        }

        // Delete vocabulary
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('vocabularies', [ 'id' => $vocabularyRepresentation->id() ]);

        $this->ok("Vocabulary '{$vocabularyRepresentation->label()}' deleted.", true);
    }
}

