<?php
namespace Tests\Commands\Cli;

use Exception;
use OSC\Commands\Cli\SelfUpdateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelfUpdateCommand::class)]
class SelfUpdateCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/self_update_' . uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->file . '.phar');
    }

    public function testRefusesAFileThatIsNotAPhar(): void
    {
        file_put_contents($this->file, '<html>Not Found</html>');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The downloaded file is not a valid omeka-s-cli PHAR; nothing was changed.');
        SelfUpdateCommand::installableVersion($this->file);
    }

    public function testRefusesAnEmptyFile(): void
    {
        file_put_contents($this->file, '');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The downloaded file is not a valid omeka-s-cli PHAR; nothing was changed.');
        SelfUpdateCommand::installableVersion($this->file);
    }

    public function testReadsTheVersionOfAPhar(): void
    {
        $phar = $this->file . '.phar';
        $this->buildPhar($phar, '{"version":"9.9.9"}');
        $this->assertSame('9.9.9', SelfUpdateCommand::installableVersion($phar));
    }

    public function testRefusesAPharWithoutAVersion(): void
    {
        $phar = $this->file . '.phar';
        $this->buildPhar($phar, '{"name":"ghentcdh/omeka-s-cli"}');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The downloaded file is not a valid omeka-s-cli PHAR; nothing was changed.');
        SelfUpdateCommand::installableVersion($phar);
    }

    /**
     * Build a PHAR holding only composer.json. Creating a PHAR needs phar.readonly=0, which PHP accepts
     * only from php.ini or `php -d`, never from ini_set(): so it is built in a child process, and this
     * test runs whatever the PHPUnit process's own setting is.
     */
    private function buildPhar(string $path, string $composerJson): void
    {
        $script = 'list(, $path, $json) = $argv;'
            . '$phar = new Phar($path);'
            . '$phar->addFromString("composer.json", $json);'
            . '$phar->setStub("<?php __HALT_COMPILER();");';
        $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'phar.readonly=0', '-r', $script, $path, $composerJson]));
        exec($command . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, "building the fixture PHAR failed:\n" . implode("\n", $output));
        clearstatcache();
    }

    public function testAnIdenticalDownloadIsUpToDateWithoutOpeningIt(): void
    {
        // the running PHAR and an identical download share their PHAR alias, so the download can not be
        // opened while the CLI runs: the comparison must not need to open it (this content is no PHAR)
        file_put_contents($this->file, 'same bytes');
        file_put_contents($this->file . '.phar', 'same bytes');
        $this->assertTrue(SelfUpdateCommand::isSameFile($this->file, $this->file . '.phar'));
    }

    public function testADifferentDownloadIsNotTheSameFile(): void
    {
        file_put_contents($this->file, 'new release');
        file_put_contents($this->file . '.phar', 'old release');
        $this->assertFalse(SelfUpdateCommand::isSameFile($this->file, $this->file . '.phar'));
    }
}
