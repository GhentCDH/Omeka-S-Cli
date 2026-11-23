<?php
namespace Tests\Helper;

use OSC\Helper\UrlCredentials;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlCredentials::class)]
class UrlCredentialsTest extends TestCase
{
    public function testHidesTheCredentialsOfAUrl(): void
    {
        $this->assertSame('https://***@example.org/a.zip', UrlCredentials::redact('https://user:pw@example.org/a.zip'));
        $this->assertSame('HTTP://***@example.org/a', UrlCredentials::redact('HTTP://token@example.org/a'));
        $this->assertSame('https://***@example.org/a', UrlCredentials::redact('https://user:p@ss@example.org/a'));
    }

    public function testHidesTheCredentialsOfEveryUrlInAMessage(): void
    {
        $this->assertSame(
            "Failed to download 'https://***@example.org/a.zip' (from http://***@example.org/b.json): 404",
            UrlCredentials::redact("Failed to download 'https://u:p@example.org/a.zip' (from http://x@example.org/b.json): 404")
        );
    }

    public function testKeepsValuesWithoutCredentials(): void
    {
        foreach (['https://example.org/a@b.zip', 'https://example.org/?mail=a@b', '/tmp/a@b.zip', 'git@github.com:o/r.git', 'gh:o/r@v1:a.zip', 'mail me@example.org'] as $value) {
            $this->assertSame($value, UrlCredentials::redact($value));
        }
    }
}
