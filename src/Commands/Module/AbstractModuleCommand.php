<?php

namespace OSC\Commands\Module;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Argument;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\NotFoundException;
use OSC\Exceptions\WarningException;
use Omeka\Module\Module;
use Throwable;

abstract class AbstractModuleCommand extends AbstractCommand
{
    public function __construct(string $_name, string $_desc = '', bool $_allowUnknown = false, ?App $_app = null)
    {
        parent::__construct($_name, $_desc, $_allowUnknown, $_app);
    }

    public function argumentModuleId(bool $optional = false): self
    {
        $argument = new Argument($optional ? '[module-id]' : '<module-id>', 'Module id', null, fn($raw) => trim($raw));
        $this->register($argument);
        return $this;
    }

    /**
     * Resolve a single module by id, honouring --ignore-not-found.
     *
     * The API already throws for an unknown module; this adds the option to treat that absence as
     * "nothing to do" instead, the same door requireUser() offers for users.
     *
     * @param string $moduleId       Module id
     * @param bool   $ignoreNotFound Report a missing module instead of failing on it
     *
     * @return Module The resolved module (always; absence throws or is reported and aborts)
     *
     * @throws NotFoundException If the module does not exist
     */
    protected function requireModule(string $moduleId, bool $ignoreNotFound = false): Module
    {
        try {
            return $this->getOmekaInstance()->getModuleApi()->getModule($moduleId);
        } catch (NotFoundException $e) {
            $this->skipMissing($e, $ignoreNotFound);
        }
    }

    /**
     * Collect the ids of all modules currently in one of the given states.
     *
     * Ids are returned instead of Module objects because a module object does not survive a
     * reload of the module manager; the bulk loop re-fetches each module when its turn comes.
     *
     * The result is unordered; use OSC\Helper\ModuleDependencyOrder when the order matters.
     *
     * @param string[] $states Omeka module states, see Omeka\Module\Manager::STATE_*
     *
     * @return string[] Module ids
     */
    protected function collectModuleIdsByState(array $states): array
    {
        $moduleIds = [];
        foreach ($this->getOmekaInstance()->getModuleApi()->getModules() as $module) {
            if (in_array($module->getState(), $states, true)) {
                $moduleIds[] = $module->getId();
            }
        }

        return $moduleIds;
    }

    /**
     * Run an operation over a list of modules, reporting on each one.
     *
     * The modules the operation resolved to are listed up front: the command was asked to work
     * out the set itself, so the caller can not know what it covers until it is shown. Under
     * --dry-run that listing is all that happens.
     *
     * A module that reports a warning does not fail the batch; any other error does, but the
     * remaining modules are still processed.
     *
     * @param string[] $moduleIds The modules to process, in order
     * @param callable $operation Receives the module id
     * @param string   $verb      Present tense verb, e.g. "Uninstalling"
     * @param string   $doneVerb  Past tense verb, e.g. "uninstalled"
     *
     * @return bool Whether every module was handled successfully
     */
    protected function runBulkOperation(array $moduleIds, callable $operation, string $verb, string $doneVerb): bool
    {
        $success = true;

        if ($this->isDryRun()) {
            $planned = sprintf(
                '%d module(s) would be %s: %s.',
                count($moduleIds),
                $doneVerb,
                implode(', ', $moduleIds)
            );
            $this->reportDryRun($planned);
            return true;
        }

        $summary = sprintf(
            '%s %d module(s): %s.',
            $verb,
            count($moduleIds),
            implode(', ', $moduleIds)
        );
        $this->info($summary, true);

        foreach ($moduleIds as $moduleId) {
            try {
                $this->info("{$verb} module: {$moduleId}", true);
                $operation($moduleId);
                $this->ok("Module '{$moduleId}' {$doneVerb}.", true);
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
