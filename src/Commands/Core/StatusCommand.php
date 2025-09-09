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
    }

    public function execute(): void {
        $serviceManager = $this->getOmekaInstance(false)->getServiceManager();

        /** @var Status $status */
        $status = $serviceManager->get('Omeka\Status');

        $isInstalled = $status->isInstalled();
        $needsMigration = $isInstalled && $status->needsMigration();

        if ($this->values()['json'] ?? false) {
            $this->outputFormatted(['installed' => $isInstalled, 'needsMigration' => $needsMigration], 'json');
        } else {
            $ret = [$isInstalled ? 'installed' : 'not_installed'];
            if ($needsMigration) {
                $ret[] = 'needs_migration';
            }
            $this->echo(implode(',', $ret), true);
        }
    }
}