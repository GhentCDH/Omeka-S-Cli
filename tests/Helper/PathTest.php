<?php
namespace Tests\Helper;

use LogicException;
use OSC\Helper\Path;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Path::class)]
class PathTest extends TestCase
{
    private string $tmp;

    /** @var string[] directories a test made read-only, restored before cleanup */
    private array $readOnly = [];

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/path_test_' . uniqid();
        mkdir($this->tmp, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->readOnly as $dir) {
            chmod($dir, 0700);
        }
        if (is_file("{$this->tmp}/unreadable")) {
            chmod("{$this->tmp}/unreadable", 0600);
        }
        Path::removeFolder($this->tmp);
    }

    /** Permissions do not bind root: tests that rely on them skip. */
    private function skipIfRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
    }

    /** A directory nobody but root can write to; skips the test when running as root. */
    private function readOnlyDir(): string
    {
        $this->skipIfRoot();
        $dir = "{$this->tmp}/ro";
        mkdir($dir, 0555);
        $this->readOnly[] = $dir;
        return $dir;
    }

    public function testSafeFolderNames(): void
    {
        foreach (['freedom', 'Foundation_S', 'my-theme', 'theme.v2'] as $name) {
            $this->assertTrue(Path::isSafeFolderName($name), $name);
        }
        foreach (['', '.', '..', 'a/b', '../x', 'with space', 'a\\b'] as $name) {
            $this->assertFalse(Path::isSafeFolderName($name), $name);
        }
    }

    public function testWriteFileWritesTheContents(): void
    {
        Path::writeFile("{$this->tmp}/a.txt", "x\n");
        $this->assertSame("x\n", file_get_contents("{$this->tmp}/a.txt"));
    }

    public function testWriteFileFailureNamesThePathAndTheReason(): void
    {
        $path = $this->readOnlyDir() . '/a.txt';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not write '{$path}': Permission denied.");
        Path::writeFile($path, 'x');
    }

    public function testWriteFileUsesTheGivenFailure(): void
    {
        $path = $this->readOnlyDir() . '/b.jsonc';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not write the blueprint to 'b.jsonc': Permission denied.");
        Path::writeFile($path, 'x', "Could not write the blueprint to 'b.jsonc'.");
    }

    public function testCheckedReturnsTheResult(): void
    {
        $this->assertSame(3, Path::checked(fn() => file_put_contents("{$this->tmp}/b", 'abc'), 'x'));
    }

    public function testCheckedWithoutAWarningOmitsTheReason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not do it.');
        try {
            Path::checked(fn() => false, 'Could not do it.');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not do it.', $e->getMessage());
            throw $e;
        }
    }

    public function testCheckedKeepsTheReasonWhenAnotherHandlerSwallowsErrors(): void
    {
        $path = $this->readOnlyDir() . '/a.txt';
        set_error_handler(fn() => true);
        try {
            Path::writeFile($path, 'x');
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringEndsWith(': Permission denied.', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testCheckedRestoresThePreviousHandler(): void
    {
        $mine = fn() => true;
        set_error_handler($mine);
        try {
            try {
                Path::checked(function () {
                    trigger_error('noise', E_USER_WARNING);
                    return false;
                }, 'x');
            } catch (RuntimeException) {
            }
            $current = set_error_handler(fn() => true);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
        $this->assertSame($mine, $current);
    }

    public function testCheckedRestoresThePreviousHandlerWhenTheOperationThrows(): void
    {
        $mine = fn() => true;
        set_error_handler($mine);
        try {
            try {
                Path::checked(fn() => throw new LogicException('boom'), 'x');
                $this->fail('expected the LogicException to bubble');
            } catch (LogicException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $current = set_error_handler(fn() => true);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
        $this->assertSame($mine, $current);
    }

    public function testCheckedSwallowsWarningsOfASuccessfulCall(): void
    {
        $result = Path::checked(function () {
            trigger_error('noise', E_USER_NOTICE);
            return true;
        }, 'x');
        $this->assertTrue($result);
    }

    public function testEnsureDirCreatesNestedDirectories(): void
    {
        Path::ensureDir("{$this->tmp}/a/b/c");
        $this->assertDirectoryExists("{$this->tmp}/a/b/c");
    }

    public function testEnsureDirAcceptsAnExistingDirectory(): void
    {
        Path::ensureDir($this->tmp);
        $this->assertDirectoryExists($this->tmp);
    }

    public function testEnsureDirRefusesAFile(): void
    {
        $file = "{$this->tmp}/f";
        touch($file);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not create directory '{$file}': File exists.");
        Path::ensureDir($file);
    }

    public function testEnsureDirFailureNamesTheReason(): void
    {
        $dir = $this->readOnlyDir() . '/sub';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not create directory '{$dir}': Permission denied.");
        Path::ensureDir($dir);
    }

    public function testMoveRenamesAFile(): void
    {
        file_put_contents("{$this->tmp}/from", 'abc');
        Path::move("{$this->tmp}/from", "{$this->tmp}/to");
        $this->assertFileDoesNotExist("{$this->tmp}/from");
        $this->assertSame('abc', file_get_contents("{$this->tmp}/to"));
    }

    public function testMoveFailureNamesTheReason(): void
    {
        $from = "{$this->tmp}/from";
        file_put_contents($from, 'abc');
        $to = $this->readOnlyDir() . '/to';
        try {
            Path::move($from, $to);
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame("Could not move '{$from}' to '{$to}': Permission denied.", $e->getMessage());
        }
        $this->assertFileExists($from);
    }

    public function testCopyFileFailureNamesTheReason(): void
    {
        $from = "{$this->tmp}/missing";
        $to = "{$this->tmp}/to";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not copy '{$from}' to '{$to}': No such file or directory.");
        Path::copyFile($from, $to);
    }

    /** A file nobody but root can read; skips the test when running as root. */
    private function unreadableFile(string $contents = 'x'): string
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read any file');
        }
        $file = "{$this->tmp}/unreadable";
        file_put_contents($file, $contents);
        chmod($file, 0000);
        return $file;
    }

    public function testReadFileReturnsTheContents(): void
    {
        file_put_contents("{$this->tmp}/a.txt", "x\n");
        $this->assertSame("x\n", Path::readFile("{$this->tmp}/a.txt"));
    }

    public function testReadFileOfAMissingFileNamesTheReason(): void
    {
        $file = "{$this->tmp}/missing";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not read '{$file}': No such file or directory.");
        Path::readFile($file);
    }

    public function testReadFileOfAnUnreadableFileNamesTheReason(): void
    {
        $file = $this->unreadableFile();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not read '{$file}': Permission denied.");
        Path::readFile($file);
    }

    public function testReadIniFileReturnsTheValues(): void
    {
        $file = "{$this->tmp}/a.ini";
        file_put_contents($file, "[info]\nname = \"X\"\n");
        $this->assertSame(['info' => ['name' => 'X']], Path::readIniFile($file, true));
        $this->assertSame(['name' => 'X'], Path::readIniFile($file));
    }

    public function testReadIniFileNamesTheSyntaxError(): void
    {
        $file = "{$this->tmp}/broken.ini";
        file_put_contents($file, "key = value\n[broken\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Could not parse '{$file}': syntax error, unexpected end of file, expecting ']' on line 2."
        );
        Path::readIniFile($file);
    }

    public function testReadIniFileOfAnUnreadableFileIsAReadError(): void
    {
        $file = $this->unreadableFile("a = b\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not read '{$file}': Permission denied.");
        Path::readIniFile($file);
    }

    public function testReadIniFileUsesTheGivenFailureForASyntaxError(): void
    {
        $file = "{$this->tmp}/broken.ini";
        file_put_contents($file, "key = value\n[broken\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Bad ini: syntax error, unexpected end of file, expecting ']' on line 2.");
        Path::readIniFile($file, false, 'Bad ini');
    }

    public function testReadIniFileUsesTheGivenFailureForAReadError(): void
    {
        $file = $this->unreadableFile("a = b\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Bad ini: Permission denied.');
        Path::readIniFile($file, false, 'Bad ini');
    }

    public function testMoveFallsBackToCopyWhenOnlyTheTargetFileIsWritable(): void
    {
        // rename() needs the target directory to be writable; overwriting an existing file does not
        $from = "{$this->tmp}/from";
        file_put_contents($from, 'new');
        $dir = "{$this->tmp}/target";
        mkdir($dir, 0700);
        file_put_contents("{$dir}/to", 'old');
        $this->readOnly[] = $dir;
        $this->skipIfRoot();
        chmod($dir, 0555);

        Path::move($from, "{$dir}/to");

        $this->assertSame('new', file_get_contents("{$dir}/to"));
        $this->assertFileDoesNotExist($from);
    }

    public function testMoveFallbackGivesTheTargetTheSourceMode(): void
    {
        $from = "{$this->tmp}/from";
        file_put_contents($from, 'new');
        chmod($from, 0750);
        $dir = "{$this->tmp}/target";
        mkdir($dir, 0700);
        file_put_contents("{$dir}/to", 'old');
        chmod("{$dir}/to", 0640);
        $this->readOnly[] = $dir;
        $this->skipIfRoot();
        chmod($dir, 0555);

        Path::move($from, "{$dir}/to");

        clearstatcache();
        $this->assertSame(0750, fileperms("{$dir}/to") & 0777);
    }

    public function testMoveWhoseSourceCannotBeRemovedStillMovesIt(): void
    {
        // the copy is complete; a source that cannot be removed is left behind, not reported as a failed move
        $dir = "{$this->tmp}/source";
        mkdir($dir, 0700);
        file_put_contents("{$dir}/from", 'abc');
        $this->readOnly[] = $dir;
        $this->skipIfRoot();
        chmod($dir, 0555);

        Path::move("{$dir}/from", "{$this->tmp}/to");

        $this->assertSame('abc', file_get_contents("{$this->tmp}/to"));
    }
}
