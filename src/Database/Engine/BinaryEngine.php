<?php

namespace OSC\Database\Engine;

use Exception;
use OSC\Database\DumpOptions;
use OSC\Database\DumpWriter;
use OSC\Database\Schema;
use OSC\Helper\DatabaseConfig;
use OSC\Helper\ProcessRunner;

/**
 * Export and import through the mysqldump / mysql client binaries.
 */
class BinaryEngine implements EngineInterface
{
    private Schema $schema;

    /** @var callable|null */
    private $logger;

    public function __construct(
        private DatabaseConfig $config,
        private string $dumpBinary,
        private string $clientBinary,
        ?callable $logger = null
    ) {
        $this->schema = new Schema($config);
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return basename($this->dumpBinary);
    }

    public function export(DumpOptions $options, DumpWriter $writer): void
    {
        $views = $options->viewsToDump($this->schema->getViews());
        $tables = $options->tablesToDump($this->schema->getTables(), $views);
        if (!$tables && !$views) {
            throw new Exception('There is no table to dump.');
        }

        $withData = $options->tablesWithData($tables);
        $withoutData = $options->tablesWithoutData($tables);

        $defaultsFile = $this->config->writeDefaultsFile();
        try {
            $this->writeHeader($options, $writer);

            // mysqldump has no per table --no-data, so the structure of everything is dumped
            // first and the rows of the data bearing tables are appended afterwards. Triggers
            // come last: created earlier they would fire while the rows are inserted.
            // Views have to be named explicitly too: mysqldump only dumps them on its own when
            // no table is listed at all.
            $this->runDump($defaultsFile, $writer, array_merge(
                ['--no-data', '--skip-triggers'],
                $options->addDropTable ? [] : ['--skip-add-drop-table'],
                [$this->config->getDbname()],
                $tables,
                $views
            ));

            if ($withData) {
                $this->runDump($defaultsFile, $writer, array_merge(
                    ['--no-create-info', '--skip-triggers'],
                    [$this->config->getDbname()],
                    $withData
                ));
            }

            if ($this->schema->hasTriggers() || $this->schema->getRoutines()) {
                $this->runDump($defaultsFile, $writer, array_merge(
                    array_merge(
                        ['--no-create-info', '--no-data', '--triggers', '--routines'],
                        $this->supportsFlag('add-drop-trigger') ? ['--add-drop-trigger'] : []
                    ),
                    [$this->config->getDbname()],
                    $tables
                ));
            }

            if ($withoutData && $options->includeData) {
                $this->log(sprintf('Skipped the data of %d table(s): %s.', count($withoutData), implode(', ', $withoutData)));
            }
        } finally {
            @unlink($defaultsFile);
        }
    }

    public function import($stream): void
    {
        $defaultsFile = $this->config->writeDefaultsFile();
        try {
            $argv = [
                $this->clientBinary,
                '--defaults-extra-file=' . $defaultsFile,
                '--default-character-set=utf8mb4',
                $this->config->getDbname(),
            ];

            $result = ProcessRunner::run($argv, null, $stream);
            if ($result['code'] !== 0) {
                throw new Exception(sprintf(
                    "The mysql client failed (exit code %d)%s",
                    $result['code'],
                    $result['stderr'] === '' ? '.' : ': ' . $result['stderr']
                ));
            }
        } finally {
            @unlink($defaultsFile);
        }
    }

    /**
     * The DROP/CREATE DATABASE statements are written here instead of relying on mysqldump's
     * --add-drop-database, which only works together with --databases and would rule out dumping
     * a subset of the tables.
     */
    private function writeHeader(DumpOptions $options, DumpWriter $writer): void
    {
        if (!$options->addDropDatabase) {
            return;
        }

        $database = Schema::quoteIdentifier($this->config->getDbname());
        $charset = $this->schema->getDatabaseCharset();
        $writer->write(
            "--\n-- Database {$database}\n--\n\n"
            . "DROP DATABASE IF EXISTS {$database};\n"
            . "CREATE DATABASE {$database}"
            . " DEFAULT CHARACTER SET {$charset['charset']} COLLATE {$charset['collation']};\n"
            . "USE {$database};\n\n"
        );
    }

    /**
     * @param string[] $arguments
     */
    private function runDump(string $defaultsFile, DumpWriter $writer, array $arguments): void
    {
        $argv = array_merge(
            [$this->dumpBinary, '--defaults-extra-file=' . $defaultsFile],
            $this->baseFlags(),
            $arguments
        );

        $this->log('Running: ' . $this->dumpBinary . ' ' . implode(' ', array_slice($argv, 2)), 'debug');

        $result = ProcessRunner::run($argv, fn(string $chunk) => $writer->write($chunk));
        if ($result['code'] !== 0) {
            throw new Exception(sprintf(
                "%s failed (exit code %d)%s",
                $this->getName(),
                $result['code'],
                $result['stderr'] === '' ? '.' : ': ' . $result['stderr']
            ));
        }
        if ($result['stderr'] !== '') {
            $this->log($result['stderr'], 'debug');
        }
    }

    /**
     * @return string[]
     */
    private function baseFlags(): array
    {
        $flags = [
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            '--set-charset',
            '--default-character-set=utf8mb4',
        ];

        // Only add flags the installed client actually knows: --no-tablespaces is needed on
        // MySQL 8 (where dumping without the PROCESS privilege fails otherwise) but is unknown to
        // older clients, and --column-statistics=0 avoids a MySQL 8 client failing against an
        // older server.
        foreach (['no-tablespaces', 'skip-dump-date'] as $flag) {
            if ($this->supportsFlag($flag)) {
                $flags[] = '--' . $flag;
            }
        }
        if ($this->supportsFlag('column-statistics')) {
            $flags[] = '--column-statistics=0';
        }

        return $flags;
    }

    private function supportsFlag(string $flag): bool
    {
        static $help = null;

        if ($help === null) {
            $help = '';
            try {
                ProcessRunner::run([$this->dumpBinary, '--help'], function (string $chunk) use (&$help) {
                    $help .= $chunk;
                });
            } catch (Exception $e) {
                $help = '';
            }
        }

        return str_contains($help, '--' . $flag);
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger) {
            ($this->logger)($message, $level);
        }
    }
}
