<?php

namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Helper\ResourceFetcher;

class ImportFromConfigCommand extends AbstractCommand
{
    use VocabularyImporterTrait;

    public function __construct()
    {
        parent::__construct('vocabulary:import-from-config', 'Import a vocabulary from a configuration file');

        $this
            ->argument('<config>', 'Path or URL to vocabulary import configuration file (JSON)')
            ->option('-u --update', 'Update existing vocabulary (if it exists)', 'boolval', false)
            ->usage(
                '<bold>  $0 vocabulary:import-from-config</end> <comment>./config.json</end><eol/>'
                . '<bold>  $0 vocabulary:import-from-config</end> <comment>https://example.com/vocab-config.json</end> <comment>--update</end><eol/>'
            );
    }

    public function execute(string $config, bool $update = false): void
    {
        $this->ensureOmekaInstance();

        // Fetch and parse config file
        try {
            $this->io()->info("Load configuration from '{$config}' ... ");
            $importerConfig = ResourceFetcher::fetchJson($config);
            $this->info("done");
        } finally {
            $this->info("", true);
        }

        if (!is_array($importerConfig)) {
            throw new InvalidArgumentException("Invalid configuration file: {$config}");
        }

        // Prepare importer options
        $importerOptions = $this->prepareImporterOptions($importerConfig);
        $this->validateImporterOptions($importerOptions);

        // Get vocabulary info
        $vocabulary = $importerOptions['vocabulary'];
        $this->info("Importing vocabulary '{$vocabulary['o:label']}' (ns: {$vocabulary['o:namespace_uri']}, prefix: {$vocabulary['o:prefix']}).", true);
// (ns: {$vocabularyItem->getNamespaceUri()}, prefix: {$vocabularyItem->getPrefix()})
        // Import or update vocabulary using trait method
        $this->importVocabulary($importerOptions, $update);
    }
}

