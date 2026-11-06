<?php

namespace Tests\Helper;

use OSC\Helper\ErrorStoreFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorStoreFormatter::class)]
class ErrorStoreFormatterTest extends TestCase
{
    public function testNestedErrorsAreFlattened(): void
    {
        $this->assertSame(
            "o:media.0.ingest_source: File not found\ndcterms:title: Required",
            ErrorStoreFormatter::format([
                'o:media' => [0 => ['ingest_source' => ['File not found']]],
                'dcterms:title' => ['Required'],
            ])
        );
    }

    public function testStringableMessages(): void
    {
        $message = new class {
            public function __toString(): string
            {
                return 'Invalid ingester';
            }
        };
        $this->assertSame('o:ingester: Invalid ingester', ErrorStoreFormatter::format(['o:ingester' => [$message]]));
    }
}
