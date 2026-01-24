<?php

namespace OSC\Commands\Vocabulary;


use Ahc\Cli\Exception\InvalidArgumentException;
use Ahc\Cli\Input\Command;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\RdfImporter;
use OSC\Exceptions\WarningException;

trait VocabularyImporterTrait
{

    protected function registerVocabularyImporterOptions(Command $command): Command
    {
        return $command
            ->option('--url', 'Vocabulary URL')
            ->option('--file', 'Path to Vocabulary file')
            ->option('-l --label', 'Label for the vocabulary (required)')
            ->option('-c --comment', 'Comment/description for the vocabulary')
            ->option('-n --namespace-uri', 'Namespace URI for the vocabulary (required)')
            ->option('-p --prefix', 'Namespace prefix for the vocabulary (required)')
            ->option('-f --format', 'Format of the vocabulary (rdfxml, turtle, ntriples, or auto)', [$this, 'filterVocabularyFormat'], 'auto')
            ->option('-l --lang', 'Preferred language for labels and comments (e.g., en, fr)')
            ->option('-lp --label-property', 'RDF property for labels')
            ->option('-cp --comment-property', 'RDF property for comments');
    }

    public function filterVocabularyFormat(?string $format): ?string
    {
        $validFormats = ['auto', 'jsonld', 'rdfxml', 'turtle', 'ntriples'];
        if ($format === null || in_array(strtolower($format), $validFormats, true)) {
            return $format ? strtolower($format) : null;
        }
        throw new InvalidArgumentException(
            "Invalid format '{$format}'. Valid formats are: " . implode(', ', $validFormats) . '.'
        );
    }

    protected function prepareImporterOptions(array $values): array
    {

        $options = [];

        // check source (file/url)
        if (count(array_intersect_key($values, array_flip(['file', 'url']))) === 0) {
            throw new InvalidArgumentException('You must specify either a file or a url for the vocabulary.');
        }
        if (count(array_intersect_key($values, array_flip(['file', 'url']))) !== 1) {
            throw new InvalidArgumentException('You must specify either a file or a url for the vocabulary, but not both.');
        }

        $file = $values['file'] ?? null;
        if ($file) {
            $strategy = 'file';
            $options['file'] = $file;
        }

        $url = $values['url'] ?? null;
        if ($url) {
            $strategy = 'url';
            $options['url'] = $url;
        }

        $label = $values['label'] ?? null;
        if (!$label) {
            throw new InvalidArgumentException('No label set for the vocabulary.');
        }

        $namespaceUri = $values['namespaceUri'] ?? null;
        $prefix = $values['prefix'] ?? null;
        if (!$namespaceUri || !$prefix) {
            throw new InvalidArgumentException('A vocabulary must have a namespace uri and a prefix.');
        }

        $vocabulary = [
            'o:namespace_uri' => $namespaceUri,
            'o:prefix' => $prefix,
            'o:label' => $label,
        ];
        if ($values['comment'] ?? null) {
            $vocabulary['o:comment'] = $values['comment'];
        }

        // Set format (auto = guess)
        $format = $values['format'] ?? null;
        $options['format'] = $format && $format !== 'auto' ? $format : 'guess';

        // Set optional properties
        $options['lang'] = $values['lang'] ?? null;
        $options['label_property'] = $values['labelProperty'] ?? null;
        $options['comment_property'] = $values['commentProperty'] ?? null;

        return [
            'strategy' => $strategy,
            'vocabulary' => array_filter($vocabulary, fn($v) => $v !== null),
            'options' => array_filter($options, fn($v) => $v !== null),
        ];
    }

    /**
     * Validate that the resource exists and is accessible
     */
    protected function validateImporterOptions(array $options): void
    {

        if ($options['strategy'] === 'file') {
            $file = $options['options']['file'];
            // Check if file exists and is readable
            if (!file_exists($file)) {
                throw new InvalidArgumentException("File not found: {$file}");
            }
            if (!is_readable($file)) {
                throw new InvalidArgumentException("File is not readable: {$file}");
            }
        } else {
            $url = $options['options']['url'];
            // For URLs, we could optionally validate with a HEAD request
            // but we'll let the RDF importer handle URL validation
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException("Invalid URL: {$url}");
            }
        }
    }

    /**
     * Find existing vocabulary by namespace URI
     *
     * @param string $namespaceUri The namespace URI to search for
     * @return object|null The vocabulary object or null if not found
     */
    protected function findExistingVocabulary(string $namespaceUri): ?object
    {
        $result = $this->getOmekaInstance()->getApi()->search('vocabularies', [
            'namespace_uri' => $namespaceUri
        ])->getContent();

        return count($result) > 0 ? $result[0] : null;
    }

    /**
     * Import or update a vocabulary based on importer options
     *
     * @param array $importerOptions Prepared importer options from prepareImporterOptions()
     * @param bool $update Whether to update existing vocabulary (if it exists)
     * @throws WarningException If vocabulary exists and update is false
     * @throws \Exception If import/update fails
     */
    protected function importVocabulary(array $importerOptions, bool $update = false): void
    {
        // Validate options
        $this->validateImporterOptions($importerOptions);

        // Check if vocabulary already exists
        $namespaceUri = $importerOptions['vocabulary']['o:namespace_uri'];
        $existingVocabulary = $this->findExistingVocabulary($namespaceUri);

        if ($existingVocabulary) {
            $this->info("Found existing vocabulary with matching namespace URI.", true);
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
                $this->info("Updating existing vocabulary from {$source} ... ");
                $diff = $rdfImporter->getDiff($strategy, $namespaceUri, $importerOptions['options']);
                $rdfImporter->update($existingVocabulary->id(), $diff);
                $this->info('done', true);
            } catch (ValidationException $e) {
                $this->io()->eol();
                throw new \Exception("Could not update vocabulary (" . $e->getMessage() . ").");
            }

            $this->ok("Successfully updated vocabulary '{$label}'.", true);
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
            } catch (\Exception $e) {
                $this->io()->eol();
                throw new \Exception("Could not import vocabulary ({$e->getMessage()})");
            }

            $this->ok("Successfully created vocabulary '{$label}'.", true);
        }
    }

}