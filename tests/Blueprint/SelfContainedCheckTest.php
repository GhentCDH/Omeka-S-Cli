<?php
namespace Tests\Blueprint;

use OSC\Blueprint\Blueprint;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Blueprint\SourceFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelfContainedCheck::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(SourceFields::class)]
class SelfContainedCheckTest extends TestCase
{
    public function testFlagsAddonsWithoutSourceExceptTheCoreTheme(): void
    {
        $violations = SelfContainedCheck::violations(new Blueprint([
            'x-omeka-s-cli' => ['install' => ['source' => '/bundle/core/omeka-s-4.1.1.zip']],
            'modules' => ['Common', ['name' => 'Log', 'version' => '1.0'], ['name' => 'Zip', 'source' => '/bundle/modules/Zip.zip']],
            'themes' => ['default', ['name' => 'freedom']],
        ]), true);

        $this->assertSame([
            "modules 'Common' has no source: it would be downloaded from a catalog.",
            "modules 'Log' has no source: it would be downloaded from a catalog.",
            "themes 'freedom' has no source: it would be downloaded from a catalog.",
        ], $violations);
    }

    public function testRequiresTheCoreSourceOnlyWhenTheCoreWillBeInstalled(): void
    {
        $blueprint = new Blueprint(['preferredVersions' => ['omeka' => '4.1.1']]);

        $this->assertSame(
            ['x-omeka-s-cli.install.source is missing: the Omeka S core would be downloaded.'],
            SelfContainedCheck::violations($blueprint, true)
        );
        $this->assertSame([], SelfContainedCheck::violations($blueprint, false));
    }
}
