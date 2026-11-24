<?php
namespace Tests\Addon;

use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Addon\GitRef;
use OSC\Helper\UrlCredentials;
use OSC\Source\GitRepository;
use OSC\Source\NamedAddon;
use OSC\Source\HttpUrl;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use OSC\Source\SourceForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonReference::class)]
#[UsesClass(NamedAddon::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(HttpUrl::class)]
#[UsesClass(LocalPath::class)]
#[UsesClass(Location::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(UrlCredentials::class)]
class AddonReferenceTest extends TestCase
{
    /**
     * A loaded blueprint's entry: its source is already resolved by the loader (an absolute path, a URL,
     * or a git repository's clone URL with #ref).
     *
     * @return array<string,array{0:string,1:?string,2:?string,3:class-string,4:string,5:?string}>
     *         name, source, version → source class, value (a NamedAddon: its name), version or ref
     */
    public static function loadedEntries(): array
    {
        return [
            'name only'           => ['Common', null, null, NamedAddon::class, 'Common', null],
            'name and version'    => ['Common', null, '3.4.93', NamedAddon::class, 'Common', '3.4.93'],
            'zip file'            => ['M', '/w/zips/M.zip', '1.0', LocalPath::class, '/w/zips/M.zip', null],
            'zip ignores version' => ['M', 'https://example.org/M-2.1.0.zip', '2.0.0', HttpUrl::class, 'https://example.org/M-2.1.0.zip', null],
            'version becomes ref' => ['A', 'https://github.com/o/a.git', '1.2.0', GitRepository::class, 'https://github.com/o/a.git#1.2.0', '1.2.0'],
            'ref wins'            => ['C', 'https://github.com/o/c.git#main', '1.2.0', GitRepository::class, 'https://github.com/o/c.git#main', 'main'],
            'git file is a zip'   => ['F', 'https://raw.githubusercontent.com/o/f/v1/dist/F.zip', null, HttpUrl::class, 'https://raw.githubusercontent.com/o/f/v1/dist/F.zip', null],
            'ssh'                 => ['S', 'git@gitlab.com:g/h.git', null, GitRepository::class, 'git@gitlab.com:g/h.git', null],
        ];
    }

    #[DataProvider('loadedEntries')]
    public function testFromALoadedEntry(string $name, ?string $source, ?string $version, string $class, string $value, ?string $versionOrRef): void
    {
        $reference = AddonReference::fromLoaded($name, $source, $version);
        $this->assertInstanceOf($class, $reference->source);
        if ($reference->source instanceof NamedAddon) {
            $this->assertSame($value, $reference->source->name);
            $this->assertSame($versionOrRef, $reference->source->version);
        } else {
            $this->assertSame($value, $reference->source->value());
        }
        if ($reference->source instanceof GitRepository) {
            $this->assertSame($versionOrRef, $reference->source->ref());
        }
    }

    public function testFromBlueprintTakesTypedSources(): void
    {
        $git = AddonReference::fromBlueprint('A', GitRepository::parse('gh:o/a'), '1.2.0');
        $this->assertInstanceOf(GitRepository::class, $git->source);
        $this->assertSame('1.2.0', $git->source->ref());

        $zip = AddonReference::fromBlueprint('M', HttpUrl::fromUrl('https://example.org/M.zip'), '2.0.0');
        $this->assertInstanceOf(HttpUrl::class, $zip->source);

        $named = AddonReference::fromBlueprint('Log', null, '3.4.30');
        $this->assertInstanceOf(NamedAddon::class, $named->source);
        $this->assertSame('3.4.30', $named->source->version);
    }

    /** @return array<string,array{0:?string,1:?string}> source, version */
    public static function invalidLoaded(): array
    {
        return [
            'id:version source' => ['CleanUrl:3.4', null],
            'unresolved path'   => ['./zips/M.zip', null],
            'option version'    => ['https://github.com/o/r.git', '--upload-pack=x'],
            'gh shortcut'       => ['gh:o/r:f.zip', null],
        ];
    }

    #[DataProvider('invalidLoaded')]
    public function testRejectsAnUnresolvedSource(?string $source, ?string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        AddonReference::fromLoaded('X', $source, $version);
    }

    public function testAnInvalidNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AddonReference::byName('CleanUrl:3.4');
    }

    public function testDisplay(): void
    {
        $this->assertSame('Common', AddonReference::byName('Common')->display());
        $this->assertSame('Log:3.4.30', AddonReference::named(new NamedAddon('Log', '3.4.30'))->display());
        $this->assertSame('Log:3.4.30', AddonReference::byName('Log', '3.4.30')->display());
        $this->assertSame('https://***@example.org/M.zip', AddonReference::zip(HttpUrl::fromUrl('https://u:p@example.org/M.zip'))->display());
        $this->assertSame("'gh:o/a#v1' (https://github.com/o/a.git#v1)", AddonReference::git(GitRepository::parse('gh:o/a#v1'))->display());
    }

    public function testIsGitAndAddonId(): void
    {
        $this->assertTrue(AddonReference::git(GitRepository::parse('gh:o/a'))->isGit());
        $this->assertFalse(AddonReference::zip(HttpUrl::fromUrl('https://example.org/a.zip'))->isGit());
        $this->assertSame('Common', AddonReference::byName('Common', '1.0')->addonId());
        $this->assertNull(AddonReference::git(GitRepository::parse('gh:o/a'))->addonId());
    }
}
