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
        parent::__construct('core:dbmigrate', 'Upgrade Omeka S database if needed');
        $this->option('--dry-run', 'Check if migration is needed without executing');
        $this->option('--force', 'Force migration even if not needed');
    }

    public function execute(?bool $dryRun = false, ?bool $force = false): void
    {
        $serviceLocator = $this->getOmekaInstance()->getServiceManager();
        /** @var MigrationManager $migrationManager */
        $migrationManager = $serviceLocator->get('Omeka\MigrationManager');

        /** @var Status $status */
        $status = $serviceLocator->get('Omeka\Status');
        if (!$status->isInstalled()) {
            $this->info('Omeka S is not installed. No migration needed.', true);
            return;
        }

        if (!$status->needsMigration()) {
            $this->info('Database is up to date. No migration needed.', true);
            return;
        }

        // Perform the migration
        try {
            $this->info('Database migration required. Starting upgrade process ... ');
            $migrationManager->upgrade();
            $this->info('done');
        } finally {
            $this->io()->eol();
        }

        $this->ok("Database successfully migrated.", true);
    }
}