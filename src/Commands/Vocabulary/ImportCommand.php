<?php
namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;
use Omeka\Stdlib\RdfImporter;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\WarningException;

class ImportCommand extends AbstractCommand
{
    use VocabularyImporterTrait;

    public function __construct()
    {
        parent::__construct('vocabulary:import', 'Import a vocabulary directly by providing all required parameters');

        $this->registerVocabularyImporterOptions($this);

        $this
            ->option('-u --update', 'Update existing vocabulary (if it exists)', 'boolval', false)
            ->usage(
                '<bold>  $0 vocabulary:import</end> <comment>--url "http://www.w3.org/TR/skos-reference/skos.rdf" --namespace-uri "http://www.w3.org/2004/02/skos/core#" --prefix schema --label SKOS --format rdfxml</end><eol/>'
                . '<bold>  $0 vocabulary:import</end> <comment>--file ./vocab.ttl --namespace-uri "http://example.com/" --prefix ex --label "Example" --format turtle</end><eol/>'
                . '<eol/><bold>Note:</end> For importing from config files, use <comment>vocabulary:import-from-config</end><eol/>'
                . '<bold>Note:</end> For importing from repositories, use <comment>vocabulary:import-from-repo</end><eol/>'
            );
    }

    public function execute(?bool $update = false): void
    {
        $args = array_filter($this->values(), function($value) {
            return $value !== null;
        });

        // Prepare importer options from direct parameters
        $importerOptions = $this->prepareImporterOptions($args);
        $this->importVocabulary($importerOptions, $update);
    }
}