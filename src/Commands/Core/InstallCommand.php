<?php
namespace OSC\Commands\Core;

use Exception;
use http\Exception\InvalidArgumentException;
use Omeka\Installation\Installer;
use OSC\Commands\AbstractCommand;
use Omeka\Mvc\Status;

class InstallCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('core:install', 'Install Omeka S');
        $this->option('-t --title', 'Title for this Omeka S installation', 'strval', 'Omeka S');
        $this->option('-tz --time-zone', 'Time zone (e.g., America/New_York)', 'strval', 'UTC');
        $this->option('-l --locale', 'Locale (e.g., en_US)', 'strval', 'en_US');
        $this->option('-n --admin-name', 'Name for the administrator user', 'strval', 'Admin');
        $this->option('-e --admin-email', 'E-mail for the administrator user', 'strval', 'admin@example.com');
        $this->option('-p --admin-password', 'Password for the administrator user', 'strval', 'admin');
    }

    public function execute(
        string $title,
        string $timeZone,
        string $locale,
        string $adminEmail,
        string $adminName,
        string $adminPassword,
    ): void {
        // Validate email
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$adminEmail}");
        }

        // Validate timezone
        if (!in_array($timeZone, timezone_identifiers_list())) {
            throw new InvalidArgumentException("Invalid timezone: {$timeZone}");
        }

        // Bootstrap Omeka S
        $omekaInstance = $this->getOmekaInstance(false);
        $serviceManager = $omekaInstance->getServiceManager();

        // Check install status
        /** @var Status $status */
        $status = $serviceManager->get('Omeka\Status');
        if ($status->isInstalled()) {
            throw new Exception("Omeka S is already installed at this location.");
        }

        // Get installation manager
        /** @var Installer $installer */
        $installer = $serviceManager->get('Omeka\Installer');

        // Register installation variables
        $installer->registerVars(
            'Omeka\Installation\Task\CreateFirstUserTask', [
            'name' => $adminName,
            'email' => $adminEmail,
            'password-confirm' => [
                'password' => $adminPassword,
            ],
        ]);
        $installer->registerVars(
            'Omeka\Installation\Task\AddDefaultSettingsTask', [
            'administrator_email' => $adminEmail,
            'installation_title' => $title,
            'time_zone' => $timeZone,
            'locale' => $locale,
        ]);

        // Perform installation
        $installer->install();

        $this->ok("Omeka S installation completed successfully!", true);
        $this->info("Administrator email: {$adminEmail}", true);
        $this->info("Administrator password: {$adminPassword}", true);
        $this->info("Installation title: {$title}", true);
        $this->info("Time zone: {$timeZone}", true);
        $this->info("Locale: {$locale}", true);
    }
}