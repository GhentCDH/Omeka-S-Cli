<?php
namespace Tests\Blueprint;

use OSC\Blueprint\Blueprint;
use OSC\Blueprint\CoreInstaller;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\OfflineException;
use OSC\Helper\Offline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreInstaller::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(Offline::class)]
#[UsesClass(OfflineException::class)]
class CoreInstallerOfflineTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv(Offline::ENV);
        Offline::enable();
    }

    protected function tearDown(): void
    {
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
        if ($this->saved !== false) {
            putenv(Offline::ENV . '=' . $this->saved);
        }
    }

    public function testOfflineACoreWithoutVersionOrSourceAsksForOne(): void
    {
        $command = $this->createMock(AbstractCommand::class);
        $command->method('isOmekaDir')->willReturn(false);
        $command->expects($this->never())->method('app');

        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('Set preferredVersions.omeka (or x-omeka-s-cli.install.source) in the blueprint');

        (new CoreInstaller($command))->download(new Blueprint([]), '/tmp/omeka-s-offline-test');
    }
}
