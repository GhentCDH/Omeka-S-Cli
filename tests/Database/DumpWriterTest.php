<?php

namespace OSC\Tests\Database;

use OSC\Database\DumpWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DumpWriter::class)]
class DumpWriterTest extends TestCase
{
    /**
     * @param string[] $chunks
     */
    private function write(array $chunks, bool $normalize = true): string
    {
        $stream = fopen('php://memory', 'r+');
        $writer = new DumpWriter($stream, $normalize);
        foreach ($chunks as $chunk) {
            $writer->write($chunk);
        }
        $writer->close();
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    public function testRewritesQuotedDefiners(): void
    {
        $this->assertSame(
            "CREATE DEFINER=CURRENT_USER VIEW `v` AS SELECT 1;\n",
            $this->write(["CREATE DEFINER=`omeka`@`localhost` VIEW `v` AS SELECT 1;\n"])
        );
    }

    public function testRewritesUnquotedDefiners(): void
    {
        $this->assertSame(
            "/*!50017 DEFINER=CURRENT_USER*/\n",
            $this->write(["/*!50017 DEFINER=omeka@localhost*/\n"])
        );
    }

    public function testRewritesDefinerSplitOverTwoChunks(): void
    {
        $this->assertSame(
            "CREATE DEFINER=CURRENT_USER VIEW `v`;\n",
            $this->write(['CREATE DEFINER=`ome', "ka`@`localhost` VIEW `v`;\n"])
        );
    }

    public function testWritesTheLastLineWithoutNewline(): void
    {
        $this->assertSame('SELECT 1', $this->write(['SELECT ', '1']));
    }

    public function testKeepsTheContentUntouchedWhenNormalizationIsOff(): void
    {
        $sql = "CREATE DEFINER=`omeka`@`localhost` VIEW `v`;\n";

        $this->assertSame($sql, $this->write([$sql], false));
    }

    public function testCountsTheBytesWritten(): void
    {
        $stream = fopen('php://memory', 'r+');
        $writer = new DumpWriter($stream, false);
        $writer->write('12345');
        $writer->close();
        fclose($stream);

        $this->assertSame(5, $writer->getBytesWritten());
    }
}
