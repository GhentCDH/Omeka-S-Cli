<?php

namespace OSC\Commands\Module;

use Ahc\Cli\Application as App;
use Ahc\Cli\Input\Argument;
use OSC\Commands\AbstractCommand;
use OSC\Commands\AddonBulkOperationTrait;
use OSC\Exceptions\NotFoundException;
use Omeka\Module\Module;

abstract class AbstractModuleCommand extends AbstractCommand
{
    use AddonBulkOperationTrait;

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
     * The result is unordered; use OSC\Addon\ModuleDependencyOrder when the order matters.
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
}
