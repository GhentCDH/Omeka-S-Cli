<?php

namespace OSC\Database;

use OSC\Helper\DatabaseConfig;
use PDO;

/**
 * Read-only queries about the structure of the database, shared by both engines.
 */
class Schema
{
    public function __construct(private DatabaseConfig $config)
    {
    }

    public function getPdo(): PDO
    {
        return $this->config->getPdo();
    }

    /**
     * @return string[]
     */
    public function getTables(): array
    {
        return $this->listByType('BASE TABLE');
    }

    /**
     * @return string[]
     */
    public function getViews(): array
    {
        return $this->listByType('VIEW');
    }

    /**
     * @return array<array{name: string, type: string}>
     */
    public function getRoutines(): array
    {
        $statement = $this->getPdo()->prepare(
            'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES'
            . ' WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME'
        );
        $statement->execute([$this->config->getDbname()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hasTriggers(): bool
    {
        $statement = $this->getPdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?'
        );
        $statement->execute([$this->config->getDbname()]);

        return (bool) $statement->fetchColumn();
    }

    public function countEvents(): int
    {
        try {
            $statement = $this->getPdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?'
            );
            $statement->execute([$this->config->getDbname()]);
            return (int) $statement->fetchColumn();
        } catch (\PDOException $e) {
            // The events table is not readable on every server; not knowing is not an error.
            return 0;
        }
    }

    /**
     * @return array{charset: string, collation: string}
     */
    public function getDatabaseCharset(): array
    {
        $statement = $this->getPdo()->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation'
            . ' FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$this->config->getDbname()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'charset' => $row['charset'] ?? 'utf8mb4',
            'collation' => $row['collation'] ?? 'utf8mb4_unicode_ci',
        ];
    }

    public function getServerVersion(): string
    {
        return (string) $this->getPdo()->query('SELECT VERSION()')->fetchColumn();
    }

    /**
     * Quote an identifier, escaping any backtick it contains.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return string[]
     */
    private function listByType(string $type): array
    {
        $statement = $this->getPdo()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?'
            . ' ORDER BY TABLE_NAME'
        );
        $statement->execute([$this->config->getDbname(), $type]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
