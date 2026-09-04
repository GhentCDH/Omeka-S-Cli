<?php
namespace OSC\Commands\Vocabulary;

use OSC\Commands\AbstractCommand;
use OSC\Manager\Vocabulary\Manager as VocabularyRepositoryManager;

class SearchCommand extends AbstractCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('vocabulary:search', 'Search/list available vocabularies');

        $this
            ->argument('[query]', 'Part of the vocabulary id, name or description')
            ->option('-r --repository [repositoryId]', 'Filter by repository id', 'strval')
            ->option('--refresh', 'Refresh the repository data', 'boolval', false);

        $this->optionJson();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $extended = false, ?string $repositoryId = null): void
    {
        $format = $this->getOutputFormat('table');

        $manager = VocabularyRepositoryManager::getInstance();

        // refresh repositories?
        if ($this->values()['refresh'] ?? false) {
            $this->info("Refreshing vocabulary repositories...");
            $manager->refreshRepositories();
        }

        // fetch results
        if ($query) {
            $vocabularyResults = $manager->search($query, $repositoryId);
        } else {
            $vocabularyResults = $manager->list($repositoryId);
        }

        $output = $this->formatVocabularyResults($vocabularyResults, $extended);
        if (!$output) {
            $this->warn("No vocabularies found for query '{$query}'", true);
        }
        $this->outputFormatted($output, $format);
    }
}
