<?php
namespace OSC\Repository\Vocabulary;

use OSC\Repository\AbstractRepository;
use OSC\Repository\Module\ModuleDetails;

/**
 * @template T of ModuleDetails
 * @template-extends AbstractRepository<ModuleDetails>
 */
class GhentCDH extends AbstractRepository
{
    private const API_ENDPOINT = 'https://raw.githubusercontent.com/GhentCDH/Omeka-S-Vocabularies/refs/heads/index/vocabulary_index.csv';

    public function getId(): string
    {
        return 'ghentcdh';
    }

    public function getDisplayName(): string
    {
        return 'GhentCDH - Omeka S Vocabularies';
    }

    /**
     * @return VocabularyItem[]
     */
    public function entries(): array
    {
        $vocabularies = [];

        // Get the CSV data from the Daniel-KM module list
        $csv = file_get_contents(self::API_ENDPOINT);
        if (!$csv) {
            throw new \HttpRequestException("Failed to fetch data from " . self::API_ENDPOINT);
        }
        $csv = array_map('str_getcsv', explode(PHP_EOL, $csv));

        // validate csv structure
        if (!is_array($csv)) {
            throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
        }
        if (empty($csv)) {
            return $vocabularies;
        }

        $header = array_shift($csv);
        $expectedKeys = ['id', 'filename', 'url', 'label', 'namespaceUri', 'prefix', 'format', 'last_modified'];
        if (count($expectedKeys) !== count(array_intersect($header, $expectedKeys)) ) {
            throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
        }

        // Convert csv to associative array
        $data = [];
        foreach ($csv as $row) {
            if (count($row) === count($header)) {
                $data[] = array_combine($header, $row);
            }
        }

        // Create the modules array
        foreach ($data as $row) {
            $vocabularyId = strtolower($row['id']);
            $vocabularies[$vocabularyId] = new VocabularyItem(
                id: $vocabularyId,
                label: $row['label'],
                url: $row['url'],
                namespaceUri: $row['namespaceUri'],
                prefix: $row['prefix'],
                format: $row['format'],
                comment: $row['comment'] ?? null,
            );
        }

        return $vocabularies;
    }
}