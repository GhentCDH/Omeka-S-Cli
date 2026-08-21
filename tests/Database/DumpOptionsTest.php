<?php

namespace OSC\Tests\Database;

use InvalidArgumentException;
use OSC\Database\DumpOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DumpOptions::class)]
class DumpOptionsTest extends TestCase
{
    private const TABLES = ['item', 'job', 'log', 'session', 'fulltext_search', 'triplestore_uri', 'value'];

    public function testParsesCommaSeparatedLists(): void
    {
        $this->assertSame(['item', 'value'], DumpOptions::parseList(' item , value '));
        $this->assertSame([], DumpOptions::parseList(''));
        $this->assertSame([], DumpOptions::parseList(null));
        $this->assertSame([], DumpOptions::parseList(true));
    }

    public function testDumpsEveryTableByDefault(): void
    {
        $options = new DumpOptions();

        $this->assertSame(self::TABLES, $options->tablesToDump(self::TABLES));
    }

    public function testSkipsTheDataOfVolatileTablesByDefault(): void
    {
        $options = new DumpOptions();

        $this->assertSame(
            ['job', 'log', 'session', 'fulltext_search', 'triplestore_uri'],
            $options->tablesWithoutData(self::TABLES)
        );
        $this->assertSame(['item', 'value'], $options->tablesWithData(self::TABLES));
    }

    public function testAllDataKeepsEveryRow(): void
    {
        $options = new DumpOptions(allData: true);

        $this->assertSame([], $options->tablesWithoutData(self::TABLES));
        $this->assertSame(self::TABLES, $options->tablesWithData(self::TABLES));
    }

    public function testAdditionalTablesCanSkipTheirData(): void
    {
        $options = new DumpOptions(skipDataTables: ['value']);

        $this->assertContains('value', $options->tablesWithoutData(self::TABLES));
        $this->assertSame(['item'], $options->tablesWithData(self::TABLES));
    }

    public function testWithoutDataNoTableKeepsItsRows(): void
    {
        $options = new DumpOptions(includeData: false);

        $this->assertSame([], $options->tablesWithData(self::TABLES));
        $this->assertSame(self::TABLES, $options->tablesWithoutData(self::TABLES));
    }

    public function testTablesRestrictsTheDump(): void
    {
        $options = new DumpOptions(tables: ['value', 'item']);

        $this->assertSame(['item', 'value'], $options->tablesToDump(self::TABLES));
    }

    public function testExcludeTablesLeavesTablesOut(): void
    {
        $options = new DumpOptions(excludeTables: ['job', 'log']);

        $this->assertSame(['item', 'session', 'fulltext_search', 'triplestore_uri', 'value'], $options->tablesToDump(self::TABLES));
    }

    public function testUnknownTableIsReported(): void
    {
        $options = new DumpOptions(tables: ['nope']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown table(s): nope.');

        $options->tablesToDump(self::TABLES);
    }

    public function testViewsCanBeNamedInTables(): void
    {
        $options = new DumpOptions(tables: ['item_view']);

        $this->assertSame([], $options->tablesToDump(self::TABLES, ['item_view']));
        $this->assertSame(['item_view'], $options->viewsToDump(['item_view', 'other_view']));
    }

    public function testViewsFollowTheExcludeList(): void
    {
        $options = new DumpOptions(excludeTables: ['other_view']);

        $this->assertSame(['item_view'], $options->viewsToDump(['item_view', 'other_view']));
    }

    public function testTablesAndExcludeTablesAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DumpOptions(tables: ['item'], excludeTables: ['value']);
    }
}
