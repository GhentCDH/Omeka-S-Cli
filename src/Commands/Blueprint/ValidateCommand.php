<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\SelfContainedCheck;

class ValidateCommand extends AbstractBlueprintCommand
{
    public function __construct()
    {
        parent::__construct('blueprint:validate', 'Validate a blueprint (or a partial asset list) against the schema');
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->option(
            '--as',
            'Validate a standalone partial list instead of a full blueprint '
            . '(modules, themes, files, vocabularies, resourceTemplates, settings, users, sites, items, itemSets)'
        );
        $this->optionJson();
        $this->option('--refresh', 'Re-download the canonical blueprint schema', 'boolval', false);
        $this->option('--root', 'Directory local blueprint references must stay inside (default: the blueprint\'s directory)');
        $this->option(
            '--self-contained',
            'Also require every source to be a relative path inside the blueprint (no catalog, git or remote references)',
            'boolval',
            false
        );
        $this->usage(
            'blueprint:validate ./site.blueprint.jsonc<eol/>'
            . 'blueprint:validate ./modules.jsonc --as modules<eol/>'
            . 'blueprint:validate ./site.blueprint.jsonc --refresh<eol/>'
            . 'blueprint:validate ./bundle/blueprint.json --self-contained<eol/>'
            . 'blueprint:validate https://example.org/site.blueprint.json --json'
        );
    }

    public function execute(
        string $source,
        ?string $as = null,
        ?bool $json = false,
        ?bool $refresh = false,
        ?string $root = null,
        ?bool $selfContained = false,
    ): void {
        $selfContained = (bool) $selfContained;
        $loader = new BlueprintLoader(null, $root, $selfContained);
        $validator = $this->validator($selfContained);

        if ($refresh) {
            $this->info('Refreshing the blueprint schema from the shared repository...', true);
            $validator->refresh();
        }

        if ($as !== null) {
            $data = $loader->loadPartial($source, $as);
            $errors = array_merge($loader->takeViolations(), $validator->validatePartial($data, $as));
        } else {
            $data = $loader->load($source);
            $violations = $selfContained
                ? array_merge($loader->takeViolations(), SelfContainedCheck::violations($data, true))
                : [];
            $errors = array_merge($violations, $validator->validateBlueprint($data->toArray()));
        }

        foreach ($loader->takeWarnings() as $warning) {
            $this->warn("  {$warning}", true);
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
