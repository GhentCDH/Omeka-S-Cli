<?php
namespace OSC\Commands\Vocabulary;

use OSC\Commands\AbstractCommand;
use OSC\Commands\Vocabulary\FormattersTrait;
use OSC\Manager\Vocabulary\Manager as VocabularyRepositoryManager;

class SearchCommand extends AbstractCommand
{
    use FormattersTrait;

    protected bool $optionExtended = true;
    protected bool $optionJson = true;
    public function __construct()
    {
        parent::__construct('vocabulary:search', 'Search/list available vocabularies');

        $this
            ->argument('[query]', 'Part of the vocabulary id, name or description')
            ->option('-r --repository [repositoryid]', 'Filter by repository', 'strval');

        $this->optionJson();
        $this->optionExtended();
    }

    public function execute(?string $query, ?bool $json = false, ?bool $extended = false, ?string $repository = null): void
    {
        $format = $this->getOutputFormat('table');
        if ($query) {
            $vocabularyResults = VocabularyRepositoryManager::getInstance()->search($query, $repository);
        } else {
            $vocabularyResults = VocabularyRepositoryManager::getInstance()->list($repository);
        }
        $moduleList = $this->formatVocabularyResults($vocabularyResults, $extended);
        if (!$moduleList) {
            $this->warn("No vocabularies found for query '{$query}'", true);
        }
        $this->outputFormatted($moduleList, $format);
    }
}
