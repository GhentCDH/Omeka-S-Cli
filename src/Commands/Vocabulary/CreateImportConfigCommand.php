<?php
namespace OSC\Commands\Vocabulary;

use Exception;
use OSC\Commands\AbstractCommand;

class CreateImportConfigCommand extends AbstractCommand
{
    use VocabularyImporterTrait;
    public function __construct()
    {
        parent::__construct('vocabulary:create-import-config', 'Create a config file for the import command');

        $this->registerVocabularyImporterOptions($this);
        $this
            ->option('--output', 'Output the import configuration to a file')
            ->usage(
                'vocabulary:create-import-config  --url "https://schema.org/version/latest/schemaorg-current-https.rdf" --namespace-uri="https://schema.org/" --prefix="schema" --label="schema" --output ./schema-dot-org.json<eol/>'
            );
    }

    public function execute($output): void {
        // prepare config values
        $args = array_filter($this->values(), function($value) {
            return $value !== null;
        });

        // Prepare importer options (used as data validation step)
        $importerOptions = $this->prepareImporterOptions($args);

        // Prepare output
        $configOptions = array_intersect_key($args, array_flip(['file', 'url', 'label', 'namespaceUri', 'prefix', 'comment', 'format', 'lang', 'labelProperty', 'commentProperty']));
        $configOptions = array_filter($configOptions, function($value) {
            return $value !== null;
        });

        if ($output) {
            if (file_put_contents($output, json_encode($configOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
                throw new Exception("Failed to write to file: {$output}");
            };
            $this->ok("Config file written to '{$output}'.", true);
        } else {
            $this->echo(json_encode($configOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
        }
    }
}