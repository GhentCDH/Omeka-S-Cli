<?php
namespace Tests\Downloader;

use Closure;
use Exception;
use LogicException;
use OSC\Downloader\AddonResolver;
use OSC\Downloader\GitDownloader;
use OSC\Downloader\ResolvedAddon;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUri;
use OSC\Helper\VersionCompatibility;
use OSC\Manager\AbstractManager;
use OSC\Manager\Result;
use OSC\Repository\Module\ModuleDetails;
use OSC\Repository\Module\ModuleVersion;
use OSC\Repository\RepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonResolver::class)]
#[UsesClass(ResolvedAddon::class)]
#[UsesClass(ZipDownloader::class)]
#[UsesClass(GitDownloader::class)]
#[UsesClass(ResourceUriParser::class)]
#[UsesClass(ResourceUri::class)]
#[UsesClass(VersionCompatibility::class)]
#[UsesClass(ModuleDetails::class)]
#[UsesClass(ModuleVersion::class)]
#[UsesClass(Result::class)]
class AddonResolverTest extends TestCase
{
    private function resolver(?ModuleDetails $details): AddonResolver
    {
        $manager = $this->createMock(AbstractManager::class);
        $manager->method('find')->willReturn(
            $details ? new Result($details, $this->createMock(RepositoryInterface::class)) : null
        );
        return new AddonResolver($manager, 'module');
    }

    /** Common 1.0.0 supports Omeka S 4, Common 2.0.0 (the latest) only Omeka S 5. */
    private function common(): ModuleDetails
    {
        return new ModuleDetails('Common', 'Common', '2.0.0', [
            '1.0.0' => new ModuleVersion('1.0.0', '2024-01-01', 'https://example.org/Common-1.0.0.zip', '^4.0'),
            '2.0.0' => new ModuleVersion('2.0.0', '2025-01-01', 'https://example.org/Common-2.0.0.zip', '^5.0'),
        ]);
    }

    /** An Omeka S version callback that must not be called (no catalog lookup expected). */
    private function noLookup(): Closure
    {
        return fn() => throw new LogicException('no catalog lookup expected');
    }

    public function testIsGit(): void
    {
        $this->assertTrue(AddonResolver::isGit('gh:omeka-s-modules/CustomVocab'));
        $this->assertTrue(AddonResolver::isGit('gh:omeka-s-modules/CustomVocab#v2.1.0'));
        $this->assertTrue(AddonResolver::isGit('https://github.com/omeka-s-modules/CustomVocab.git#v2.1.0'));
        $this->assertTrue(AddonResolver::isGit('git@github.com:omeka-s-modules/CustomVocab.git'));
        $this->assertFalse(AddonResolver::isGit('https://example.org/CustomVocab-2.1.0.zip'));
        $this->assertFalse(AddonResolver::isGit('./modules/CustomVocab-2.1.0.zip'));
        $this->assertFalse(AddonResolver::isGit('CustomVocab:2.1.0'));
    }

    public function testAZipSourceNeedsNoCatalog(): void
    {
        $url = 'https://example.org/Mapping-2.1.0.zip';
        $resolved = $this->resolver(null)->resolve($url, $this->noLookup());

        $this->assertSame(ResolvedAddon::ZIP, $resolved->form);
        $this->assertInstanceOf(ZipDownloader::class, $resolved->downloader);
        $this->assertSame($url, $resolved->downloader->getDownloadUrl());
        $this->assertNull($resolved->dirname);

        $local = $this->resolver(null)->resolve('/bundle/modules/Mapping-2.1.0.zip', $this->noLookup());
        $this->assertSame(ResolvedAddon::ZIP, $local->form);
    }

    public function testAGitHubSourceClonesAtItsRef(): void
    {
        $resolved = $this->resolver(null)->resolve('gh:owner/repo#1.2.0', $this->noLookup());

        $this->assertSame(ResolvedAddon::GIT, $resolved->form);
        $this->assertSame('https://github.com/owner/repo.git', $resolved->downloader->getDownloadUrl());
        $this->assertSame('1.2.0', $resolved->downloader->getRef());
    }

    public function testACatalogNameSelectsTheLatestCompatibleRelease(): void
    {
        $resolved = $this->resolver($this->common())->resolve('common', fn() => '4.1.1');

        $this->assertSame(ResolvedAddon::CATALOG, $resolved->form);
        $this->assertSame('Common', $resolved->dirname);
        $this->assertSame('1.0.0', $resolved->version);
        $this->assertSame('https://example.org/Common-1.0.0.zip', $resolved->downloader->getDownloadUrl());
        $this->assertSame(["Cannot use module 'Common' latest version v2.0.0: incompatible with Omeka S 4.1.1."], $resolved->warnings);
        $this->assertSame(["Selected 'Common' v1.0.0 (compatible with Omeka S 4.1.1)."], $resolved->notes);
    }

    public function testAnIncompatiblePinnedReleaseIsRefusedUnlessForced(): void
    {
        $forced = $this->resolver($this->common())->resolve('Common:2.0.0', fn() => '4.1.1', true);
        $this->assertSame('2.0.0', $forced->version);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Cannot use module 'Common' version '2.0.0': incompatible with Omeka S 4.1.1.");
        $this->resolver($this->common())->resolve('Common:2.0.0', fn() => '4.1.1');
    }

    public function testAnUnknownNameOrVersionIsNotFound(): void
    {
        try {
            $this->resolver(null)->resolve('Nope', fn() => '4.1.1');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame("Could not find module 'Nope' in any repository.", $e->getMessage());
        }

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Module 'Common' has no version '9.9.9'.");
        $this->resolver($this->common())->resolve('Common:9.9.9', fn() => '4.1.1');
    }

    public function testUriForABlueprintEntry(): void
    {
        $this->assertSame('Common', AddonResolver::uriFor('Common', null, null));
        $this->assertSame('Log:3.4.30', AddonResolver::uriFor('Log', null, '3.4.30'));
        $this->assertSame('https://example.org/M-2.1.0.zip', AddonResolver::uriFor('M', 'https://example.org/M-2.1.0.zip', '2.0.0'));
        $this->assertSame('gh:owner/a#1.2.0', AddonResolver::uriFor('A', 'gh:owner/a', '1.2.0'));
        $this->assertSame('https://example.org/owner/b.git#1.2.0', AddonResolver::uriFor('B', 'https://example.org/owner/b.git', '1.2.0'));
        $this->assertSame('gh:owner/c#main', AddonResolver::uriFor('C', 'gh:owner/c#main', '1.2.0'));
    }
}
