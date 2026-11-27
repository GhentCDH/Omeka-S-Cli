<?php
namespace Tests\Commands\Resource;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Resource\AbstractResourceCommand;
use OSC\Commands\Resource\DeleteCommand;
use OSC\Resource\ResourceType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * What item:delete/item-set:delete were given is checked before Omeka S is bootstrapped, so these run
 * without an instance.
 */
#[CoversClass(DeleteCommand::class)]
#[CoversClass(AbstractResourceCommand::class)]
#[UsesClass(AbstractCommand::class)]
#[UsesClass(ResourceType::class)]
class DeleteCommandTest extends TestCase
{
    /** Parse $argv as the command line would, then run the command with the parsed arguments. */
    private function runCommand(ResourceType $type, array $argv): void
    {
        $command = new DeleteCommand($type);
        $command->parse(["{$type->commandPrefix()}:delete", ...$argv]);
        $values = $command->values();
        $command->execute($values['ids'] ?: null, $values['ignoreNotFound'], $values['dryRun'], $values['force']);
    }

    public function testNothingSelectedIsAnError(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Give the IDs of the items to delete, or filters that select them (as for item:list).');
        $this->runCommand(ResourceType::Item, []);
    }

    public function testIdsAndFiltersTogetherIsAnError(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Give either IDs or filters, not both.');
        $this->runCommand(ResourceType::Item, ['5', '--public']);
    }

    /** @return array<string,array{0:string}> */
    public static function badIds(): array
    {
        return ['letter' => ['12a'], 'list' => ['1,2'], 'zero' => ['0'], 'negative' => ['-3']];
    }

    /** An ID is exact: `12a` is never read as 12 (as a (int) cast would). */
    #[DataProvider('badIds')]
    public function testAnIdMustBeAPositiveInteger(string $id): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("'{$id}' is not an item set ID.");
        $this->runCommand(ResourceType::ItemSet, ['--', $id]);
    }

    public function testFiltersNeedForce(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Deleting items by filters needs --force; use --dry-run first to list them.');
        $this->runCommand(ResourceType::Item, ['--property', 'dcterms:title=X']);
    }

    public function testTheCommandsAreRegistered(): void
    {
        $names = array_map(fn($command) => $command->name(), require __DIR__ . '/../../../src/Commands/Resource/Index.php');
        $this->assertContains('item:delete', $names);
        $this->assertContains('item-set:delete', $names);
    }
}
