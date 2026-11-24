<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Source\NamedAddon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedAddon::class)]
class NamedAddonTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:?string}> written → name, version */
    public static function namedAddons(): array
    {
        return [
            'name'                => ['Common', 'Common', null],
            'name and version'    => ['Common:3.4.93', 'Common', '3.4.93'],
            'dashes, underscores' => ['Clean-Url_2:1.0.0-beta', 'Clean-Url_2', '1.0.0-beta'],
            'digit first'         => ['3DViewer', '3DViewer', null],
        ];
    }

    #[DataProvider('namedAddons')]
    public function testTryParse(string $written, string $name, ?string $version): void
    {
        $addon = NamedAddon::tryParse($written);
        $this->assertNotNull($addon);
        $this->assertSame($name, $addon->name);
        $this->assertSame($version, $addon->version);
        $this->assertSame($written, $addon->display());
    }

    /** @return array<string,array{0:string}> */
    public static function otherForms(): array
    {
        return [
            'empty'           => [''],
            'dash first'      => ['-foo'],
            'underscore first' => ['_foo'],
            'empty version'   => ['Common:'],
            'two versions'    => ['Common:3.4:x'],
            'zip file'        => ['Common-3.4.71.zip'],
            'relative path'   => ['./dist/Log'],
            'gh repository'   => ['gh:o/r'],
            'url'             => ['https://example.org/M.zip'],
            'final newline'   => ["Common\n"],
        ];
    }

    /** A value that is not `name[:version]` is another form: null, never an error. */
    #[DataProvider('otherForms')]
    public function testTryParseOfAnotherFormIsNull(string $written): void
    {
        $this->assertNull(NamedAddon::tryParse($written));
    }

    public function testTheConstructorRejectsWhatTryParseDoesNotTake(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NamedAddon('-foo');
    }
}
