<?php
namespace Tests\Commands;

use OSC\Commands\AbstractCommand;
use OSC\Helper\Offline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractCommand::class)]
class AbstractCommandOfflineTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv(Offline::ENV);
        self::unsetEnv();
    }

    protected function tearDown(): void
    {
        self::unsetEnv();
        if ($this->saved !== false) {
            putenv(Offline::ENV . '=' . $this->saved);
        }
    }

    private static function unsetEnv(): void
    {
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
    }

    private function command(): AbstractCommand
    {
        return new class ('test:offline') extends AbstractCommand {
            public function execute(): void
            {
            }
        };
    }

    public function testEveryCommandTakesAGlobalOfflineOption(): void
    {
        $this->command()->parse(['omeka-s-cli', '--offline']);

        $this->assertTrue(Offline::isEnabled());
    }

    public function testWithoutTheOptionTheCommandStaysOnline(): void
    {
        $this->command()->parse(['omeka-s-cli']);

        $this->assertFalse(Offline::isEnabled());
    }
}
