<?php
namespace Tests\Helper\Reference;

use OSC\Helper\Reference\GitHubProvider;
use OSC\Helper\Reference\GitLabProvider;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\Reference\RepoProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceResolver::class)]
#[UsesClass(GitHubProvider::class)]
#[UsesClass(GitLabProvider::class)]
class ReferenceResolverTest extends TestCase
{
    private ReferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = ReferenceResolver::withDefaults();
    }

    public function testConvertsRepoBlobUrlToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/site.jsonc',
            $this->resolver->resolve('https://github.com/owner/repo/blob/main/site.jsonc')
        );
    }

    public function testConvertsShortSchemeToRaw(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/site.jsonc',
            $this->resolver->resolve('gh:owner/repo@main:site.jsonc')
        );
    }

    public function testReturnsPlainUrlUnchanged(): void
    {
        $url = 'https://example.org/dir/site.jsonc';
        $this->assertSame($url, $this->resolver->resolve($url));
    }

    public function testReturnsAbsoluteLocalPathUnchanged(): void
    {
        $this->assertSame('/abs/dir/site.jsonc', $this->resolver->resolve('/abs/dir/site.jsonc'));
    }

    public function testResolvesRelativeFilenameAgainstUrlBase(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/other.jsonc',
            $this->resolver->resolve('other.jsonc', 'https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc')
        );
    }

    public function testResolvesRelativeSubdirAgainstUrlBase(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/sub/other.jsonc',
            $this->resolver->resolve('sub/other.jsonc', 'https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc')
        );
    }

    public function testResolvesParentTraversalAgainstUrlBase(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/other.jsonc',
            $this->resolver->resolve('../other.jsonc', 'https://raw.githubusercontent.com/owner/repo/main/dir/site.jsonc')
        );
    }

    public function testNormalizesBlobUrlBaseBeforeResolving(): void
    {
        $this->assertSame(
            'https://raw.githubusercontent.com/owner/repo/main/dir/other.jsonc',
            $this->resolver->resolve('other.jsonc', 'https://github.com/owner/repo/blob/main/dir/site.jsonc')
        );
    }

    public function testResolvesRelativeAgainstLocalBase(): void
    {
        $this->assertSame(
            '/tmp/dir/other.jsonc',
            $this->resolver->resolve('other.jsonc', '/tmp/dir/site.jsonc')
        );
    }

    public function testRelativeWithoutBaseReturnedUnchanged(): void
    {
        $this->assertSame('other.jsonc', $this->resolver->resolve('other.jsonc'));
    }

    public function testProvidersAreInjectable(): void
    {
        $fake = new class implements RepoProvider {
            public function supports(string $reference): bool
            {
                return str_starts_with($reference, 'fake:');
            }
            public function toRawUrl(string $reference): string
            {
                return 'https://example.org/' . substr($reference, 5);
            }
        };
        $resolver = new ReferenceResolver([$fake]);

        $this->assertSame('https://example.org/a.jsonc', $resolver->resolve('fake:a.jsonc'));
        // a github reference is not converted because no GitHub provider was injected
        $this->assertSame('gh:o/r@main:a.jsonc', $resolver->resolve('gh:o/r@main:a.jsonc'));
    }
}
