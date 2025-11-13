<?php
namespace OSC\Commands\ResourceTemplates;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractResourceTemplateCommand extends AbstractCommand
{
    /** Search by ID only */
    public const SEARCH_BY_ID = 'id';

    /** Search by label only */
    public const SEARCH_BY_LABEL = 'label';

    /** Search by both ID and label (default) */
    public const SEARCH_BY_BOTH = 'both';


    /**
     * Find a resource template by ID or label
     *
     * @param string $identifier Resource template ID or label
     * @param ApiManager $api Omeka API instance
     * @param string $searchBy Search strategy: 'id', 'label', or 'both' (default: 'both')
     * @return ResourceTemplateRepresentation|null
     */
    protected function findResourceTemplate(
        string $identifier,
        ApiManager $api,
        string $searchBy = self::SEARCH_BY_BOTH
    ): ?ResourceTemplateRepresentation {
        // Search by ID
        if (($searchBy === self::SEARCH_BY_ID || $searchBy === self::SEARCH_BY_BOTH) && is_numeric($identifier)) {
            try {
                $result = $api->read('resource_templates', (int)$identifier);
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
        if ($searchBy === self::SEARCH_BY_LABEL || $searchBy === self::SEARCH_BY_BOTH) {
            $search = $api->search('resource_templates', ['label' => $identifier]);
            return $search->getTotalResults() > 0 ? $search->getContent()[0] : null;
        }

        return null;
    }
}
