<?php
namespace OSC\Repository\Vocabulary;

use OSC\Repository\AbstractRepository;

/**
 * Linked Open Vocabularies (LOV) Repository
 *
 * Fetches vocabulary metadata from https://lov.linkeddata.es/
 *
 * @template T of VocabularyItem
 * @template-extends AbstractRepository<VocabularyItem>
 */
class LOV extends AbstractRepository
{
    private const API_ENDPOINT = 'https://lov.linkeddata.es/dataset/lov/api/v2/vocabulary/list';

    public function getId(): string
    {
        return 'lov';
    }

    public function getDisplayName(): string
    {
        return 'Linked Open Vocabularies (LOV)';
    }

    /**
     * @return VocabularyItem[]
     */
    public function entries(): array
    {
        $vocabularies = [];

        // Fetch JSON data from LOV API
        $json = file_get_contents(self::API_ENDPOINT);
        if (!$json) {
            throw new \HttpRequestException("Failed to fetch data from " . self::API_ENDPOINT);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Invalid JSON data from " . self::API_ENDPOINT);
        }

        if (empty($data)) {
            return $vocabularies;
        }

        // Convert LOV data to VocabularyItem objects
        foreach ($data as $item) {
            // Skip items without required fields
            if (!isset($item['prefix']) || !isset($item['nsp'])) {
                continue;
            }

            $prefix = $item['prefix'];
            $vocabularyId = strtolower($prefix);

            // Extract the title (prefer English)
            $label = $prefix; // Default to prefix
            if (isset($item['titles']) && is_array($item['titles'])) {
                foreach ($item['titles'] as $title) {
                    if (isset($title['value'])) {
                        $label = $title['value'];
                        // Prefer English titles
                        if (isset($title['lang']) && $title['lang'] === 'en') {
                            break;
                        }
                    }
                }
            }

            // Extract description/comment if available
            $comment = null;
            if (isset($item['descriptions']) && is_array($item['descriptions'])) {
                foreach ($item['descriptions'] as $desc) {
                    if (isset($desc['value'])) {
                        $comment = $desc['value'];
                        // Prefer English descriptions
                        if (isset($desc['lang']) && $desc['lang'] === 'en') {
                            break;
                        }
                    }
                }
            }

            // Use the URI as the URL (LOV doesn't provide direct download URLs in this endpoint)
            $url = $item['uri'] ?? $item['nsp'];

            $vocabularies[$vocabularyId] = new VocabularyItem(
                id: $vocabularyId,
                label: $label,
                url: $url,
                namespaceUri: $item['nsp'],
                prefix: $prefix,
                format: 'auto', // LOV doesn't specify format in the list endpoint
                comment: $comment,
            );
        }

        return $vocabularies;
    }
}

