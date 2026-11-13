<?php
namespace Tests\Commands;

use OSC\Blueprint\BlueprintValidator;
use OSC\Commands\Blueprint\AbstractBlueprintCommand;
use OSC\Helper\Offline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(AbstractBlueprintCommand::class)]
#[UsesClass(BlueprintValidator::class)]
#[UsesClass(Offline::class)]
class BlueprintOfflineSchemaTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv(Offline::ENV);
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
    }

    protected function tearDown(): void
    {
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
        if ($this->saved !== false) {
            putenv(Offline::ENV . '=' . $this->saved);
        }
    }

    private static function schemaSource(bool $selfContained): string
    {
        $command = new class ('test:blueprint') extends AbstractBlueprintCommand {
            public function execute(): void
            {
            }
        };
        $validator = (new ReflectionMethod($command, 'validator'))->invoke($command, $selfContained);
        return (new ReflectionProperty(BlueprintValidator::class, 'schemaSource'))->getValue($validator);
    }

    public function testOnlineTheSharedSchemaIsFetched(): void
    {
        $this->assertSame(BlueprintValidator::SCHEMA_SOURCE, self::schemaSource(false));
    }

    public function testOfflineTheBundledSchemaIsUsed(): void
    {
        Offline::enable();

        $this->assertSame(BlueprintValidator::bundledSchemaPath(), self::schemaSource(false));
    }
}
