<?php

namespace OSC\Tests\Database;

use OSC\Database\SqlStatementReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlStatementReader::class)]
class SqlStatementReaderTest extends TestCase
{
    /**
     * @return string[]
     */
    private function read(string $sql): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $sql);
        rewind($stream);

        $statements = iterator_to_array(SqlStatementReader::statements($stream), false);
        fclose($stream);

        return $statements;
    }

    public function testSplitsOnSemicolons(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->read("SELECT 1;\nSELECT 2;\n")
        );
    }

    public function testKeepsTheLastStatementWithoutTrailingSemicolon(): void
    {
        $this->assertSame(['SELECT 1'], $this->read('SELECT 1'));
    }

    public function testIgnoresEmptyStatements(): void
    {
        $this->assertSame(['SELECT 1'], $this->read(";;\nSELECT 1;\n\n;"));
    }

    public function testSemicolonInsideAStringDoesNotSplit(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('a;b', \"c;d\")"],
            $this->read("INSERT INTO t VALUES ('a;b', \"c;d\");")
        );
    }

    public function testEscapedQuotesAreHandled(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('it\\'s; here', 'do''ubled; too')"],
            $this->read("INSERT INTO t VALUES ('it\\'s; here', 'do''ubled; too');")
        );
    }

    public function testBackslashAtEndOfValue(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('back\\\\')", 'SELECT 2'],
            $this->read("INSERT INTO t VALUES ('back\\\\');\nSELECT 2;")
        );
    }

    public function testNewlineInsideAStringIsKept(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('line1\nline2; still')"],
            $this->read("INSERT INTO t VALUES ('line1\nline2; still');")
        );
    }

    public function testQuotedIdentifiersAreHandled(): void
    {
        $this->assertSame(
            ['SELECT `we;ird` FROM `t`'],
            $this->read('SELECT `we;ird` FROM `t`;')
        );
    }

    public function testLineCommentsAreStripped(): void
    {
        $statements = $this->read(<<<SQL
        -- a comment; with a semicolon
        SELECT 1; -- trailing comment
        # hash comment;
        SELECT 2;
        SQL);

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        $this->assertSame(['SELECT 1--2'], $this->read('SELECT 1--2;'));
    }

    public function testBlockCommentsAreStripped(): void
    {
        $statements = $this->read("/* a\n multi line; comment */\nSELECT 1;\nSELECT /* inline */ 2;");

        // A stripped comment leaves the whitespace that separated the tokens around it.
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            array_map(fn($statement) => preg_replace('~\s+~', ' ', $statement), $statements)
        );
    }

    public function testConditionalCommentsAreKept(): void
    {
        $this->assertSame(
            ['/*!40000 ALTER TABLE `t` DISABLE KEYS */'],
            $this->read("/*!40000 ALTER TABLE `t` DISABLE KEYS */;")
        );
    }

    public function testDelimiterBlocks(): void
    {
        $sql = <<<SQL
        DELIMITER ;;
        DROP TRIGGER IF EXISTS `trg`;;
        CREATE TRIGGER `trg` BEFORE INSERT ON `t` FOR EACH ROW
        BEGIN
          IF NEW.a IS NULL THEN SET NEW.a = 'x; y'; END IF;
        END;;
        DELIMITER ;
        SELECT 1;
        SQL;

        $statements = $this->read($sql);

        $this->assertCount(3, $statements);
        $this->assertSame('DROP TRIGGER IF EXISTS `trg`', $statements[0]);
        $this->assertStringStartsWith('CREATE TRIGGER `trg`', $statements[1]);
        $this->assertStringContainsString("SET NEW.a = 'x; y'; END IF;", $statements[1]);
        $this->assertSame('SELECT 1', $statements[2]);
    }

    public function testByteOrderMarkIsRemoved(): void
    {
        $this->assertSame(['SELECT 1'], $this->read("\xEF\xBB\xBFSELECT 1;"));
    }

    public function testMariadbSandboxLineIsIgnored(): void
    {
        $this->assertSame(
            ['SELECT 1'],
            $this->read("/*M!999999\\- enable the sandbox mode */ \nSELECT 1;")
        );
    }
}
