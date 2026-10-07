<?php
namespace Tests\Blueprint;

use InvalidArgumentException;
use OSC\Commands\Blueprint\ExportOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportOptions::class)]
class ExportOptionsTest extends TestCase
{
    public function testIncludesNothingOptionalByDefault(): void
    {
        $this->assertFalse((new ExportOptions())->includes(ExportOptions::SITE_PERMISSIONS));
        $this->assertFalse(ExportOptions::fromInclude(null)->includes(ExportOptions::SITE_PERMISSIONS));
        $this->assertFalse(ExportOptions::fromInclude('')->includes(ExportOptions::SITE_PERMISSIONS));
    }

    public function testParsesACommaSeparatedList(): void
    {
        $options = ExportOptions::fromInclude(' Site-Permissions , site-permissions,');

        $this->assertTrue($options->includes(ExportOptions::SITE_PERMISSIONS));
        $this->assertSame([ExportOptions::SITE_PERMISSIONS], $options->included());
    }

    public function testRejectsAnUnknownPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid --include 'passwords'. Use one or more of: site-permissions.");
        ExportOptions::fromInclude('site-permissions,passwords');
    }
}
