<?php
namespace OSC\Commands\CustomVocabulary;

use Ahc\Cli\Exception\InvalidArgumentException;

class DeleteCommand extends AbstractCustomVocabularyCommand
{

    public function __construct()
    {
        parent::__construct('custom-vocabulary:delete', 'Delete a custom vocabulary');
        $this->argument('<identifier>', 'Custom vocabulary ID or label');
        $this->option('-f --force', 'Force delete');
    }

    public function execute(string $identifier, ?bool $force): void
    {
        $api = $this->getOmekaInstance()->getApi();

        // Find resource template by ID or label
        $existingCustomVocabulary = $this->findCustomVocabulary($identifier, $api);
        if (!$existingCustomVocabulary) {
            throw new InvalidArgumentException("Custom vocabulary not found by ID or label: '{$identifier}'.");
        }

        // Delete resource template
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('custom_vocabs', [ 'id' => $existingCustomVocabulary->id() ]);

        $this->ok("Custom vocabulary '{$existingCustomVocabulary->label()}' successfully deleted.", true);
    }
}
