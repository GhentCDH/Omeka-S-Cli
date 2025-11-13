<?php
namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use Exception;
use Omeka\Stdlib\RdfImporter;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\WarningException;
use OSC\Helper\ResourceFetcher;

class ImportCommand extends AbstractCommand
{
    use VocabularyImporterTrait;
    public function __construct()
    {
        parent::__construct('vocabulary:import', 'Import a vocabulary into Omeka S using RDF importer');

        $this->registerVocabularyImporterOptions($this);

        $this
            ->option('--config', 'Path or URL to Vocabulary importer config file')
            ->option('-u --update', 'Update existing vocabulary (if it exists)', 'boolval', false)
            ->usage(
                '<eol/>* Import from url:<eol/>'
                . 'vocabulary:import --url "https://schema.org/version/latest/schemaorg-current-https.rdf" --namespace-uri="https://schema.org/" --prefix="schema" --label="schema.org"<eol/>'
                . '<eol/>* Import from config file:<eol/>'
                . 'vocabulary:import --config ./schema-dot-org.json<eol/>'
                . '<eol/>Example config file (schema-dot-org.json):'
                . '<eol/>{'
                . '<eol/>    "url": "https://schema.org/version/latest/schemaorg-current-https.rdf",'
                . '<eol/>    "label": "schema.org",'
                . '<eol/>    "namespaceUri": "https://schema.org/",'
                . '<eol/>    "prefix": "schema",'
                . '<eol/>}'
            );
    }

    public function execute(
        ?bool $update = false
    ): void {
        $args = array_filter($this->values(), function($value) {
            return $value !== null;
        });

        // check source: file/url/config
        if (count(array_intersect_key($args, array_flip(['file', 'url', 'config']))) !== 1) {
            throw new InvalidArgumentException('You must specify either a file, a url or a importer config file for the vocabulary.');
        }

        $configFile = $args['config'] ?? null;
        if ($configFile) {
            $importerConfig = ResourceFetcher::fetchJson($configFile);
            $importerOptions = $this->prepareImporterOptions($importerConfig);
        } else {
            $importerOptions = $this->prepareImporterOptions($args);
        }

        // validate options and set is_checked to true
        $this->validateImporterOptions($importerOptions);
        // $importerOptions['is_checked'] = true;

        // Check if vocabulary already exists
        $namespaceUri = $importerOptions['vocabulary']['o:namespace_uri'];
        $existingVocabulary = $this->findExistingVocabulary($namespaceUri);
        if ($existingVocabulary) {
            $this->io()->info("Found existing vocabulary with namespace URI '{$existingVocabulary->namespaceUri()}'.", true);
        }
        if ($existingVocabulary && !$update) {
            throw new WarningException(
                "Use --update to update the existing vocabulary."
            );
        }

        $label = $importerOptions['vocabulary']['o:label'];
        $strategy = $importerOptions['strategy'];
        $source = $importerOptions['options'][$strategy];

        // Get RDF importer from service manager
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        /** @var RdfImporter $rdfImporter */
        $rdfImporter = $serviceManager->get('Omeka\RdfImporter');

        // Update existing vocabulary?
        if ($existingVocabulary && $update) {

            // Get diff between existing and new vocabulary
            try {
                $this->io()->info("Updating existing vocabulary ... ");
                $diff = $rdfImporter->getDiff($strategy, $namespaceUri, $importerOptions['options']);
                $rdfImporter->update($existingVocabulary->id(), $diff);
                $this->io()->info('done', true);
            } catch (\Omeka\Api\Exception\ValidationException $e) {
                $this->io()->eol();
                throw new Exception("Could not update vocabulary (" . $e->getMessage() . ")");
            }

            $this->io()->ok("Successfully updated vocabulary '{$label}'.", true);
        } else {
            // Import new vocabulary
            try {
                $this->io()->info("Importing vocabulary from {$source} ... ");
                $response = $rdfImporter->import(
                    $importerOptions['strategy'],
                    $importerOptions['vocabulary'],
                    $importerOptions['options']
                );
                $vocabulary = $response->getContent();
                $this->info('done', true);
            } catch (Exception $e) {
                $this->io()->eol();
                throw new Exception("Could not import vocabulary ({$e->getMessage()})");
            }

            $this->io()->ok("Successfully created vocabulary '{$label}'.", true);
        }
    }

    /**
     * Find existing vocabulary by namespace URI
     */
    protected function findExistingVocabulary(string $namespaceUri): ?object
    {
        $result = $this->getOmekaInstance()->getApi()->search('vocabularies', [
            'namespace_uri' => $namespaceUri
        ])->getContent();

        return count($result) > 0 ? $result[0] : null;
    }
}