<?php
namespace OSC\Commands\CustomVocabulary;


class DeleteCommand extends AbstractCustomVocabularyCommand
{

    public function __construct()
    {
        parent::__construct('custom-vocabulary:delete', 'Delete a custom vocabulary');
        $this->argument('<identifier>', 'Custom vocabulary ID or label');
        $this->option('-f --force', 'Force delete');
        $this->optionIgnoreNotFound('custom vocabulary');
    }

    public function execute(string $identifier, ?bool $force, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();

        // Get vocabulary
        $existingCustomVocabulary = $this->requireCustomVocabulary($identifier, $api, self::SEARCH_BY_BOTH, $ignoreNotFound);
        if (!$existingCustomVocabulary) {
            return;
        }

        // Delete resource template
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('custom_vocabs', [ 'id' => $existingCustomVocabulary->id() ]);

        $this->ok("Custom vocabulary '{$existingCustomVocabulary->label()}' deleted.", true);
    }
}
