<?php

namespace OSC\Tests\Commands;

use Ahc\Cli\Input\Command;
use OSC\Commands\CodeSnippets\ActivateCommand;
use OSC\Commands\CodeSnippets\DeactivateCommand;
use OSC\Commands\CodeSnippets\DeleteCommand;
use OSC\Commands\CodeSnippets\ExportCommand;
use OSC\Commands\CodeSnippets\ImportCommand;
use OSC\Commands\CodeSnippets\ListCommand;
use OSC\Commands\CodeSnippets\ShowCommand;
use PHPUnit\Framework\TestCase;

class CodeSnippetsTest extends TestCase
{
    public function testIndexReturnsAllCommands(): void
    {
        $commands = require __DIR__ . '/../../src/Commands/CodeSnippets/Index.php';

        $this->assertIsArray($commands);
        $this->assertCount(7, $commands);

        $names = array_map(fn(Command $cmd) => $cmd->name(), $commands);

        $this->assertContains('code-snippet:list', $names);
        $this->assertContains('code-snippet:show', $names);
        $this->assertContains('code-snippet:export', $names);
        $this->assertContains('code-snippet:import', $names);
        $this->assertContains('code-snippet:activate', $names);
        $this->assertContains('code-snippet:deactivate', $names);
        $this->assertContains('code-snippet:delete', $names);
    }

    public function testAllCommandsAreRegisteredInGlobalIndex(): void
    {
        $commands = require __DIR__ . '/../../src/Commands/Index.php';

        $this->assertIsArray($commands);
        $names = array_map(fn(Command $cmd) => $cmd->name(), $commands);

        $this->assertContains('code-snippet:list', $names);
        $this->assertContains('code-snippet:show', $names);
        $this->assertContains('code-snippet:export', $names);
        $this->assertContains('code-snippet:import', $names);
        $this->assertContains('code-snippet:activate', $names);
        $this->assertContains('code-snippet:deactivate', $names);
        $this->assertContains('code-snippet:delete', $names);
    }

    public function testListCommandConfiguration(): void
    {
        $command = new ListCommand();
        $this->assertSame('code-snippet:list', $command->name());
        $this->assertArrayHasKey('json', $command->allOptions());
        $this->assertArrayHasKey('csv', $command->allOptions());
    }

    public function testShowCommandConfiguration(): void
    {
        $command = new ShowCommand();
        $this->assertSame('code-snippet:show', $command->name());
        $this->assertArrayHasKey('json', $command->allOptions());
        $this->assertArrayHasKey('ignoreNotFound', $command->allOptions());
        $this->assertArrayHasKey('id', $command->allArguments());
    }

    public function testExportCommandConfiguration(): void
    {
        $command = new ExportCommand();
        $this->assertSame('code-snippet:export', $command->name());
        $this->assertArrayHasKey('output', $command->allOptions());
        $this->assertArrayHasKey('ignoreNotFound', $command->allOptions());
        $this->assertArrayNotHasKey('json', $command->allOptions());
    }

    public function testImportCommandConfiguration(): void
    {
        $command = new ImportCommand();
        $this->assertSame('code-snippet:import', $command->name());
        $this->assertArrayHasKey('json', $command->allOptions());
        $this->assertArrayHasKey('source', $command->allArguments());
    }

    public function testActivateCommandConfiguration(): void
    {
        $command = new ActivateCommand();
        $this->assertSame('code-snippet:activate', $command->name());
        $this->assertArrayHasKey('json', $command->allOptions());
        $this->assertArrayHasKey('ignoreNotFound', $command->allOptions());
        $this->assertArrayHasKey('id', $command->allArguments());
    }

    public function testDeactivateCommandConfiguration(): void
    {
        $command = new DeactivateCommand();
        $this->assertSame('code-snippet:deactivate', $command->name());
        $this->assertArrayHasKey('json', $command->allOptions());
        $this->assertArrayHasKey('ignoreNotFound', $command->allOptions());
        $this->assertArrayHasKey('id', $command->allArguments());
    }

    public function testDeleteCommandConfiguration(): void
    {
        $command = new DeleteCommand();
        $this->assertSame('code-snippet:delete', $command->name());
        $this->assertArrayHasKey('ignoreNotFound', $command->allOptions());
        $this->assertArrayHasKey('id', $command->allArguments());
    }
}
