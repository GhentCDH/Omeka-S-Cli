<?php

namespace OSC\Database\Engine;

use Exception;
use OSC\Database\DumpOptions;
use OSC\Database\DumpWriter;
use OSC\Database\Schema;
use OSC\Database\SqlStatementReader;
use OSC\Helper\DatabaseConfig;
use PDO;
use PDOException;

/**
 * Export and import with PHP only, for hosts without the mysql client binaries.
 */
class PdoEngine implements EngineInterface
{
    /** Maximum size of an extended INSERT statement, in bytes. */
    private const MAX_INSERT_SIZE = 1048576;

    private const BINARY_TYPES = ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'];

    private Schema $schema;

    /** @var callable|null */
    private $logger;

    public function __construct(private DatabaseConfig $config, ?callable $logger = null)
    {
        $this->schema = new Schema($config);
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'php';
    }

    public function export(DumpOptions $options, DumpWriter $writer): void
    {
        $views = $options->viewsToDump($this->schema->getViews());
        $tables = $options->tablesToDump($this->schema->getTables(), $views);
        if (!$tables && !$views) {
            throw new Exception('There is no table to dump.');
        }

        $withoutData = $options->tablesWithoutData($tables);
        $events = $this->schema->countEvents();
        if ($events) {
            $this->log("The PHP export does not dump scheduled events; {$events} event(s) skipped.", 'warn');
        }

        $this->writeHeader($options, $writer);

        foreach ($this->schema->getRoutines() as $routine) {
            $this->exportRoutine($routine['name'], $routine['type'], $writer);
        }

        foreach ($tables as $table) {
            $this->exportTableStructure($table, $options, $writer);
            if (!in_array($table, $withoutData, true)) {
                $this->exportTableData($table, $writer);
            }
        }

        foreach ($views as $view) {
            $this->exportViewStructure($view, $writer);
        }

        // Triggers are written after the rows: created earlier they would fire on every insert.
        foreach ($tables as $table) {
            $this->exportTriggers($table, $writer);
        }

        $writer->write("\nSET foreign_key_checks = 1;\n");

        if ($withoutData && $options->includeData) {
            $this->log(sprintf('Skipped the data of %d table(s): %s.', count($withoutData), implode(', ', $withoutData)));
        }
    }

    public function import($stream): void
    {
        $pdo = $this->config->getPdo();
        $pdo->exec('SET foreign_key_checks = 0');

        $number = 0;
        try {
            foreach (SqlStatementReader::statements($stream) as $statement) {
                $number++;
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    throw new Exception(sprintf(
                        "Statement %d failed: %s\nStatement: %s",
                        $number,
                        $e->getMessage(),
                        mb_strimwidth(preg_replace('~\s+~', ' ', $statement), 0, 200, '…')
                    ), 0, $e);
                }
            }
        } finally {
            try {
                $pdo->exec('SET foreign_key_checks = 1');
            } catch (PDOException $e) {
                // The connection may be gone; the original error is the interesting one.
            }
        }

