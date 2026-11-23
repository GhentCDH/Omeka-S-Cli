<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\BlueprintValidator;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Offline;

abstract class AbstractBlueprintCommand extends AbstractCommand
{
    /**
     * The schema validator. A self-contained check, and offline mode, use the copy shipped with the
     * CLI, so they never need the network; otherwise the shared schema is fetched (with the bundled
     * copy as fallback).
     */
    protected function validator(bool $selfContained): BlueprintValidator
    {
        return $selfContained || Offline::isEnabled()
            ? new BlueprintValidator(BlueprintValidator::bundledSchemaPath())
            : new BlueprintValidator();
    }

    /**
     * Load a blueprint to act on, and refuse it when it is invalid (or, with $selfContained, refers
     * to a source outside it).
     *
     * @param string $action          The verb for the refusal message (deploy, download)
     * @param bool $coreWillInstall The core is downloaded from the blueprint (its release ZIP must
     *                              then be inside it, for a self-contained blueprint)
     * @param bool $quiet           Don't report loading the blueprint and its warnings (a later stage
     *                              of a multi-process deploy, whose first process already did)
     * @throws Exception When the blueprint is invalid or not self-contained
     */
    protected function loadValidBlueprint(
        string $action,
        string $source,
        ?string $root,
        bool $selfContained,
        bool $coreWillInstall,
        bool $quiet = false
    ): Blueprint {
        if (!$quiet) {
            $this->note("Loading blueprint from '{$source}' ...", true);
        }
        // each document is validated as written while it loads: once resolved, its relative sources are
        // absolute paths, which the schema rejects
        $validator = $this->validator($selfContained);
        $loader = new BlueprintLoader(null, $root, $selfContained, $validator);
        $blueprint = $loader->load($source);
        $loaderWarnings = $loader->takeWarnings();
        if (!$quiet) {
            foreach ($loaderWarnings as $warning) {
                $this->warn("  {$warning}", true);
            }
        }
        // the checks the schema cannot express (themes, permissions, item sets) need no resolved source
        $errors = array_merge($loader->takeErrors(), $validator->validateResolved($blueprint->toArray()));
        if (!$quiet) {
            foreach ($validator->takeWarnings() as $warning) {
                $this->warn("  {$warning}", true);
            }
        }
        $this->refuseInvalid($action, $errors);

        if ($selfContained) {
            $violations = array_merge(
                $loader->takeViolations(),
                SelfContainedCheck::violations($blueprint, $coreWillInstall)
            );
            if ($violations) {
                $this->error('Blueprint is not self-contained:', true);
                foreach ($violations as $violation) {
                    $this->error("  - {$violation}", true);
                }
                throw new Exception("Refusing to {$action}: " . count($violations) . ' source(s) are outside the blueprint.');
            }
        }

        return $blueprint;
    }

    /**
     * @param string[] $errors
     * @throws Exception When there are errors (after printing them)
     */
    private function refuseInvalid(string $action, array $errors): void
    {
        if ($errors) {
            $this->error('Blueprint is invalid:', true);
            foreach ($errors as $error) {
                $this->error("  - {$error}", true);
            }
            throw new Exception("Refusing to {$action} an invalid blueprint. Run 'blueprint:validate' for details.");
        }
    }

    /**
     * Parse a comma-separated phase list into a trimmed array.
     *
     * @return string[]
     */
    protected function parsePhases(?string $csv): array
    {
        return $csv ? array_values(array_filter(array_map('trim', explode(',', $csv)))) : [];
    }
}
