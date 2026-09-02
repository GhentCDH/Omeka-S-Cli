<?php
namespace OSC\Commands\Vocabulary;

use InvalidArgumentException;
use Omeka\Api\Representation\VocabularyRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractVocabularyCommand extends AbstractCommand
{
    /**
     * Find a vocabulary by ID or prefix.
     *
     * @param string $identifier Vocabulary ID or prefix
     * @param mixed  $api        Omeka API instance
     *
     * @return VocabularyRepresentation|null
     */
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

    /**
     * Resolve a vocabulary by ID or prefix, honouring --ignore-not-found.
     *
     * @param string $identifier     Vocabulary ID or prefix
     * @param mixed  $api            Omeka API instance
     * @param bool   $ignoreNotFound Report a missing vocabulary instead of failing on it
     *
     * @return VocabularyRepresentation The resolved vocabulary (always; absence throws or aborts)
     *
     * @throws InvalidArgumentException If the vocabulary does not exist
     */
    protected function requireVocabulary(
        string $identifier,
        $api,
        bool $ignoreNotFound = false
    ): VocabularyRepresentation {
        $vocabulary = $this->findVocabulary($identifier, $api);
        if ($vocabulary) {
            return $vocabulary;
        }

        $this->skipMissing(
            new InvalidArgumentException("Could not find vocabulary by ID or prefix: '{$identifier}'."),
            $ignoreNotFound
        );
    }
}
