<?php
namespace Tests\Downloader;

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
class GitDownloaderTest extends TestCase
{
    public function testRejectsARefThatGitWouldReadAsAnOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GitDownloader('https://example.org/repo.git', '--upload-pack=touch /tmp/pwned');
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

            $clone = (new GitDownloader($repo, 'v1'))->download();
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
