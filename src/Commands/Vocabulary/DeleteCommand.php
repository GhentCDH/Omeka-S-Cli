<?php
namespace OSC\Commands\Vocabulary;

use InvalidArgumentException;
use Omeka\Api\Representation\VocabularyRepresentation;
use OSC\Commands\AbstractCommand;

class DeleteCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('vocabulary:delete', 'Delete a vocabulary');
        $this->argument('<identifier>', 'Vocabulary ID or prefix');
    }

    public function execute(string $identifier): void
    {
        $api = $this->getOmekaInstance()->getApi();

        // Try to find user by ID or email
        /** @var VocabularyRepresentation $vocabularyRepresentation */
        $vocabularyRepresentation = $this->findVocabulary($identifier, $api);
        if (!$vocabularyRepresentation) {
            throw new InvalidArgumentException("Could not find vocabulary '{$identifier}' by ID or prefix.");
        }

        // Delete user
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('vocabularies', [ 'id' => $vocabularyRepresentation->id() ]);

        $this->ok("Vocabulary '{$vocabularyRepresentation->prefix()}' successfully deleted.", true);
    }

    protected function findVocabulary(string $identifier, $api): ?VocabularyRepresentation
    {
        // Try to find user by ID or email
        if (is_numeric($identifier)) {
            try {
                $result = $api->read('vocabularies', (int)$identifier);
                return $result->getContent();
            } catch (\Throwable $e) {
                return null;
            }
        }

        $search = $api->search('vocabularies', ['prefix' => $identifier]);
        return $search->getTotalResults() > 0 ? $search->getContent()[0] : null;
    }
}

