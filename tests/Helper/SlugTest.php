<?php

namespace Tests\Helper;

use OSC\Helper\Slug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Slug::class)]
class SlugTest extends TestCase
{
    public function testEmailBecomesFilesystemFriendlySlug(): void
    {
        $this->assertEquals('admin-example-com', Slug::email('admin@example.com'));
    }

    public function testEmailSlugIsLowercasedAndTrimmed(): void
    {
        $this->assertEquals('john-doe-example-org', Slug::email('  John.Doe@Example.org  '));
    }

    public function testConsecutiveSeparatorsCollapseToOneDash(): void
    {
        $this->assertEquals('a-b', Slug::email('a+++b@'));
    }

    public function testText(): void
    {
        $this->assertSame('mona-lisa-la-joconde', Slug::text('Mona Lisa (La Joconde)'));
        $this->assertSame('', Slug::text('!!!'));
        $this->assertLessThanOrEqual(60, strlen(Slug::text(str_repeat('word ', 30))));
        $this->assertStringEndsNotWith('-', Slug::text(str_repeat('word ', 30)));
    }
}
