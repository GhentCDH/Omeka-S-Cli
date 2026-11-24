<?php
namespace Tests\Source;

use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Addon\GitRef;
use OSC\Source\GitRepository;
use OSC\Source\SourceForm;
use OSC\Helper\Path;
use OSC\Helper\UrlCredentials;
use OSC\Source\CliSourceResolver;
use OSC\Source\GitFile;
use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitLabHost;
use OSC\Source\GitHost\GitPathSegments;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use OSC\Source\NamedAddon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CliSourceResolver::class)]
#[UsesClass(AddonReference::class)]
#[UsesClass(NamedAddon::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(GitFile::class)]
#[UsesClass(GitHubHost::class)]
#[UsesClass(GitLabHost::class)]
#[UsesClass(GitPathSegments::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(HttpUrl::class)]
#[UsesClass(LocalPath::class)]
#[UsesClass(Location::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(UrlCredentials::class)]
#[UsesClass(Path::class)]
class CliSourceResolverTest extends TestCase
{
    /** @return array<string,array{0:string,1:class-string,2:string}> typed → class, value (cwd /w) */
    public static function files(): array
    {
        return [
            'relative with a space' => ['./a b.json', LocalPath::class, '/w/a b.json'],
            'literal percent'       => ['My%20File.pdf', LocalPath::class, '/w/My%20File.pdf'],
            'parent and a hash'     => ['../x/#1.json', LocalPath::class, '/x/#1.json'],
            'absolute'              => ['/abs/a.json', LocalPath::class, '/abs/a.json'],
            'uppercase scheme'      => ['HTTPS://example.org/a', HttpUrl::class, 'https://example.org/a'],
            'gh file'               => ['gh:o/r@v1:a.json', HttpUrl::class, 'https://raw.githubusercontent.com/o/r/v1/a.json'],
            'drive-letter-like'     => ['C:/x.json', LocalPath::class, '/w/C:/x.json'],
        ];
    }

    #[DataProvider('files')]
    public function testFile(string $typed, string $class, string $value): void
    {
        $location = CliSourceResolver::file($typed, '/w');
        $this->assertInstanceOf($class, $location);
        $this->assertSame($value, $location->value());
        $this->assertSame($typed, $location->original());
    }

