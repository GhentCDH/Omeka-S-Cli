<?php
namespace Tests\Core;

use OSC\Core\CoreSwap;
use OSC\Core\CoreSwapException;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoreSwap::class)]
#[CoversClass(CoreSwapException::class)]
#[UsesClass(Path::class)]
class CoreSwapTest extends TestCase
{
    private string $tmp;
    private string $root;
    private string $release;

    /** The live files a swap must leave alone, with their contents. */
    private const USER_FILES = [
        'config/database.ini' => 'dbname = "live"',
        'files/original/f.jpg' => 'jpg',
        'modules/M/Module.php' => '<?php namespace M;',
        'themes/T/theme.ini' => '[info]',
        'logs/application.log' => 'log',
    ];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/core_swap_' . uniqid();
        $this->root = "{$this->tmp}/root";
        $this->release = "{$this->tmp}/release";

        $this->files($this->root, [
            'application/a.php' => 'old',
            'vendor/v.php' => 'old',
            'index.php' => 'old',
            '.htaccess' => 'old rules',
        ] + self::USER_FILES);
        $this->files($this->release, [
            'application/a.php' => 'new',
            'vendor/v.php' => 'new',
            'index.php' => 'new',
            '.htaccess' => 'new rules',
            'config/database.ini' => 'dbname = ""',
        ]);
        foreach (['files', 'modules', 'themes', 'logs'] as $dir) {
            mkdir("{$this->release}/{$dir}");
        }
    }

    protected function tearDown(): void
    {
        chmod($this->tmp, 0700);
        if (is_file("{$this->release}/index.php")) {
            chmod("{$this->release}/index.php", 0600);
        }
        Path::removeFolder($this->tmp);
    }

    /** @param array<string,string> $files relative path => contents */
    private function files(string $base, array $files): void
    {
        foreach ($files as $path => $contents) {
            Path::ensureDir(dirname("{$base}/{$path}"));
            file_put_contents("{$base}/{$path}", $contents);
        }
    }

    /** @return array<string,string> relative path => contents of every file under $base */
    private function tree(string $base): array
    {
        $tree = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $tree[substr($file->getPathname(), strlen($base) + 1)] = file_get_contents($file->getPathname());
        }
        ksort($tree);
        return $tree;
    }

    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
    }

    /** @return string[] staging directories left in $dir */
    private function stages(string $dir): array
    {
        return glob("{$dir}/.omeka-s-cli-update-*", GLOB_ONLYDIR) ?: [];
    }

    /**
     * A rename that fails on call number $failOn, and on every later call whose source is in $alwaysFail.
     *
     * @param string[] $alwaysFail
     */
    private function failingRename(int $failOn, array &$alwaysFail = []): \Closure
    {
        $calls = 0;
        return function (string $from, string $to) use (&$calls, $failOn, &$alwaysFail): void {
            $calls++;
            if ($calls === $failOn || ($calls > $failOn && in_array($from, $alwaysFail, true))) {
                throw new RuntimeException('boom');
            }
            Path::checked(fn() => rename($from, $to), "Could not move '{$from}' to '{$to}'");
        };
    }

    public function testSwapReplacesTheCoreAndKeepsUserFolders(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        foreach (['application/a.php', 'vendor/v.php', 'index.php'] as $file) {
            $this->assertSame('new', file_get_contents("{$this->root}/{$file}"), $file);
        }
        $this->assertSame('new rules', file_get_contents("{$this->root}/.htaccess"));
        foreach (self::USER_FILES as $file => $contents) {
            $this->assertSame($contents, file_get_contents("{$this->root}/{$file}"), $file);
        }
        $swap->cleanup();
    }

    public function testAReadOnlyFileIsReplaced(): void
    {
        chmod("{$this->root}/.htaccess", 0444);

        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        $this->assertSame('new rules', file_get_contents("{$this->root}/.htaccess"));
        $swap->cleanup();
    }

    public function testAFailedSwapRestoresTheOldCore(): void
    {
        $before = $this->tree($this->root);
        $swap = new CoreSwap($this->root, $this->failingRename(5));
        $swap->stage($this->release);

        try {
            $swap->swap();
            $this->fail('expected a CoreSwapException');
        } catch (CoreSwapException $e) {
            $this->assertTrue($e->restored());
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertStringContainsString('The previous core was restored.', $e->getMessage());
        }

        $this->assertSame($before, $this->tree($this->root));
        $this->assertNull($swap->cleanup());
        $this->assertDirectoryDoesNotExist($swap->stagePath());
    }

    public function testAFailedRollbackKeepsTheStage(): void
    {
        $alwaysFail = [];
        $swap = new CoreSwap($this->root, $this->failingRename(5, $alwaysFail));
        $swap->stage($this->release);
        $alwaysFail = ["{$this->root}/application", "{$swap->stagePath()}/old/application"];

        try {
            $swap->swap();
            $this->fail('expected a CoreSwapException');
        } catch (CoreSwapException $e) {
            $this->assertFalse($e->restored());
            $this->assertStringContainsString('Could not restore: application', $e->getMessage());
            $this->assertStringContainsString("{$swap->stagePath()}/old", $e->getMessage());
        }

        $warning = $swap->cleanup();
        $this->assertNotNull($warning);
        $this->assertStringContainsString($swap->stagePath(), $warning);
        $this->assertDirectoryExists($swap->stagePath());
    }

    public function testAFailedStageChangesNothing(): void
    {
        $this->skipIfRoot();
        $before = $this->tree($this->root);
        chmod("{$this->release}/index.php", 0000);

        try {
            (new CoreSwap($this->root))->stage($this->release);
            $this->fail('expected the stage to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        }

        $this->assertSame($before, $this->tree($this->root));
        $this->assertSame([], $this->stages($this->tmp));
        $this->assertSame([], $this->stages($this->root));
    }

    public function testStagingOutsideTheRootWhenTheParentIsWritable(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);

        $this->assertSame(realpath($this->tmp), dirname($swap->stagePath()));
        $this->assertMatchesRegularExpression('/^\.omeka-s-cli-update-[0-9a-f]{32}$/', basename($swap->stagePath()));
        $this->assertFalse($swap->isStagedInsideRoot());
        $swap->cleanup();
    }

    public function testStagingInsideTheRootIsLockedDown(): void
    {
        $this->skipIfRoot();
        chmod($this->tmp, 0555);

        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);

        $this->assertSame(realpath($this->root), dirname($swap->stagePath()));
        $this->assertTrue($swap->isStagedInsideRoot());
        $this->assertSame(0700, fileperms($swap->stagePath()) & 0777);
        $this->assertStringContainsString('Require all denied', file_get_contents("{$swap->stagePath()}/.htaccess"));
        $swap->cleanup();
    }

    public function testAChangedHtaccessIsSaved(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        $saved = $swap->saveChangedHtaccess();

        $this->assertNotNull($saved);
        $this->assertStringStartsWith(realpath($this->root) . '/.htaccess.bak-', $saved);
        $this->assertSame('old rules', file_get_contents($saved));
        $swap->cleanup();
    }

    public function testAnUnchangedHtaccessIsNotSaved(): void
    {
        file_put_contents("{$this->release}/.htaccess", 'old rules');
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        $this->assertNull($swap->saveChangedHtaccess());
        $swap->cleanup();
    }

    public function testKeepBackupMovesTheReplacedFiles(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        $swap->keepBackup("{$this->tmp}/backup");

        $this->assertSame('old', file_get_contents("{$this->tmp}/backup/application/a.php"));
        $this->assertNull($swap->cleanup());
        $this->assertDirectoryDoesNotExist($swap->stagePath());
    }

    public function testAnInterruptedSwapKeepsTheStage(): void
    {
        // the shutdown function runs cleanup() when the process dies; mid-swap, old/ holds the only copy
        $swap = null;
        $midSwap = null;
        $calls = 0;
        $swap = new CoreSwap($this->root, function (string $from, string $to) use (&$swap, &$midSwap, &$calls): void {
            if (++$calls === 3) {
                $midSwap = $swap->cleanup();
            }
            Path::checked(fn() => rename($from, $to), "Could not move '{$from}' to '{$to}'");
        });
        $swap->stage($this->release);
        $swap->swap();

        $this->assertNotNull($midSwap);
        $this->assertStringContainsString($swap->stagePath(), $midSwap);
        $this->assertDirectoryExists($swap->stagePath() . '/old');
        $swap->cleanup();
    }

    public function testAFailedHtaccessSaveKeepsThePreviousFiles(): void
    {
        $this->skipIfRoot();
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();
        chmod($this->root, 0555);   // the .htaccess.bak-… copy can not be written

        try {
            $swap->saveChangedHtaccess();
            $this->fail('expected the save to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("{$swap->stagePath()}/old", $e->getMessage());
        } finally {
            chmod($this->root, 0700);
        }

        $this->assertNotNull($swap->cleanup());
        $this->assertSame('old rules', file_get_contents("{$swap->stagePath()}/old/.htaccess"));
    }

    public function testAnUnreadablePreviousHtaccessKeepsThePreviousFiles(): void
    {
        $this->skipIfRoot();
        chmod("{$this->root}/.htaccess", 0000);
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();

        try {
            $swap->saveChangedHtaccess();
            $this->fail('expected the save to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("{$swap->stagePath()}/old", $e->getMessage());
        } finally {
            chmod("{$swap->stagePath()}/old/.htaccess", 0600);
        }

        $this->assertNotNull($swap->cleanup());
        $this->assertFileExists("{$swap->stagePath()}/old/.htaccess");
    }

    public function testAFailedBackupKeepsThePreviousFiles(): void
    {
        $this->skipIfRoot();
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();
        mkdir("{$this->tmp}/ro", 0555);

        try {
            $swap->keepBackup("{$this->tmp}/ro/backup");
            $this->fail('expected the backup to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("{$swap->stagePath()}/old", $e->getMessage());
        } finally {
            chmod("{$this->tmp}/ro", 0700);
        }

        $this->assertNotNull($swap->cleanup());
        $this->assertSame('old', file_get_contents("{$swap->stagePath()}/old/application/a.php"));
    }

    public function testStagingOutsideTheRootIsLockedDownToo(): void
    {
        // the parent of the Omeka S root can itself be in the web root (/var/www/html/omeka-s)
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);

        $this->assertFalse($swap->isStagedInsideRoot());
        $this->assertSame(0700, fileperms($swap->stagePath()) & 0777);
        $this->assertStringContainsString('Require all denied', file_get_contents("{$swap->stagePath()}/.htaccess"));
        $swap->cleanup();
    }

    public function testAStageThatCanNotBeRemovedKeepsItsDenyRule(): void
    {
        $this->skipIfRoot();
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);
        $swap->swap();
        chmod("{$swap->stagePath()}/old/application", 0555);   // a.php can not be removed

        try {
            $this->assertNotNull($swap->cleanup());
            $this->assertFileExists("{$swap->stagePath()}/.htaccess");
        } finally {
            chmod("{$swap->stagePath()}/old/application", 0700);
        }
    }

    public function testTheDenyRuleAlsoTurnsOffDirectoryListings(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release);

        $this->assertStringContainsString('Options -Indexes', file_get_contents("{$swap->stagePath()}/.htaccess"));
        $swap->cleanup();
    }

    public function testAGivenStageDirIsUsed(): void
    {
        mkdir("{$this->tmp}/stage-here");
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release, "{$this->tmp}/stage-here");

        $this->assertSame(realpath("{$this->tmp}/stage-here"), dirname($swap->stagePath()));
        $this->assertFalse($swap->isStagedInsideRoot());
        $swap->swap();
        $this->assertSame('new', file_get_contents("{$this->root}/index.php"));
        $swap->cleanup();
    }

    public function testAGivenStageDirInsideTheRootCountsAsInside(): void
    {
        $swap = new CoreSwap($this->root);
        $swap->stage($this->release, "{$this->root}/logs");

        $this->assertTrue($swap->isStagedInsideRoot());
        $swap->cleanup();
    }

    public function testAStageDirThatDoesNotExistIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot stage the new core files in '{$this->tmp}/missing': it is not a directory.");
        CoreSwap::assertStageDir("{$this->tmp}/missing", $this->root);
    }

    public function testAStageDirThatIsNotWritableIsRefused(): void
    {
        $this->skipIfRoot();
        mkdir("{$this->tmp}/ro", 0555);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Cannot stage the new core files in '{$this->tmp}/ro': it is not writable.");
            CoreSwap::assertStageDir("{$this->tmp}/ro", $this->root);
        } finally {
            chmod("{$this->tmp}/ro", 0700);
        }
    }

    public function testAStageDirOnAnotherFilesystemIsRefused(): void
    {
        $other = '/dev/shm';
        if (!is_dir($other) || !is_writable($other) || @stat($other)['dev'] === @stat($this->root)['dev']) {
            $this->markTestSkipped("{$other} is not a writable directory on another filesystem");
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot stage the new core files in '{$other}': it is not on the same filesystem as '" . realpath($this->root) . "'");
        CoreSwap::assertStageDir($other, $this->root);
    }

    public function testStageChecksTheGivenStageDir(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('it is not a directory');
        (new CoreSwap($this->root))->stage($this->release, "{$this->tmp}/missing");
    }
}
