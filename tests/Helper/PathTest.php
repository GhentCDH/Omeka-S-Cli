<?php
namespace Tests\Helper;

use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Path::class)]
class PathTest extends TestCase
{
    public function testSafeFolderNames(): void
    {
        foreach (['freedom', 'Foundation_S', 'my-theme', 'theme.v2'] as $name) {
            $this->assertTrue(Path::isSafeFolderName($name), $name);
        }
        foreach (['', '.', '..', 'a/b', '../x', 'with space', 'a\\b'] as $name) {
            $this->assertFalse(Path::isSafeFolderName($name), $name);
        }
    }
}
