<?php

namespace Tests\Helper;

use OSC\Addon\AddonReference;
use OSC\Addon\GitRef;
use OSC\Blueprint\SourceForm;
use OSC\Helper\Reference\GitHubProvider;
use OSC\Helper\Reference\GitLabProvider;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\Reference\RepoSegments;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\ResourceUriParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceUriParser::class)]
#[UsesClass(AddonReference::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(ReferenceResolver::class)]
#[UsesClass(GitHubProvider::class)]
#[UsesClass(GitLabProvider::class)]
#[UsesClass(RepoSegments::class)]
#[UsesClass(ResourceFetcher::class)]
class ResourceUriParserTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string,3:?string}> argument → kind, name or location, version */
    public static function arguments(): array
    {
        return [
            'zip url'            => ['https://example.org/Common-3.4.71.zip', AddonReference::ZIP_URL, 'https://example.org/Common-3.4.71.zip', null],
            'url without .zip'   => ['https://example.org/download?id=3', AddonReference::ZIP_URL, 'https://example.org/download?id=3', null],
            'uppercase scheme'   => ['HTTPS://example.org/C.zip', AddonReference::ZIP_URL, 'https://example.org/C.zip', null],
            'relative zip file'  => ['./Common-3.4.71.zip', AddonReference::ZIP_FILE, './Common-3.4.71.zip', null],
            'bare zip file'      => ['Common-3.4.71.zip', AddonReference::ZIP_FILE, 'Common-3.4.71.zip', null],
            'absolute zip file'  => ['/tmp/zips/Common.ZIP', AddonReference::ZIP_FILE, '/tmp/zips/Common.ZIP', null],
            'absolute any name'  => ['/tmp/zips/Common', AddonReference::ZIP_FILE, '/tmp/zips/Common', null],
            'git url'            => ['https://github.com/o/r.git#v1', AddonReference::GIT, 'https://github.com/o/r.git', 'v1'],
            'git url over http'  => ['http://example.org/r.git', AddonReference::GIT, 'http://example.org/r.git', null],
            'github shorthand'   => ['gh:o/r#v1', AddonReference::GIT, 'https://github.com/o/r.git', 'v1'],
            'github url'         => ['https://github.com/o/r', AddonReference::GIT, 'https://github.com/o/r.git', null],
            'github url slash'   => ['https://github.com/o/r/', AddonReference::GIT, 'https://github.com/o/r.git', null],
            'github url and ref' => ['https://github.com/o-1/r.s_2#v1.0', AddonReference::GIT, 'https://github.com/o-1/r.s_2.git', 'v1.0'],
            'branch with slash'  => ['gh:o/r#feature/x', AddonReference::GIT, 'https://github.com/o/r.git', 'feature/x'],
            'github url .git'    => ['https://github.com/o/r.git', AddonReference::GIT, 'https://github.com/o/r.git', null],
            'ssh'                => ['git@github.com:o/r.git#main', AddonReference::GIT, 'git@github.com:o/r.git', 'main'],
            'github archive zip' => ['https://github.com/o/r/archive/refs/tags/v1.zip', AddonReference::ZIP_URL, 'https://github.com/o/r/archive/refs/tags/v1.zip', null],
            'file in a repo'     => ['gh:o/r@v1:dist/M.zip', AddonReference::ZIP_URL, 'https://raw.githubusercontent.com/o/r/v1/dist/M.zip', null],
            'id'                 => ['Common', AddonReference::BY_NAME, 'Common', null],
            'id and version'     => ['Common:3.4.71', AddonReference::BY_NAME, 'Common', '3.4.71'],
        ];
    }

    #[DataProvider('arguments')]
    public function testParse(string $input, string $kind, string $nameOrLocation, ?string $version): void
    {
        $reference = ResourceUriParser::parse($input);
        $this->assertSame($kind, $reference->kind);
        $this->assertSame($nameOrLocation, $kind === AddonReference::BY_NAME ? $reference->name : $reference->location);
        $this->assertSame($version, $reference->version);
    }

    public static function unknownForms(): array
    {
        return [
            'free text'          => ['not a module'],
            'relative, no zip'   => ['dist/Common'],
            'other scheme'       => ['ftp://example.org/C.zip'],
            'option ref'         => ['gh:o/r#--upload-pack=x'],
            'escaping repo'      => ['https://github.com/o/..'],
            'gl repository'      => ['gl:g/r'],
        ];
    }

    #[DataProvider('unknownForms')]
    public function testUnknownFormIsRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResourceUriParser::parse($input);
    }
}
