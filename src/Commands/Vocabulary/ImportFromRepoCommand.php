<?php

namespace OSC\Commands\Vocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Manager\Vocabulary\Manager as VocabularyRepositoryManager;

class ImportFromRepoCommand extends AbstractCommand
{
    use VocabularyImporterTrait;

    public function __construct()
    {
        parent::__construct('vocabulary:import-from-repo', 'Import a vocabulary from a repository');

        $this
            ->argument('<identifier>', 'Vocabulary identifier')
            ->option('-p --prefix', 'Match vocabulary by prefix', 'boolval', false)
            ->option('-n --namespace-uri', 'Match vocabulary by namespace uri', 'boolval', false)
            ->option('-r --repository-id [repositoryId]', 'Filter by repository', 'strval')

            ->option('-u --update', 'Update existing vocabulary (if it exists)', 'boolval', false)
            ->usage(
                '<bold>  $0 vocabulary:import-from-repo</end> <comment>lov:event</end><eol/>'
                . '<bold>  $0 vocabulary:import-from-repo</end> <comment>--prefix event</end><eol/>'
            );
    }

    public function execute(string $identifier, ?string $repositoryId = null, bool $namespaceUri = false, bool $prefix = false, bool $update = false): void
    {
        $this->ensureOmekaInstance();

        // Init repository search arguments
        $args = [
            'type' => null,
        ];
        if ($repositoryId) {
            $args['repositoryId'] = $repositoryId;
        }
        if ($namespaceUri) {
            $args['type'] = 'namespace-uri';
        }
        if ($prefix) {
            $args['type'] = 'prefix';
        }

        $repositoryManager = VocabularyRepositoryManager::getInstance();

        // Check repository
        if ($repositoryId) {
            try {
                $repo = $repositoryManager->getRepository($repositoryId);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Repository '{$repositoryId}' not found");
            }
        }

        // Try to resolve vocabulary by different identifier types
        $vocabularyResult = $repositoryManager->find($identifier, ...$args);
        if (!$vocabularyResult) {
            throw new InvalidArgumentException("No vocabulary found matching '{$identifier}'.");
        }

        $vocabularyItem = $vocabularyResult->getItem();
        $this->info("Resolved to vocabulary '{$vocabularyItem->getName()}' (ns: {$vocabularyItem->getNamespaceUri()}, prefix: {$vocabularyItem->getPrefix()})", true);

        // Prepare importer configuration
        $importerConfig = [
            'url' => $vocabularyItem->getUrl(),
            'label' => $vocabularyItem->getName(),
            'namespaceUri' => $vocabularyItem->getNamespaceUri(),
            'prefix' => $vocabularyItem->getPrefix(),
            'format' => $vocabularyItem->getFormat(),
            'comment' => $vocabularyItem->getComment(),
        ];

        // Prepare importer options
        $importerOptions = $this->prepareImporterOptions($importerConfig);
        $this->validateImporterOptions($importerOptions);

        // Import or update vocabulary using trait method
        $this->importVocabulary($importerOptions, $update);
    }
}

