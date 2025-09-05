<?php
namespace OSC\Commands\Core;

use Exception;
use OSC\Commands\AbstractCommand;
use Omeka\Db\Migration\Manager as MigrationManager;
use Omeka\Mvc\Status;

class MigrateCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('core:migrate', 'Upgrade Omeka S database if needed');
    }

    public function execute(): void
    {
        $serviceLocator = $this->getOmekaInstance()->getServiceManager();
        /** @var MigrationManager $migrationManager */
        $migrationManager = $serviceLocator->get('Omeka\MigrationManager');

        /** @var Status $status */
        $status = $serviceLocator->get('Omeka\Status');
        if (!$status->isInstalled()) {
            throw new WarningException('Omeka S is not installed.', true);
        }

        if (!$status->needsMigration()) {
            $this->info('Database is up to date. No migrations required.', true);
            return;
        }

        // Perform the migration
        try {
            $this->info('Database migrations required. Starting upgrade process ... ');
            $migrationManager->upgrade();
            $this->info('done');
        } finally {
            $this->io()->eol();
        }

        $this->ok("Core database successfully migrated.", true);
    }
}