    /** @return array<string,array{0:string,1:string}> typed → message part */
    public static function rejected(): array
    {
        return [
            'empty'          => ['', 'empty'],
            'other scheme'   => ['php://filter/x', 'Unsupported source'],
            'git repository' => ['gh:o/r', 'is a git repository'],
            'github url'     => ['https://github.com/o/r', 'is a git repository'],
            'dot git url'    => ['https://example.org/r.git', 'is a git repository'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejects(string $typed, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        CliSourceResolver::file($typed, '/w');
    }

    public function testDirectoryIsALocalPath(): void
    {
        $dir = sys_get_temp_dir();
        $location = CliSourceResolver::file($dir, '/w');
        $this->assertInstanceOf(LocalPath::class, $location);
        $this->assertSame($dir, $location->value());
    }

    /**
     * module:download/theme:download arguments (cwd /w): the overview's table (§4) plus the cases of
     * the command-line parser it replaced.
     *
     * @return array<string,array{0:string,1:class-string,2:string,3:?string,4:string}>
     *         typed → source class, value (a NamedAddon: its name), version or ref, display()
     */
    public static function addons(): array
    {
        return [
            'name'               => ['Common', NamedAddon::class, 'Common', null, 'Common'],
            'name and version'   => ['Common:3.4.93', NamedAddon::class, 'Common', '3.4.93', 'Common:3.4.93'],
            'relative zip'       => ['./dist/Log.zip', LocalPath::class, '/w/dist/Log.zip', null, "'./dist/Log.zip' (/w/dist/Log.zip)"],
            'bare zip file'      => ['Common-3.4.71.zip', LocalPath::class, '/w/Common-3.4.71.zip', null, "'Common-3.4.71.zip' (/w/Common-3.4.71.zip)"],
            'absolute any name'  => ['/srv/zips/Log-release', LocalPath::class, '/srv/zips/Log-release', null, '/srv/zips/Log-release'],
            'absolute zip'       => ['/tmp/zips/Common.ZIP', LocalPath::class, '/tmp/zips/Common.ZIP', null, '/tmp/zips/Common.ZIP'],
            'any url is a zip'   => ['https://example.org/dl?id=5', HttpUrl::class, 'https://example.org/dl?id=5', null, 'https://example.org/dl?id=5'],
            'uppercase scheme'   => ['HTTPS://example.org/C.zip', HttpUrl::class, 'https://example.org/C.zip', null, "'HTTPS://example.org/C.zip' (https://example.org/C.zip)"],
            'github archive zip' => ['https://github.com/o/r/archive/refs/tags/v1.zip', HttpUrl::class, 'https://github.com/o/r/archive/refs/tags/v1.zip', null, 'https://github.com/o/r/archive/refs/tags/v1.zip'],
            'file in a git repo' => ['gh:o/m@v1:dist/M.zip', HttpUrl::class, 'https://raw.githubusercontent.com/o/m/v1/dist/M.zip', null, "'gh:o/m@v1:dist/M.zip' (https://raw.githubusercontent.com/o/m/v1/dist/M.zip)"],
            'gh repository'      => ['gh:o/m', GitRepository::class, 'https://github.com/o/m.git', null, "'gh:o/m' (https://github.com/o/m.git)"],
            'gh with ref'        => ['gh:o/m#v2.0', GitRepository::class, 'https://github.com/o/m.git#v2.0', 'v2.0', "'gh:o/m#v2.0' (https://github.com/o/m.git#v2.0)"],
            'branch with slash'  => ['gh:o/r#feature/x', GitRepository::class, 'https://github.com/o/r.git#feature/x', 'feature/x', "'gh:o/r#feature/x' (https://github.com/o/r.git#feature/x)"],
            'github url'         => ['https://github.com/o/r', GitRepository::class, 'https://github.com/o/r.git', null, "'https://github.com/o/r' (https://github.com/o/r.git)"],
            'github url and ref' => ['https://github.com/o-1/r.s_2#v1.0', GitRepository::class, 'https://github.com/o-1/r.s_2.git#v1.0', 'v1.0', "'https://github.com/o-1/r.s_2#v1.0' (https://github.com/o-1/r.s_2.git#v1.0)"],
            'dot git url'        => ['https://gitlab.com/g/sub/m.git#main', GitRepository::class, 'https://gitlab.com/g/sub/m.git#main', 'main', 'https://gitlab.com/g/sub/m.git#main'],
            'git over http'      => ['http://example.org/r.git', GitRepository::class, 'http://example.org/r.git', null, 'http://example.org/r.git'],
            'ssh'                => ['git@gitlab.com:g/m.git', GitRepository::class, 'git@gitlab.com:g/m.git', null, 'git@gitlab.com:g/m.git'],
        ];
    }

    #[DataProvider('addons')]
    public function testAddon(string $typed, string $class, string $value, ?string $versionOrRef, string $display): void
    {
        $reference = CliSourceResolver::addon($typed, '/w');
        $this->assertInstanceOf($class, $reference->source);
        if ($reference->source instanceof NamedAddon) {
            $this->assertSame($value, $reference->source->name);
            $this->assertSame($versionOrRef, $reference->source->version);
        } else {
            $this->assertSame($value, $reference->source->value());
            if ($reference->source instanceof GitRepository) {
                $this->assertSame($versionOrRef, $reference->source->ref());
            }
        }
        $this->assertSame($display, $reference->display());
    }

    /** @return array<string,array{0:string}> */
    public static function badAddons(): array
    {
        return [
            'free text'        => ['not a module'],
            'relative, no zip' => ['./dist/Log'],
            'other scheme'     => ['ftp://example.org/C.zip'],
            'option ref'       => ['gh:o/m#-x'],
            'option ref long'  => ['gh:o/r#--upload-pack=x'],
            'escaping repo'    => ['https://github.com/o/..'],
            'gl repository'    => ['gl:g/r'],
        ];
    }

    #[DataProvider('badAddons')]
    public function testAddonRejects(string $typed): void
    {
        $this->expectException(InvalidArgumentException::class);
        CliSourceResolver::addon($typed, '/w');
    }

    /** @return array<string,array{0:string}> */
    public static function undeterminedAddons(): array
    {
        return [
            'relative, no zip' => ['./dist/Log'],
            // not a name (NamedAddon::tryParse()), so not looked up in the repositories
            'dash first'       => ['-foo'],
            'underscore first' => ['_foo'],
        ];
    }

    #[DataProvider('undeterminedAddons')]
    public function testAddonThatIsNoFormSaysSo(string $typed): void
    {
        $this->expectExceptionMessage("Could not determine argument type: '{$typed}'");
        CliSourceResolver::addon($typed, '/w');
    }
}
