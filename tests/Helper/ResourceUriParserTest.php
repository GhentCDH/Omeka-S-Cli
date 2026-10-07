<?php
namespace Tests\Helper;

use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUri;
use OSC\Helper\Types\ResourceUriType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceUriParser::class)]
#[UsesClass(ResourceUri::class)]
class ResourceUriParserTest extends TestCase
{
    public static function uris(): array
    {
        return [
            'zip url'            => ['https://example.org/Common-3.4.71.zip', ResourceUriType::ZipUrl, 'https://example.org/Common-3.4.71.zip', null],
            'relative zip file'  => ['./Common-3.4.71.zip', ResourceUriType::ZipFile, './Common-3.4.71.zip', null],
            'bare zip file'      => ['Common-3.4.71.zip', ResourceUriType::ZipFile, 'Common-3.4.71.zip', null],
            'absolute zip file'  => ['/tmp/zips/Common.ZIP', ResourceUriType::ZipFile, '/tmp/zips/Common.ZIP', null],
            'git url'            => ['https://github.com/o/r.git#v1', ResourceUriType::GitRepo, 'https://github.com/o/r.git', 'v1'],
            'github shorthand'   => ['gh:o/r#v1', ResourceUriType::GitHubRepo, 'o/r', 'v1'],
            'id and version'     => ['Common:3.4.71', ResourceUriType::IdVersion, 'Common', '3.4.71'],
        ];
    }

    #[DataProvider('uris')]
    public function testParse(string $input, ResourceUriType $type, string $id, ?string $version): void
    {
        $uri = ResourceUriParser::parse($input);
        $this->assertSame($type, $uri->getType());
        $this->assertSame($id, $uri->getId());
        $this->assertSame($version, $uri->getVersion());
    }

    public function testUnknownFormIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResourceUriParser::parse('not a module');
    }
}
