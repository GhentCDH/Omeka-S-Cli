<?php
namespace OSC\Commands\CustomVocabulary;

use Exception;
use OSC\Omeka\CustomVocabImportExport;

class ExportCommand extends AbstractCustomVocabularyCommand
{

    public function __construct()
    {
        parent::__construct('custom-vocabulary:export', 'Export a custom vocabulary');
        $this->argument('<identifier>', 'Custom vocabulary ID or label');
        $this->argument('[filename]', 'Export to file');
        // No class constant as default: CustomVocabImportExport only loads once Omeka has booted
        $this->option(
            '--item-set-property',
            'Property identifying the item set of an Items vocabulary (default: dcterms:identifier)',
            'strval'
        );
    }

    public function execute(
        string $identifier, ?string $filename = null, ?string $itemSetProperty = null
    ): void {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();
        $api = $omekaInstance->getApi();

        // Get vocabulary
        $existingVocabulary = $this->requireCustomVocabulary($identifier, $api);

        // Get the actual ID of the vocabulary
        $vocabularyId = $existingVocabulary->id();

        // Export
        $importExport = new CustomVocabImportExport($api);
        $exportContent = $importExport->getExport($vocabularyId, $itemSetProperty);
        if ($exportContent === false) {
            throw new Exception("Custom vocabulary '{$existingVocabulary->label()}' cannot be exported.");
        }

        // Extract filename from headers or generate one
        if (!$filename) {
            $this->outputFormatted($exportContent);
        } else {
            // Write to file
            $exportJson = json_encode($exportContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($filename, $exportJson.PHP_EOL) === false) {
                throw new Exception("Failed to write custom vocabulary to file '{$filename}'.");
            }
            $this->ok("Custom vocabulary '{$existingVocabulary->label()}' exported to '{$filename}'.", true);
        }
    }
}
