<?php
namespace OSC\Commands\CustomVocabulary;

use CustomVocab\Stdlib\ImportExport;
use Exception;
use InvalidArgumentException;

class ExportCommand extends AbstractCustomVocabularyCommand
{

    public function __construct()
    {
        parent::__construct('custom-vocabulary:export', 'Export a custom vocabulary');
        $this->argument('<identifier>', 'Custom vocabulary ID or label');
        $this->argument('[filename]', 'Export to file');
    }

    public function execute(string $identifier, ?string $filename = null): void
    {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();
        $api = $omekaInstance->getApi();

        // Find resource template by ID or label
        $existingVocabulary = $this->findCustomVocabulary($identifier, $api);
        if (!$existingVocabulary) {
            throw new InvalidArgumentException("Custom vocabulary '{$identifier}' not found by ID or label.");
        }

        // Get the actual ID of the vocabulary
        $vocabularyId = $existingVocabulary->id();

        // Export
        $importExport = new ImportExport($api);
        $exportContent = $importExport->getExport($vocabularyId);

        // Extract filename from headers or generate one
        if (!$filename) {
            $this->outputFormatted($exportContent);
        } else {
            // Write to file
            if (file_put_contents($filename, $exportContent.PHP_EOL) === false) {
                throw new Exception("Failed to write custom vocabulary to file '{$filename}'.");
            }
            $this->ok("Successfully exported custom vocabulary '{$existingVocabulary->label()}' to '{$filename}'.", true);
        }
    }
}