<?php

namespace OSC\Database;

use InvalidArgumentException;

/**
 * What goes into a dump, and how.
 */
class DumpOptions
{
    /**
     * Tables whose rows are skipped by default: they hold transient, rebuildable or historical
     * data that is usually not worth restoring, and they are often the largest tables of the
     * instance. Their structure is always dumped, so they come back empty but present.
     */
    public const DEFAULT_SKIP_DATA = ['session', 'fulltext_search', 'job', 'log'];

    /** Prefixes of tables whose rows are skipped by default. */
    public const DEFAULT_SKIP_DATA_PREFIX = ['triplestore_'];

    public function __construct(
        public bool $addDropTable = true,
        public bool $addDropDatabase = false,
        public bool $includeData = true,
        public bool $allData = false,
        public array $skipDataTables = [],
        public array $tables = [],
        public array $excludeTables = []
    ) {
        if ($this->tables && $this->excludeTables) {
            throw new InvalidArgumentException('The options --tables and --exclude-tables are mutually exclusive.');
        }
    }

    /**
     * Split a comma separated option value into a list of table names.
     */
    public static function parseList($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($name) => $name !== ''));
    }

    /**
     * The tables that end up in the dump, structure wise.
     *
     * @param string[] $allTables All base tables of the database.
     * @param string[] $allViews All views of the database, only used to validate --tables.
     * @return string[]
     */
    public function tablesToDump(array $allTables, array $allViews = []): array
    {
        if ($this->tables) {
            $unknown = array_diff($this->tables, $allTables, $allViews);
            if ($unknown) {
                throw new InvalidArgumentException('Unknown table(s): ' . implode(', ', $unknown) . '.');
            }
            return array_values(array_intersect($allTables, $this->tables));
        }

        if ($this->excludeTables) {
            return array_values(array_diff($allTables, $this->excludeTables));
        }

        return array_values($allTables);
    }

    /**
     * The views that end up in the dump.
     *
     * @param string[] $allViews
     * @return string[]
     */
    public function viewsToDump(array $allViews): array
    {
        if ($this->tables) {
            return array_values(array_intersect($allViews, $this->tables));
        }
        if ($this->excludeTables) {
            return array_values(array_diff($allViews, $this->excludeTables));
        }

        return array_values($allViews);
    }

    /**
     * The dumped tables whose rows are skipped.
     *
     * @param string[] $dumpedTables
     * @return string[]
     */
    public function tablesWithoutData(array $dumpedTables): array
    {
        if (!$this->includeData || $this->allData) {
            return $this->includeData ? [] : array_values($dumpedTables);
        }

        $skip = array_merge(self::DEFAULT_SKIP_DATA, $this->skipDataTables);

        return array_values(array_filter($dumpedTables, function ($table) use ($skip) {
            if (in_array($table, $skip, true)) {
                return true;
            }
            foreach (self::DEFAULT_SKIP_DATA_PREFIX as $prefix) {
                if (str_starts_with($table, $prefix)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * The dumped tables whose rows are included.
     *
     * @param string[] $dumpedTables
     * @return string[]
     */
    public function tablesWithData(array $dumpedTables): array
    {
        if (!$this->includeData) {
            return [];
        }

        return array_values(array_diff($dumpedTables, $this->tablesWithoutData($dumpedTables)));
    }
}
