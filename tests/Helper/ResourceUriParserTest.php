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
            'github url'         => ['https://github.com/o/r', ResourceUriType::GitHubRepo, 'o/r', null],
            'github url slash'   => ['https://github.com/o/r/', ResourceUriType::GitHubRepo, 'o/r', null],
            'github url and ref' => ['https://github.com/o-1/r.s_2#v1.0', ResourceUriType::GitHubRepo, 'o-1/r.s_2', 'v1.0'],
            'github url .git'    => ['https://github.com/o/r.git', ResourceUriType::GitRepo, 'https://github.com/o/r.git', null],
            'github archive zip' => ['https://github.com/o/r/archive/refs/tags/v1.zip', ResourceUriType::ZipUrl, 'https://github.com/o/r/archive/refs/tags/v1.zip', null],
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

    public static function unknownForms(): array
    {
        return [
            'free text'            => ['not a module'],
            'github owner only'    => ['https://github.com/o'],
            'github tree page'     => ['https://github.com/o/r/tree/main'],
            'github blob page'     => ['https://github.com/o/r/blob/main/README.md'],
            'github over http'     => ['http://github.com/o/r'],
            'github www host'      => ['https://www.github.com/o/r'],
            'other git host'       => ['https://gitlab.com/o/r'],
            'github .git, slash'   => ['https://github.com/o/r.git/'],
        ];
    }

    #[DataProvider('unknownForms')]
    public function testUnknownFormIsRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResourceUriParser::parse($input);
    }
}
