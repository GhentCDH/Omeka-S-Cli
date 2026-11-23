<?php
namespace Tests\Commands;

use Exception;
use OSC\Blueprint\BlueprintValidator;
use OSC\Commands\Blueprint\AbstractBlueprintCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A loaded blueprint is validated as written: its relative sources are absolute paths once resolved,
 * which the shared schema (from v0.2.0) rejects. The test schema here rejects absolute file sources
 * the same way.
 */
#[CoversClass(AbstractBlueprintCommand::class)]
class BlueprintLoadValidTest extends TestCase
{
    private string $dir;
    private string $schema;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bp_load_valid_' . uniqid();
        mkdir($this->dir . '/lists', 0777, true);
        $schema = json_decode(file_get_contents(BlueprintValidator::bundledSchemaPath()), true);
        $schema['$defs']['file']['properties']['source']['pattern'] = '^(?![/\\\\])';
        $this->schema = $this->dir . '/schema.json';
        file_put_contents($this->schema, json_encode($schema));
    }

    protected function tearDown(): void
    {
        foreach (['lists/files.json', 'blueprint.json', 'schema.json'] as $file) {
            @unlink("{$this->dir}/{$file}");
        }
        @rmdir("{$this->dir}/lists");
        @rmdir($this->dir);
    }

    /** @return array{0: AbstractBlueprintCommand, 1: \ArrayObject} The command, and the errors it printed */
    private function command(): array
    {
        $printed = new \ArrayObject();
        $command = new class ('test:blueprint', $this->schema, $printed) extends AbstractBlueprintCommand {
            public function __construct(string $name, private string $schema, private \ArrayObject $printed)
            {
                parent::__construct($name);
            }
            public function execute(): void
            {
            }
            protected function validator(bool $selfContained): BlueprintValidator
            {
                return new BlueprintValidator($this->schema);
            }
            public function note(string $message, bool $eol = false): void
            {
            }
            public function warn(string $message, bool $eol = false): void
            {
            }
            public function error(string $message, bool $eol = false): void
            {
                $this->printed[] = $message;
            }
        };
        return [$command, $printed];
    }

    private function load(AbstractBlueprintCommand $command): mixed
    {
        return (new ReflectionMethod($command, 'loadValidBlueprint'))
            ->invoke($command, 'deploy', "{$this->dir}/blueprint.json", null, false, false);
    }

    public function testRelativeSourcesAreValidatedAsWritten(): void
    {
        file_put_contents("{$this->dir}/lists/files.json", json_encode([['source' => './b.php', 'destination' => 'config/b.php']]));
        file_put_contents("{$this->dir}/blueprint.json", json_encode(['files' => [
            ['source' => './a.php', 'destination' => 'config/a.php'],
            ['$import' => './lists/files.json'],
        ]]));
        [$command] = $this->command();

        $files = $this->load($command)->files();
        $this->assertSame("{$this->dir}/a.php", $files[0]['source']);
        $this->assertSame("{$this->dir}/lists/b.php", $files[1]['source']);
    }

    public function testAnInvalidImportIsNamedInTheErrors(): void
    {
        file_put_contents("{$this->dir}/lists/files.json", json_encode([['source' => './b.php']]));
        file_put_contents("{$this->dir}/blueprint.json", json_encode(['files' => [['$import' => './lists/files.json']]]));
        [$command, $printed] = $this->command();

        try {
            $this->load($command);
            $this->fail('the blueprint is invalid');
        } catch (Exception $e) {
            $this->assertStringContainsString('invalid blueprint', $e->getMessage());
        }
        $this->assertStringContainsString('lists/files.json: /0', implode("\n", (array) $printed));
    }
}
