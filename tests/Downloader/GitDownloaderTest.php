<?php
namespace Tests\Downloader;

use OSC\Source\GitRepository;
use OSC\Helper\UrlCredentials;
use OSC\Source\SourceForm;
use InvalidArgumentException;
use OSC\Addon\GitRef;
use OSC\Downloader\GitDownloader;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitDownloader::class)]
#[UsesClass(GitRef::class)]
#[UsesClass(Path::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(SourceForm::class)]
#[UsesClass(UrlCredentials::class)]
class GitDownloaderTest extends TestCase
{
    public function testRejectsARefThatGitWouldReadAsAnOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GitDownloader(GitRepository::parse('https://example.org/repo.git')->withRef('--upload-pack=touch /tmp/pwned'));
    }

    public function testChecksOutTheRef(): void
    {
        $repo = Path::createTempFolder('osc-git-test.');
        try {
            $git = fn(string $args) => exec('git -C ' . escapeshellarg($repo) . " -c user.email=t@example.org -c user.name=t {$args} 2>&1");
            $git('init -q -b main');
            file_put_contents("{$repo}/a.txt", 'one');
            $git('add a.txt');
            $git('commit -q -m one');
            $git('tag v1');
            file_put_contents("{$repo}/a.txt", 'two');
            $git('commit -q -am two');

            // a GitRepository is a URL (the spec's forms), so git maps this one to the local repository
            putenv('GIT_CONFIG_COUNT=1');
            putenv("GIT_CONFIG_KEY_0=url.{$repo}.insteadOf");
            putenv('GIT_CONFIG_VALUE_0=https://git.example.invalid/repo.git');
            try {
                $clone = (new GitDownloader(GitRepository::parse('https://git.example.invalid/repo.git#v1')))->download();
            } finally {
                putenv('GIT_CONFIG_COUNT');
                putenv('GIT_CONFIG_KEY_0');
                putenv('GIT_CONFIG_VALUE_0');
            }
            try {
                $this->assertSame('one', file_get_contents("{$clone}/a.txt"));
            } finally {
                Path::removeFolder($clone);
            }
        } finally {
            Path::removeFolder($repo);
        }
    }
}
