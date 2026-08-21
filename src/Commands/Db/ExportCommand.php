<?php

namespace OSC\Commands\Db;

use Exception;
use InvalidArgumentException;
use OSC\Database\DumpOptions;
use OSC\Database\DumpWriter;
use OSC\Database\StreamFactory;
use OSC\Helper\Path;

class ExportCommand extends AbstractDbCommand
{
    /** Boolean options that would otherwise swallow the file name argument. */
    private const FLAGS = ['allData', 'skipAddDropTable', 'addDropDatabase', 'gzip', 'stdout', 'force'];

    public function __construct()
    {
        parent::__construct('db:export', 'Export the Omeka S database to a SQL dump');

        $this->argument('[filename]', 'Dump file (default: a timestamped file in the current directory)');
        $this->option('--no-data', 'Dump the structure only, without any row');
        $this->option('--all-data', 'Also dump the rows of the tables that are skipped by default');
        $this->option('--skip-data-tables', 'Comma separated tables whose rows are skipped', 'strval');
        $this->option('--tables', 'Comma separated tables to dump, to the exclusion of all others', 'strval');
        $this->option('--exclude-tables', 'Comma separated tables to leave out of the dump', 'strval');
        $this->option('--skip-add-drop-table', 'Do not write a DROP TABLE IF EXISTS before each table');
        $this->option('--add-drop-database', 'Write DROP DATABASE / CREATE DATABASE statements (needs elevated privileges on import)');
        $this->option('--gzip', 'Compress the dump (implied by a .gz file name)');
        $this->option('--stdout', 'Write the dump to standard output instead of a file');
        $this->option('-f --force', 'Overwrite an existing file, and allow writing inside the Omeka S directory');

        $this->usage(
            'db:export<eol>' .
            'db:export dump.sql.gz<eol>' .
            'db:export --no-data schema.sql<eol>' .
            'db:export --all-data full.sql<eol>' .
            'db:export --exclude-tables session,job dump.sql<eol>' .
            'db:export --stdout | gzip > dump.sql.gz<eol>'
        );
    }

    public function execute(): void
    {
        $values = $this->values();
        $reclaimed = $this->reclaimFlagValues(self::FLAGS);

        $filename = $values['filename'] ?? null;
        if (!$filename && $reclaimed) {
            $filename = $reclaimed[0];
        }

        $config = $this->getDatabaseConfig();
        $options = new DumpOptions(
            addDropTable: !$this->isFlagSet('skipAddDropTable'),
            addDropDatabase: $this->isFlagSet('addDropDatabase'),
            includeData: (bool) ($values['data'] ?? true),
            allData: $this->isFlagSet('allData'),
            skipDataTables: DumpOptions::parseList($this->stringValue('skipDataTables')),
            tables: DumpOptions::parseList($this->stringValue('tables')),
            excludeTables: DumpOptions::parseList($this->stringValue('excludeTables'))
        );

        $toStdout = $filename === '-' || $this->isFlagSet('stdout');
        $gzip = $this->isFlagSet('gzip');

        if ($toStdout) {
            $this->beQuiet();
            $target = 'php://stdout';
        } else {
            $target = $filename
                ? Path::toAbsolutePath($filename, $this->getCwd())
                : $this->getCwd() . DIRECTORY_SEPARATOR . $this->defaultFilename($config->getDbname());
            $gzip = $gzip || StreamFactory::wantsGzip($target);
            $this->checkTarget($target);
        }

        $engine = $this->createEngine();
        $this->debug("Using the {$engine->getName()} engine.", true);

        // The dump is written to a temporary file first, so a failed run never leaves a truncated
        // file that looks like a valid backup.
        $path = $toStdout ? $target : $target . '.part';
        $stream = StreamFactory::openWrite($path, $gzip);
        $writer = new DumpWriter($stream);

        try {
            $engine->export($options, $writer);
            $writer->close();
            fclose($stream);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$toStdout) {
                @unlink($path);
            }
            throw $e;
        }

        if ($toStdout) {
            return;
        }

        if (!rename($path, $target)) {
            @unlink($path);
            throw new Exception("Could not write the dump to '{$target}'.");
        }

        $this->ok(sprintf(
            "Database '%s' exported to '%s' (%s).",
            $config->getDbname(),
            $target,
            $this->formatSize((int) filesize($target))
        ), true);
    }

    private function defaultFilename(string $dbname): string
    {
        return sprintf('omeka-s-%s-%s.sql.gz', $dbname, date('Ymd-His'));
    }

    /**
     * A dump holds password hashes, API keys and the credentials of the instance, so it must not
     * end up in a directory the web server serves.
     */
    private function checkTarget(string $target): void
    {
        $directory = dirname($target);
        if (!is_dir($directory)) {
            throw new InvalidArgumentException("The directory '{$directory}' does not exist.");
        }
        if (!is_writable($directory)) {
            throw new InvalidArgumentException("The directory '{$directory}' is not writable.");
        }
        if (file_exists($target) && !$this->isFlagSet('force')) {
            throw new InvalidArgumentException("The file '{$target}' already exists. Use --force to overwrite it.");
        }

        $omekaPath = realpath($this->getOmekaPath());
        $realDirectory = realpath($directory);
        if (!$omekaPath || !$realDirectory || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, $omekaPath . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (!$this->isFlagSet('force')) {
            throw new InvalidArgumentException(
                "Refusing to write the dump inside the Omeka S directory ({$realDirectory}): it contains password"
                . ' hashes, API keys and the database credentials, and may be downloadable over the web.'
                . ' Write it outside the installation, or use --force.'
            );
        }

        $this->warn('The dump is written inside the Omeka S directory; make sure it is not reachable over the web.', true);
        $this->protectDirectory($realDirectory);
    }

    private function protectDirectory(string $directory): void
    {
        $htaccess = $directory . '/.htaccess';
        if (file_exists($htaccess)) {
            return;
        }

        @file_put_contents($htaccess, <<<'HTACCESS'
        # Deny direct access to database dumps.
        # They contain sensitive data (database credentials, API keys, password hashes).
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order deny,allow
            Deny from all
        </IfModule>
        HTACCESS);
    }
}
