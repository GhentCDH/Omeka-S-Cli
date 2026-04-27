<?php
namespace OSC\Commands\CustomVocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use CustomVocab\Stdlib\ImportExport;
use Exception;
use Omeka\Stdlib\RdfImporter;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Vocabulary\VocabularyImporterTrait;
use OSC\Exceptions\WarningException;
use OSC\Helper\ResourceFetcher;

class ImportCommand extends AbstractCustomVocabularyCommand
{
    use VocabularyImporterTrait;
    public function __construct()
    {
        parent::__construct('custom-vocabulary:vocabulary', 'Import a custom vocabulary');
        $this->argument('<source>', 'File or url to import from');
        $this->argument('[identifier]', 'Custom vocabulary ID or label (required for update)');
        $this->option('-l --label', 'Set or override the resource template label');
        $this->option('--update', 'Update existing resource template', 'boolval', false);
    }

    public function execute(
        string $source, ?string $identifier = null, ?string $label = null, ?bool $update = false
    ): void {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();

        $api = $omekaInstance->getApi();

        $importExport = new ImportExport($api);

        // read file content and check if valid json
        $customVocabularyData = ResourceFetcher::fetchJson($source);

        // verify the file
        if (!$importExport->isValidImport($customVocabularyData)) {
            throw new Exception("Invalid custom vocabulary import source: {$source}.");
        }

        // Determine the label to use (priority: --label option, then from file)
        $label = $label ?? $customVocabularyData['o:label'] ?? null;
        $customVocabularyData['o:label'] = $label;

        // Check if we need to find an existing custom vocabulary
        if ($identifier) {
            $update = true;
            // Find existing custom vocabulary by identifier (ID or label)
            $existingCustomVocabulary = $this->findCustomVocabulary($identifier, $api);
            if (!$existingCustomVocabulary) {
                throw new InvalidArgumentException("Custom vocabulary not found by ID or label: '{$identifier}'.");
            }
        } else {
            // Check if a custom vocabulary with the same label already exists
            $existingCustomVocabulary = $this->findCustomVocabulary($label, $api);
            if ($existingCustomVocabulary) {
                if (!$update) {
                    throw new WarningException("The custom vocabulary with label '{$label}' already exists. Use --update to force update.");
                }
            }
        }

        if ($existingCustomVocabulary && $update) {
            $currentLabel = $existingCustomVocabulary->label();
            $response = $api->update('custom_vocabs', $existingCustomVocabulary->id(), $customVocabularyData)->getContent();
            if (!$response) {
                throw new Exception("An error occurred while updating the custom vocabulary '{$currentLabel}'.");
            }
            $this->ok("Successfully updated custom vocabulary '{$currentLabel}'.", true);
        } else {
            $response = $api->create('custom_vocabs', $customVocabularyData)->getContent();
            if (!$response) {
                throw new Exception("An error occurred while creating the custom vocabulary '{$label}'.");
            }
            $this->ok("Successfully created custom vocabulary '{$label}'.", true);
        }

    }
}