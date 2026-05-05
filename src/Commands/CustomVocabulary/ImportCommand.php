<?php
namespace OSC\Commands\CustomVocabulary;

use CustomVocab\Stdlib\ImportExport;
use Exception;
use OSC\Exceptions\WarningException;
use OSC\Helper\ResourceFetcher;

class ImportCommand extends AbstractCustomVocabularyCommand
{
    public function __construct()
    {
        parent::__construct('custom-vocabulary:import', 'Import a custom vocabulary');
        $this->argument('<source>', 'File or url to import from');
        $this->argument('[identifier]', 'Custom vocabulary ID or label (required for update)');
        $this->option('-l --label', 'Set or override the custom vocabulary label');
        $this->option('--update', 'Update existing custom vocabulary', 'boolval', false);
//        $this->optionJson();
    }

    public function execute(
        string $source, ?string $identifier = null, ?string $label = null, ?bool $update = false
    ): void {
        // Get Omeka instance and service manager
        $api = $this->getOmekaInstance()->getApi();

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
        $existingCustomVocabulary = null;
        if ($identifier) {
            $update = true;
            $existingCustomVocabulary = $this->getCustomVocabulary($identifier, $api);
        } else {
            // Check if a custom vocabulary with the same label already exists
            $existingCustomVocabulary = $this->findCustomVocabulary($label, $api, static::SEARCH_BY_LABEL);
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
//            if ($this->getOutputFormat() === 'json') {
//                $this->outputFormatted([
//                    'id' => $existingCustomVocabulary->id(),
//                    'label' => $existingCustomVocabulary->label(),
//                    'message' => "Successfully updated custom vocabulary '{$currentLabel}' (ID: {$response->id()})."
//                ]);
//            }
        } else {
            $response = $api->create('custom_vocabs', $customVocabularyData)->getContent();
            if (!$response) {
                throw new Exception("An error occurred while creating the custom vocabulary '{$label}'.");
            }
            $this->ok("Successfully created custom vocabulary '{$label}' (ID: {$response->id()}).", true);
//            if ($this->getOutputFormat() === 'json') {
//                $this->outputFormatted([
//                    'id' => $response->id(),
//                    'label' => $response->label(),
//                    'message' => "Successfully updated custom vocabulary '{$response->label()}' (ID: {$response->id()})."
//                ]);
//            }
        }

    }
}