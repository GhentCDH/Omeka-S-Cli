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

        // Try to find vocabulary by ID or email
        /** @var VocabularyRepresentation $vocabularyRepresentation */
        $vocabularyRepresentation = $this->findVocabulary($identifier, $api);
        if (!$vocabularyRepresentation) {
            throw new InvalidArgumentException("Could not find vocabulary by ID or prefix: '{$identifier}'.");
        }

        // Check if vocabulary is protected
        if ($vocabularyRepresentation->isPermanent()) {
            throw new InvalidArgumentException("Vocabulary '{$vocabularyRepresentation->label()}' is protected and cannot be deleted.");
        }

        // Delete vocabulary
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('vocabularies', [ 'id' => $vocabularyRepresentation->id() ]);

        $this->ok("Successfully deleted vocabulary '{$vocabularyRepresentation->label()}'.", true);
    }

    protected function findVocabulary(string $identifier, $api): ?VocabularyRepresentation
    {
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

