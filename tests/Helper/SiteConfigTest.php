<?php
namespace Tests\Helper;

use InvalidArgumentException;
use OSC\Helper\SiteConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteConfig::class)]
class SiteConfigTest extends TestCase
{
    public function testDefaultsMapToTheApiPayload(): void
    {
        $config = SiteConfig::fromArray(['title' => 'My Site']);

        $this->assertSame([
            'o:title' => 'My Site',
            'o:slug' => '',
            'o:summary' => '',
            'o:theme' => 'default',
            'o:is_public' => true,
            'o:assign_new_items' => true,
            'o:item_pool' => [],
        ], $config->toApiPayload());
        $this->assertNull($config->slug());
        $this->assertSame('default', $config->theme());
    }

    public function testExplicitValuesMapToTheApiPayload(): void
    {
        $config = SiteConfig::fromArray([
            'title' => 'My Site',
            'slug' => 'my_site-2',
            'summary' => 'About it',
            'theme' => 'foundation',
            'isPublic' => false,
            'assignNewItems' => false,
        ]);

        $this->assertSame([
            'o:title' => 'My Site',
            'o:slug' => 'my_site-2',
            'o:summary' => 'About it',
            'o:theme' => 'foundation',
            'o:is_public' => false,
            'o:assign_new_items' => false,
            'o:item_pool' => [],
        ], $config->toApiPayload());
    }

    public function testTrimsValuesAndTreatsBlankAsMissing(): void
    {
        $config = SiteConfig::fromArray(['title' => '  My Site ', 'slug' => '', 'summary' => '   ', 'theme' => ' ']);

        $this->assertSame('My Site', $config->title());
        $this->assertNull($config->slug());
        $this->assertSame('default', $config->theme());
        $this->assertSame('', $config->toApiPayload()['o:summary']);
    }

    public function testMissingTitleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A site must have a title.');
        SiteConfig::fromArray(['slug' => 'x']);
    }

    public function testBlankTitleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SiteConfig::fromArray(['title' => '   ']);
    }

    public function testSlugWithInvalidCharactersIsRejected(): void
    {
        foreach (['my site', 'café', 'a/b', 'a.b'] as $slug) {
            try {
                SiteConfig::fromArray(['title' => 'X', 'slug' => $slug]);
                $this->fail("Slug '{$slug}' should be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("Invalid slug '{$slug}'", $e->getMessage());
            }
        }
    }

    public function testIsValidSlug(): void
    {
        $this->assertTrue(SiteConfig::isValidSlug('My_site-2'));
        $this->assertTrue(SiteConfig::isValidSlug('2024'));
        $this->assertFalse(SiteConfig::isValidSlug(''));
        $this->assertFalse(SiteConfig::isValidSlug('my site'));
    }

    public function testToArrayOmitsDefaults(): void
    {
        $this->assertSame(['title' => 'My Site'], SiteConfig::fromArray(['title' => 'My Site'])->toArray());
    }

    public function testToArrayKeepsNonDefaultsAndNeverEmitsAssignNewItems(): void
    {
        $config = SiteConfig::fromArray([
            'title' => 'My Site',
            'slug' => 'my-site',
            'summary' => 'About it',
            'theme' => 'foundation',
            'isPublic' => false,
            'assignNewItems' => false,
        ]);

        $this->assertSame([
            'title' => 'My Site',
            'slug' => 'my-site',
            'summary' => 'About it',
            'theme' => 'foundation',
            'isPublic' => false,
        ], $config->toArray());
    }
}
