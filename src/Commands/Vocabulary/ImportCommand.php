<?php
namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use OSC\Helper\ResourceFetcher;

class ImportCommand extends AbstractVocabularyCommand
{
    use VocabularyImporterTrait;

    public function __construct()
    {
        parent::__construct('vocabulary:import', 'Import a vocabulary');

        $this->registerVocabularyImporterOptions($this);

        $this
            ->option('--config', 'Path or URL to a JSON import configuration file (instead of the options above)')
            ->option('-u --update', 'Update existing vocabulary (if it exists)', 'boolval', false)
            ->usage(
                '<bold>  $0 vocabulary:import</end> <comment>--url "http://www.w3.org/TR/skos-reference/skos.rdf" --namespace-uri "http://www.w3.org/2004/02/skos/core#" --prefix skos --label SKOS --format rdfxml</end><eol/>'
                . '<bold>  $0 vocabulary:import</end> <comment>--file ./vocab.ttl --namespace-uri "http://example.com/" --prefix ex --label "Example" --format turtle</end><eol/>'
                . '<bold>  $0 vocabulary:import</end> <comment>--config ./vocab.json</end><eol/>'
                . '<eol/><bold>Note:</end> To import from a repository, use <comment>vocabulary:import-from-repo</end><eol/>'
            );
    }

    public function execute(?string $config = null, ?bool $update = false): void
    {
        $this->ensureOmekaInstance();

        if ($config !== null) {
            // Import parameters come from a JSON config file (path or URL)
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

            $importerOptions = $this->prepareImporterOptions($importerConfig);
        } else {
            // Import parameters come from the command options
            $args = array_filter($this->values(), fn($value) => $value !== null);
            $importerOptions = $this->prepareImporterOptions($args);
        }

        $this->importVocabulary($importerOptions, $update);
    }
}
