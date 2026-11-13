<?php
namespace Tests\Commands;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A short flag registered twice resolves to the first option only (the parser returns the first
 * match), so the second option's short flag silently does something else: -b meant --base-path
 * where the help showed "-b, --backup".
 */
#[CoversNothing]
class ShortOptionsTest extends TestCase
{
    public function testNoCommandRegistersAShortFlagTwice(): void
    {
        $clashes = [];
        foreach (require __DIR__ . '/../../src/Commands/Index.php' as $command) {
            $seen = [];
            foreach ($command->allOptions() as $option) {
                if ($option->short() === $option->long()) {
                    continue; // no short flag
                }
                if (isset($seen[$option->short()])) {
                    $clashes[] = "{$command->name()}: {$option->short()} is {$seen[$option->short()]} and {$option->long()}";
                }
                $seen[$option->short()] ??= $option->long();
            }
        }
        $this->assertSame([], $clashes);
    }
}
