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
}
