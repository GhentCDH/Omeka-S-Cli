<?php
namespace Tests\Helper;

use OSC\Exceptions\OfflineException;
use OSC\Helper\Offline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Offline::class)]
#[CoversClass(OfflineException::class)]
class OfflineTest extends TestCase
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

    public static function envValues(): array
    {
        return [
            '1' => ['1', true],
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            '0' => ['0', false],
            'false' => ['false', false],
            'empty' => ['', false],
            'other' => ['maybe', false],
        ];
    }

    #[DataProvider('envValues')]
    public function testTheEnvironmentVariableSwitchesOfflineMode(string $value, bool $offline): void
    {
        putenv(Offline::ENV . '=' . $value);

        $this->assertSame($offline, Offline::isEnabled());
    }

    public function testOnlineWhenTheVariableIsNotSet(): void
    {
        $this->assertFalse(Offline::isEnabled());
    }

    public function testEnableSetsTheVariableForChildProcesses(): void
    {
        Offline::enable();

        $this->assertTrue(Offline::isEnabled());
        $this->assertSame('1', getenv(Offline::ENV));
        $this->assertSame('1', $_ENV[Offline::ENV]);
    }

    public function testAssertOnlinePassesWhenOnline(): void
    {
        Offline::assertOnline('query the Omeka.org repository');

        $this->addToAssertionCount(1);
    }

    public function testAssertOnlineThrowsWhenOffline(): void
    {
        Offline::enable();

        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage(
            'Offline mode: cannot query the Omeka.org repository. Drop --offline or unset OMEKA_S_CLI_OFFLINE.'
        );

        Offline::assertOnline('query the Omeka.org repository');
    }

    public function testAssertOnlineSuggestsTheAlternative(): void
    {
        Offline::enable();

        $this->expectExceptionMessage(
            "Offline mode: cannot look up 'Common' in the module repositories. Pass a release ZIP URL/path or git "
            . 'source instead, or drop --offline / unset OMEKA_S_CLI_OFFLINE.'
        );

        Offline::assertOnline("look up 'Common' in the module repositories", 'pass a release ZIP URL/path or git source instead');
    }
}
