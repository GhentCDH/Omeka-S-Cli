<?php

namespace OSC\Commands\Db;

use Ahc\Cli\IO\Interactor;
use Exception;
use InvalidArgumentException;
use OSC\Database\Schema;
use OSC\Database\StreamFactory;
use OSC\Exceptions\NotFoundException;
use OSC\Exceptions\WarningException;
use OSC\Helper\Path;
use PDOException;

class ImportCommand extends AbstractDbCommand
{
    /** Boolean options that would otherwise swallow the file name argument. */
    private const FLAGS = ['dropTables', 'recreateDatabase', 'yes'];

    /** Access denied / insufficient privileges. */
    private const PRIVILEGE_ERRORS = [1044, 1045, 1142, 1227];

    public function __construct()
    {
        parent::__construct('db:import', 'Import a SQL dump into the Omeka S database');

        $this->argument('[filename]', 'Dump file to import (plain SQL or gzipped)');
        $this->option('--drop-tables', 'Drop all existing tables before importing (table level privileges)');
        $this->option('--recreate-database', 'Drop and create the database before importing (elevated privileges)');
        $this->option('-y --yes', 'Do not ask for confirmation');

        $this->usage(
            'db:import dump.sql<eol>' .
            'db:import --drop-tables dump.sql.gz<eol>' .
            'db:import --recreate-database --yes dump.sql<eol>'
        );
    }

    public function interact(Interactor $io): void
    {
        $filename = $this->resolveFilename();
        if ($this->isFlagSet('yes')) {
            return;
        }

        $database = $this->getDatabaseConfig()->getDbname();
        $question = "This overwrites the database '{$database}' with '{$filename}'. Continue?";

        if (!stream_isatty(STDIN)) {
            throw new Exception("{$question} Rerun with --yes to confirm.");
        }
        if (!$io->confirm($question, 'n')) {
            throw new WarningException('Import cancelled.');
        }
    }

    public function execute(): void
    {
        $filename = $this->resolveFilename();
        $config = $this->getDatabaseConfig();

        if ($this->isFlagSet('dropTables') && $this->isFlagSet('recreateDatabase')) {
            throw new InvalidArgumentException('The options --drop-tables and --recreate-database are mutually exclusive.');
        }

        if ($this->isFlagSet('recreateDatabase')) {
            $this->recreateDatabase();
        } elseif ($this->isFlagSet('dropTables')) {
            $this->dropTables();
        }

        $engine = $this->createEngine();
        $this->debug("Using the {$engine->getName()} engine.", true);

        $stream = StreamFactory::openRead($filename);
        try {
            $engine->import($stream);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), '1050') || stripos($e->getMessage(), 'already exists') !== false) {
                throw new Exception(
                    $e->getMessage() . ' The database is not empty; rerun with --drop-tables to replace its content.',
                    0,
                    $e
                );
            }
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->ok("Database '{$config->getDbname()}' imported from '{$filename}'.", true);
        $this->reportPostImportHints();
    }

    private function resolveFilename(): string
    {
        static $resolved = null;
        if ($resolved) {
            return $resolved;
        }

        $filename = $this->values()['filename'] ?? null;
        $reclaimed = $this->reclaimFlagValues(self::FLAGS);
        if (!$filename && $reclaimed) {
            $filename = $reclaimed[0];
        }
        if (!$filename) {
            throw new InvalidArgumentException('The dump file to import is required.');
        }

        $filename = Path::toAbsolutePath($filename, $this->getCwd());
        if (!file_exists($filename)) {
            throw new NotFoundException("The file '{$filename}' does not exist.");
        }

        return $resolved = $filename;
    }

    /**
     * Most Omeka database users may only create and drop tables, so a failure here is expected and
     * gets an explanation instead of the raw driver error.
     */
    private function recreateDatabase(): void
    {
        $config = $this->getDatabaseConfig();
        $database = Schema::quoteIdentifier($config->getDbname());
        $schema = new Schema($config);

        try {
            $charset = $schema->getDatabaseCharset();
        } catch (PDOException $e) {
            $charset = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
        }

        try {
            $pdo = $config->getPdo(false);
            $pdo->exec("DROP DATABASE IF EXISTS {$database}");
            $pdo->exec(
                "CREATE DATABASE {$database} DEFAULT CHARACTER SET {$charset['charset']}"
                . " COLLATE {$charset['collation']}"
            );
            $pdo->exec("USE {$database}");
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), self::PRIVILEGE_ERRORS, true)) {
                throw new Exception(sprintf(
                    "The database user '%s' is not allowed to drop or create databases. Use --drop-tables instead,"
                    . ' which only needs table level privileges. (%s)',
                    $config->getUser(),
                    $e->getMessage()
                ), 0, $e);
            }
            throw $e;
        }

        $this->info("Database '{$config->getDbname()}' recreated.", true);
    }

    private function dropTables(): void
    {
        $config = $this->getDatabaseConfig();
        $schema = new Schema($config);
        $pdo = $config->getPdo();

        $views = $schema->getViews();
        $tables = $schema->getTables();
        if (!$views && !$tables) {
            $this->debug('The database is already empty.', true);
            return;
        }

        $pdo->exec('SET foreign_key_checks = 0');
        try {
            foreach ($views as $view) {
                $pdo->exec('DROP VIEW IF EXISTS ' . Schema::quoteIdentifier($view));
            }
            foreach ($tables as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . Schema::quoteIdentifier($table));
            }
        } catch (PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), self::PRIVILEGE_ERRORS, true)) {
                throw new Exception(sprintf(
                    "The database user '%s' is not allowed to drop tables. (%s)",
                    $config->getUser(),
                    $e->getMessage()
                ), 0, $e);
            }
            throw $e;
        } finally {
            $pdo->exec('SET foreign_key_checks = 1');
        }

        $this->info(sprintf('Dropped %d table(s) and %d view(s).', count($tables), count($views)), true);
    }

    /**
     * Dumps taken with the default options carry no full text search index, and a dump from
     * another Omeka S version needs the migrations to be run.
     */
    private function reportPostImportHints(): void
    {
        try {
            $pdo = $this->getDatabaseConfig()->getPdo();
            $indexed = (int) $pdo->query('SELECT COUNT(*) FROM fulltext_search')->fetchColumn();
            $resources = (int) $pdo->query('SELECT COUNT(*) FROM resource')->fetchColumn();
            if ($resources > 0 && $indexed === 0) {
                $this->info('The full text search index is empty; reindex it from the admin interface.', true);
            }
        } catch (PDOException $e) {
            // Not every dump holds these tables; the hint is a convenience, not a requirement.
        }

        $this->info("Run 'core:migrate' if the dump was made with another Omeka S version.", true);
    }
}