        $this->log("Executed {$number} statement(s).", 'debug');
    }

    private function writeHeader(DumpOptions $options, DumpWriter $writer): void
    {
        $writer->write(
            "-- Omeka S CLI database dump\n"
            . '-- Server: ' . $this->schema->getServerVersion() . "\n"
            . '-- Database: ' . $this->config->getDbname() . "\n\n"
        );

        if ($options->addDropDatabase) {
            $database = Schema::quoteIdentifier($this->config->getDbname());
            $charset = $this->schema->getDatabaseCharset();
            $writer->write(
                "DROP DATABASE IF EXISTS {$database};\n"
                . "CREATE DATABASE {$database}"
                . " DEFAULT CHARACTER SET {$charset['charset']} COLLATE {$charset['collation']};\n"
                . "USE {$database};\n\n"
            );
        }

        $writer->write(
            "SET NAMES utf8mb4;\n"
            . "SET time_zone = '+00:00';\n"
            . "SET foreign_key_checks = 0;\n"
            . "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
        );
    }

    private function exportTableStructure(string $table, DumpOptions $options, DumpWriter $writer): void
    {
        $quoted = Schema::quoteIdentifier($table);
        $writer->write("--\n-- Table structure for {$quoted}\n--\n\n");
        if ($options->addDropTable) {
            $writer->write("DROP TABLE IF EXISTS {$quoted};\n");
        }

        $row = $this->schema->getPdo()->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $writer->write(($row['Create Table'] ?? '') . ";\n\n");
        }
    }

    private function exportTableData(string $table, DumpWriter $writer): void
    {
        $quoted = Schema::quoteIdentifier($table);
        $pdo = $this->schema->getPdo();

        $columns = $this->getColumns($table);
        if (!$columns) {
            return;
        }
        $columnList = implode(', ', array_map(fn($column) => Schema::quoteIdentifier($column['name']), $columns));
        $binary = array_map(fn($column) => $column['binary'], $columns);

        $header = "INSERT INTO {$quoted} ({$columnList}) VALUES\n";
        $rows = 0;
        $buffer = '';
        $size = 0;

        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $statement = $pdo->query("SELECT * FROM {$quoted}");
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                if ($rows === 0) {
                    $writer->write("--\n-- Data for {$quoted}\n--\n\n");
                    $writer->write("/*!40000 ALTER TABLE {$quoted} DISABLE KEYS */;\n");
                }

                $values = [];
                foreach ($row as $index => $value) {
                    $values[] = $this->quoteValue($pdo, $value, $binary[$index] ?? false);
                }
                $tuple = '(' . implode(',', $values) . ')';

                if ($buffer !== '' && $size + strlen($tuple) > self::MAX_INSERT_SIZE) {
                    $writer->write($header . $buffer . ";\n");
                    $buffer = '';
                    $size = 0;
                }
                $buffer .= ($buffer === '' ? '' : ",\n") . $tuple;
                $size += strlen($tuple) + 2;
                $rows++;
            }
            $statement->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        if ($buffer !== '') {
            $writer->write($header . $buffer . ";\n");
        }
        if ($rows > 0) {
            $writer->write("/*!40000 ALTER TABLE {$quoted} ENABLE KEYS */;\n\n");
            $this->log("Exported table {$table}: {$rows} row(s).", 'debug');
        }
    }

    private function exportViewStructure(string $view, DumpWriter $writer): void
    {
        $quoted = Schema::quoteIdentifier($view);
        $writer->write("--\n-- View structure for {$quoted}\n--\n\n");
        $writer->write("DROP VIEW IF EXISTS {$quoted};\n");

        $row = $this->schema->getPdo()->query("SHOW CREATE VIEW {$quoted}")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $writer->write(($row['Create View'] ?? '') . ";\n\n");
        }
    }

    private function exportTriggers(string $table, DumpWriter $writer): void
    {
        $statement = $this->schema->getPdo()->prepare('SHOW TRIGGERS WHERE `Table` = ?');
        $statement->execute([$table]);
        $triggers = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$triggers) {
            return;
        }

        $quoted = Schema::quoteIdentifier($table);
        $writer->write("--\n-- Triggers for {$quoted}\n--\n\nDELIMITER ;;\n");
        foreach ($triggers as $trigger) {
            $name = Schema::quoteIdentifier($trigger['Trigger']);
            $writer->write("DROP TRIGGER IF EXISTS {$name};;\n");
            $writer->write("CREATE TRIGGER {$name} {$trigger['Timing']} {$trigger['Event']} ON {$quoted} FOR EACH ROW\n");
            $writer->write("{$trigger['Statement']};;\n");
        }
        $writer->write("DELIMITER ;\n\n");
    }

    private function exportRoutine(string $name, string $type, DumpWriter $writer): void
    {
        $quoted = Schema::quoteIdentifier($name);
        $row = $this->schema->getPdo()->query("SHOW CREATE {$type} {$quoted}")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $create = $row[$type === 'PROCEDURE' ? 'Create Procedure' : 'Create Function'] ?? '';
        if (!$create) {
            return;
        }

        $writer->write("--\n-- {$type} {$quoted}\n--\n\nDELIMITER ;;\n");
        $writer->write("DROP {$type} IF EXISTS {$quoted};;\n");
        $writer->write($create . ";;\n");
        $writer->write("DELIMITER ;\n\n");
    }

    /**
     * @return array<array{name: string, binary: bool}>
     */
    private function getColumns(string $table): array
    {
        $statement = $this->schema->getPdo()->prepare(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$this->config->getDbname(), $table]);

        return array_map(fn($row) => [
            'name' => $row['name'],
            'binary' => in_array(strtolower($row['type']), self::BINARY_TYPES, true),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function quoteValue(PDO $pdo, $value, bool $binary): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($binary) {
            return $value === '' ? "''" : '0x' . bin2hex((string) $value);
        }

        return $pdo->quote((string) $value);
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->logger) {
            ($this->logger)($message, $level);
        }
    }
}
