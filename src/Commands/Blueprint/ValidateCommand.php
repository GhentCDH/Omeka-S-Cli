<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\BlueprintValidator;

class ValidateCommand extends AbstractBlueprintCommand
{
    public function __construct()
    {
        parent::__construct('blueprint:validate', 'Validate a blueprint (or a partial asset list) against the schema');
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->option(
            '--as',
            'Validate a standalone partial list instead of a full blueprint '
            . '(modules, themes, vocabularies, resourceTemplates, settings, users, items, itemSets)'
        );
        $this->optionJson();
        $this->usage(
            'blueprint:validate ./site.blueprint.jsonc<eol/>'
            . 'blueprint:validate ./modules.jsonc --as modules<eol/>'
            . 'blueprint:validate https://example.org/site.blueprint.json --json'
        );
    }

    public function execute(string $source, ?string $as = null, ?bool $json = false): void
    {
        $loader = new BlueprintLoader();
        $validator = new BlueprintValidator();

        if ($as !== null) {
            $data = $loader->loadPartial($source, $as);
            $errors = $validator->validatePartial($data, $as);
        } else {
            $data = $loader->load($source);
            $errors = $validator->validateBlueprint($data->toArray());
        }

        if ($json) {
            $this->outputFormatted(['valid' => empty($errors), 'errors' => array_values($errors)], 'json');
        }

        if (empty($errors)) {
            if (!$json) {
                $this->ok("Blueprint '{$source}' is valid.", true);
            }
            return;
        }

        if (!$json) {
            $this->error("Blueprint '{$source}' is invalid:", true);
            foreach ($errors as $error) {
                $this->error("  - {$error}", true);
            }
        }

        // non-zero exit for CI; the message goes to stderr, keeping any --json output on stdout clean
        throw new Exception('Blueprint validation failed with ' . count($errors) . ' error(s).');
    }
}
