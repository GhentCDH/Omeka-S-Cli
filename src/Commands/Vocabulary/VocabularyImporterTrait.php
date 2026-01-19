<?php

namespace OSC\Commands\Vocabulary;


use Ahc\Cli\Exception\InvalidArgumentException;
use Ahc\Cli\Input\Command;

trait VocabularyImporterTrait
{

    protected function registerVocabularyImporterOptions(Command $command): Command
    {
        return $command
            ->option('--url', 'Vocabulary URL')
            ->option('--file', 'Path to Vocabulary file')
            ->option('--label', 'Label for the vocabulary (required)')
            ->option('--comment', 'Comment/description for the vocabulary')
            ->option('--namespace-uri', 'Namespace URI for the vocabulary (required)')
            ->option('--prefix', 'Namespace prefix for the vocabulary (required)')
            ->option('--format', 'Format of the vocabulary (rdfxml, turtle, ntriples, or auto)', [$this, 'filterVocabularyFormat'], 'auto')
            ->option('--lang', 'Preferred language for labels and comments (e.g., en, fr)')
            ->option('--label-property', 'RDF property for labels')
            ->option('--comment-property', 'RDF property for comments');
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

}