<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\Blueprint;
use OSC\Commands\AbstractCommand;
use OSC\Helper\DatabaseConfig;
use OSC\Helper\UserConfig;
use PDO;
use Throwable;

/**
 * The deploy "core" phase: download the Omeka S core (if absent), write database.ini, reset an
 * existing install (with --force) and install the core.
 *
 * Detection and reset use a raw PDO connection (no Omeka bootstrap); the install runs in-process,
 * which boots Omeka on the freshly reset/empty database — the standard single-process install path.
 * A helper for {@see DeployCommand} rather than blueprint-domain logic, so it lives under the command
 * namespace and drives the existing core:* commands through the invoking command.
 */
class CoreInstaller
{
    public function __construct(private AbstractCommand $command)
    {
    }

    /**
     * Ensure the core is downloaded, configured and installed at $targetPath.
     */
    public function run(Blueprint $blueprint, string $targetPath, DatabaseConfig $database, UserConfig $admin, bool $force): void
    {
        $this->command->info('• core', true);

        // 1. core files
        if (!$this->command->isOmekaDir($targetPath)) {
            $this->command->info('  downloading Omeka S core ...', true);
            $version = $blueprint->preferredOmekaVersion();
            $this->runSubcommand('core:download', fn($c) => $c->execute($version, $targetPath, true));
        } else {
            $this->command->info("  core already present at {$targetPath}", true);
        }

        // 2. database.ini. Omeka's core zip ships an empty template, so "the file exists" is not
        //    enough: write our credentials whenever the current file has none (fresh install), and
        //    keep it only when it already holds real credentials (a reset reuses them).
        $iniPath = $targetPath . '/config/database.ini';
        $existing = file_exists($iniPath) ? (@parse_ini_file($iniPath) ?: []) : [];
        $hasRealCredentials = !empty($existing['dbname']) && !empty($existing['user']);
        if (!$hasRealCredentials) {
            $this->command->info('  writing database.ini ...', true);
            $database->writeIniFile($iniPath);
        }

        // 3. detect an existing install and reset it (guarded by --force)
        $this->assertDatabaseReachable($database);
        if ($this->databaseHasOmekaTables($database)) {
            if (!$force) {
                throw new Exception(
                    "Omeka S is already installed at {$targetPath}. Pass --force to reset it "
                    . '(this deletes all data), or --skip core to deploy onto it.'
                );
            }
            $this->command->info('  resetting the database (dropping all tables) ...', true);
            $this->dropAllTables($database);
        }

        // 4. install the core (in-process, empty database)
        $siteOptions = $blueprint->siteOptions();
        $this->command->info('  installing Omeka S core ...', true);
        $this->runSubcommand('core:install', function ($c) use ($siteOptions, $admin, $targetPath) {
            $c->primeValue('basePath', $targetPath);
            $c->execute(
                (string) ($siteOptions['title'] ?? 'Omeka S'),
                (string) ($siteOptions['timezone'] ?? 'UTC'),
                (string) ($siteOptions['locale'] ?? 'en_US'),
                $admin->getEmail(),
                $admin->getName(),
                $admin->getPassword(),
            );
        });
    }

    public function reportDryRun(Blueprint $blueprint): void
    {
        $this->command->info('• core', true);
        $version = $blueprint->preferredOmekaVersion() ?? 'latest';
        $this->command->info("  would ensure the Omeka S core ({$version}) is downloaded and installed", true);
        $this->command->info('  would write database.ini if missing, and reset the database if it is already installed (with --force)', true);
    }

    /**
     * Refuse to deploy onto an already-installed instance without --force; refuse a sync onto one
     * that is not installed at all.
     */
    public function assertExistingInstallDeployable(DatabaseConfig $database, bool $force): void
    {
        $path = $this->command->resolveOmekaPath();
        if (!$this->databaseHasOmekaTables($database)) {
            throw new Exception("Omeka S is not installed at {$path}. Omit '--skip core' to install it.");
        }
        if (!$force) {
            throw new Exception("Omeka S is already installed at {$path}. Pass --force to deploy onto it.");
        }
    }

    // ── database helpers (raw PDO, no Omeka bootstrap) ────────────────────────────────────────────

    private function assertDatabaseReachable(DatabaseConfig $database): void
    {
        try {
            $database->getPdo(true);
        } catch (Throwable $e) {
            throw new Exception(
                "Cannot connect to database '{$database->getDbname()}' at '{$database->getHost()}'. "
                . 'Create it and grant access before deploying (deploy does not create databases). '
                . 'Original error: ' . $e->getMessage()
            );
        }
    }

    private function databaseHasOmekaTables(DatabaseConfig $database): bool
    {
        try {
            $tables = $database->getPdo(true)->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return false;
        }
        // "setting" is a core Omeka S table; its presence means the schema is installed
        return in_array('setting', $tables, true);
    }

    private function dropAllTables(DatabaseConfig $database): void
    {
        $pdo = $database->getPdo(true);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!$tables) {
            return;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function runSubcommand(string $name, callable $call): void
    {
        $cmd = $this->command->app()->commands()[$name] ?? null;
        if (!$cmd instanceof AbstractCommand) {
            throw new Exception("Required command '{$name}' is not available.");
        }
        $cmd->primeValue('verbosity', $this->command->values()['verbosity'] ?? 1);
        $call($cmd);
    }
}
