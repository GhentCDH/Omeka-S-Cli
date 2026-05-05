<?php
namespace OSC\Commands\CustomVocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use Omeka\Api\Manager as ApiManager;
use OSC\Commands\AbstractCommand;
use CustomVocab\Api\Representation\CustomVocabRepresentation;

abstract class AbstractCustomVocabularyCommand extends AbstractCommand
{
    /** Search by ID only */
    public const SEARCH_BY_ID = 'id';

    /** Search by label only */
    public const SEARCH_BY_LABEL = 'label';

    /** Search by both ID and label (default) */
    public const SEARCH_BY_BOTH = 'both';

    protected array $moduleDependencies = ['CustomVocab'];

    /**
     * Find a custom vocabulary by ID or label
     *
     * @param string $identifier Custom vocabulary ID or label
     * @param ApiManager $api Omeka API instance
     * @param string $searchBy Search strategy: 'id', 'label', or 'both' (default: 'both')
     * @return CustomVocabRepresentation|null
     */
    protected function findCustomVocabulary(
        string $identifier,
        ApiManager $api,
        string $searchBy = self::SEARCH_BY_BOTH
    ): ?CustomVocabRepresentation {
        // Search by ID
        if (($searchBy === self::SEARCH_BY_ID || $searchBy === self::SEARCH_BY_BOTH) && is_numeric($identifier)) {
            try {
                $result = $api->read('custom_vocabs', (int)$identifier);
                return $result->getContent();
            } catch (\Throwable $e) {
                // If searching by ID only, return null on failure
                if ($searchBy === self::SEARCH_BY_ID) {
                    return null;
                }
                // Otherwise continue to search by label
            }
        }

        // Search by label
        // can't filter by label using CustomVocab api
        // todo: consider adding label filtering to the api adapter to avoid fetching all records here
        if ($searchBy === self::SEARCH_BY_LABEL || $searchBy === self::SEARCH_BY_BOTH) {
            $search = $api->search('custom_vocabs');
            foreach($search->getContent() as $item) {
                if (strtolower($item->label()) === strtolower($identifier)) {
                    return $item;
                }
            }
        }

        return null;
    }

    protected function getCustomVocabulary(
        string $identifier,
        ApiManager $api,
        string $searchBy = self::SEARCH_BY_BOTH
    ): CustomVocabRepresentation {
        $customVocab = $this->findCustomVocabulary($identifier, $api, $searchBy);

        $byLabel = match($searchBy) {
            static::SEARCH_BY_LABEL => 'label',
            static::SEARCH_BY_ID => 'ID',
            static::SEARCH_BY_BOTH => 'ID or label'
        };

        if (!$customVocab) {
            throw new InvalidArgumentException("Custom vocabulary not found by {$byLabel}: '{$identifier}'.");
        }
        return $customVocab;
    }
}
