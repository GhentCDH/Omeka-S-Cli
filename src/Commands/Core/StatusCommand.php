<?php
namespace OSC\Commands\Core;

use OSC\Commands\AbstractCommand;
use Omeka\Mvc\Status;

class StatusCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('core:status', 'Get Omeka S core status');
        $this->optionJson();
        $this->option('--is-installed', 'Check if Omeka S is installed (exit code 0 if yes, 1 if no)');
        $this->option('--needs-migration', 'Check if Omeka S needs migration (exit code 0 if yes, 1 if no)');
        $this->option('--is-ready', 'Check if Omeka S is ready (installed and no migrations needed, exit code 0 if yes, 1 if no)');
    }

    public function execute(): int {
        $serviceManager = $this->getOmekaInstance(false)->getServiceManager();

        /** @var Status $status */
        $status = $serviceManager->get('Omeka\Status');

        $isInstalled = $status->isInstalled();
        $needsMigration = $isInstalled && $status->needsMigration();

        // Handle check options
        if ($this->values()['isInstalled'] ?? false) {
            return $isInstalled ? 0 : 1;
        }

        if ($this->values()['needsMigration'] ?? false) {
            return $needsMigration ? 0 : 1;
        }

        if ($this->values()['isReady'] ?? false) {
            return ($isInstalled && !$needsMigration) ? 0 : 1;
        }

        // Default: output status
        if ($this->values()['json'] ?? false) {
            $this->outputFormatted(['installed' => $isInstalled, 'needsMigration' => $needsMigration], 'json');
        } else {
            $ret = [$isInstalled ? 'installed' : 'not_installed'];
            if ($needsMigration) {
                $ret[] = 'needs_migration';
            }
            $this->echo(implode(',', $ret), true);
        }
        return 0;
    }
}