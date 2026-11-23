<?php
namespace Tests\Addon;

use InvalidArgumentException;
use OSC\Addon\GitRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitRef::class)]
class GitRefTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function validRefs(): array
    {
        return array_combine($refs = ['v1.2.0', 'main', 'feature/x', '1.0+build', 'a1b2c3d', 'release-1', 'v2.0.0-rc1', '3.4.30'], array_map(fn($r) => [$r], $refs));
    }

    /** @return array<string,array{0:string}> */
    public static function invalidRefs(): array
    {
        $refs = ['', '-x', '--upload-pack=evil', 'a..b', '..', 'a//b', '/a', 'a/', 'a.lock', 'a/b.lock', 'x@{1}', '@',
            'a\\b', 'a b', "a\nb", "a\x7f", '.hidden', 'a/.b', 'a.', 'a:b', 'a~1', 'a^', 'a?', 'a*', 'a[b'];
        return array_combine(array_map(fn($r) => json_encode($r), $refs), array_map(fn($r) => [$r], $refs));
    }

    #[DataProvider('validRefs')]
    public function testAcceptsARef(string $ref): void
    {
        $this->assertTrue(GitRef::isValid($ref));
        $this->assertSame($ref, GitRef::assertValid($ref));
    }

    #[DataProvider('invalidRefs')]
    public function testRejectsARef(string $ref): void
    {
        $this->assertFalse(GitRef::isValid($ref));
    }

    public function testAssertValidNamesTheRef(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'--upload-pack=evil'");
        GitRef::assertValid('--upload-pack=evil');
    }
}
