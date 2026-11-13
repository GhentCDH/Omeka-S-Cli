<?php

namespace OSC\Commands;

use OSC\Addon\AddonType;
use OSC\Exceptions\WarningException;
use Throwable;

/** The bulk mode (--all and friends) of the module and theme commands. */
trait AddonBulkOperationTrait
{
    /**
     * Run an operation over a list of add-ons (modules by default), reporting on each one.
     *
     * The add-ons the operation resolved to are listed up front: the command was asked to work
     * out the set itself, so the caller can not know what it covers until it is shown. Under
     * --dry-run that listing is all that happens.
     *
     * An add-on that reports a warning does not fail the batch; any other error does, but the
     * remaining ones are still processed.
     *
     * @param string[]  $ids       The add-ons to process, in order
     * @param callable  $operation Receives the add-on id
     * @param string    $verb      Present tense verb, e.g. "Uninstalling"
     * @param string    $doneVerb  Past tense verb, e.g. "uninstalled"
     * @param AddonType $type      What the ids are, for the messages
     *
     * @return bool Whether every add-on was handled successfully
     */
    protected function runBulkOperation(array $ids, callable $operation, string $verb, string $doneVerb, AddonType $type = AddonType::Module): bool
    {
        $success = true;
        $noun = $type->value;

        if ($this->isDryRun()) {
            $planned = sprintf(
                '%d %s(s) would be %s: %s.',
                count($ids),
                $noun,
                $doneVerb,
                implode(', ', $ids)
            );
            $this->reportDryRun($planned);
            return true;
        }

        $summary = sprintf(
            '%s %d %s(s): %s.',
            $verb,
            count($ids),
            $noun,
            implode(', ', $ids)
        );
        $this->info($summary, true);

        foreach ($ids as $id) {
            try {
                $this->info("{$verb} {$noun}: {$id}", true);
                $operation($id);
                $this->ok(ucfirst($noun) . " '{$id}' {$doneVerb}.", true);
            } catch (WarningException $e) {
                $this->warn($e->getMessage(), true);
            } catch (Throwable $e) {
                $this->error($e->getMessage(), true);
                $success = false;
            }
        }

        return $success;
    }
}
