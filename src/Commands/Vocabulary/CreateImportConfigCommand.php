<?php
namespace OSC\Commands\Vocabulary;

use Exception;
use OSC\Helper\VocabularyConfig;

class CreateImportConfigCommand extends AbstractVocabularyCommand
{
    use VocabularyImporterTrait;
    public function __construct()
    {
        parent::__construct('vocabulary:create-import-config', 'Create a config file for the import command');

        $this->registerVocabularyImporterOptions($this);
        $this
            ->option('--output', 'Output the import configuration to a file')
            ->usage(
                'vocabulary:create-import-config  --source "https://schema.org/version/latest/schemaorg-current-https.rdf" --namespace-uri="https://schema.org/" --prefix="schema" --label="schema" --output ./schema-dot-org.json<eol/>'
            );
    }

    public function execute($output): void {
        // prepare config values
        $args = array_filter($this->values(), function($value) {
            return $value !== null;
        });

        // Validate and normalise into the canonical config shape (emits `source`, drops extras)
        $config = VocabularyConfig::fromArray($args);
        $json = json_encode($config->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($output) {
            if (file_put_contents($output, $json . "\n") === false) {
                throw new Exception("Failed to write to file: {$output}");
            };
            $this->ok("Config file written to '{$output}'.", true);
        } else {
            $this->echo($json, true);
        }
    }
}