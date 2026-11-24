<?php
namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use OSC\Helper\Config\VocabularyConfig;
use OSC\Source\CliSourceResolver;
use OSC\Source\Document;
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
                '<bold>  $0 vocabulary:import</end> <comment>--source "http://www.w3.org/TR/skos-reference/skos.rdf" --namespace-uri "http://www.w3.org/2004/02/skos/core#" --prefix skos --label SKOS --format rdfxml</end><eol/>'
                . '<bold>  $0 vocabulary:import</end> <comment>--source ./vocab.ttl --namespace-uri "http://example.com/" --prefix ex --label "Example" --format turtle</end><eol/>'
                . '<bold>  $0 vocabulary:import</end> <comment>--config ./vocab.json</end><eol/>'
                . '<eol/><bold>Note:</end> To import from a repository, use <comment>vocabulary:import-from-repo</end><eol/>'
            );
        $this->optionRoot();
    }

    /**
     * @param string|VocabularyConfig|null $config A config file (path, URL, gh:), or a config built by
     *                                             blueprint:deploy, its source already resolved
     * @param string|null                  $root   Directory the config file's source must stay inside
     */
    public function execute(string|VocabularyConfig|null $config = null, ?bool $update = false, ?string $root = null): void
    {
        $this->ensureOmekaInstance();

        if ($config instanceof VocabularyConfig) {
            $vocabulary = $config;
        } elseif ($config !== null) {
            // a config file: its source is written in a file, so the file rules apply (relative to the
            // config, inside its folder or --root, no absolute path)
            $document = Document::open(CliSourceResolver::file($config, $this->getCwd()), $root);
            try {
                $this->info("Load configuration from {$document->source->display()} ... ");
                $data = ResourceFetcher::fetchJson($document->source);
                $this->info("done");
            } finally {
                $this->info("", true);
            }
            if (!is_array($data)) {
                throw new InvalidArgumentException("Invalid configuration file: {$document->source->display()}");
            }
            $vocabulary = VocabularyConfig::fromArray($data);
            $vocabulary = $vocabulary->withSource($document->resolve($vocabulary->source())->value());
        } else {
            // the command options: --source is typed on the command line
            $vocabulary = VocabularyConfig::fromArray(array_filter($this->values(), fn($value) => $value !== null));
            $vocabulary = $vocabulary->withSource(CliSourceResolver::file($vocabulary->source(), $this->getCwd())->value());
        }

        $this->importVocabulary($this->importerOptionsOf($vocabulary), $update);
    }
}
