<?php
namespace Tests\Blueprint;

use OSC\Blueprint\BlueprintValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintValidator::class)]
class BlueprintValidatorTest extends TestCase
{
    public function testBundledSchemaFileExists(): void
    {
        // guards the bundled-schema path so a box/layout change can't silently break validation
        $this->assertFileExists((new BlueprintValidator())->schemaFile());
    }

    public function testAcceptsAValidBlueprint(): void
    {
        $blueprint = [
            'meta' => ['title' => 'Test'],
            'modules' => [
                'Common',
                ['name' => 'AdvancedSearch', 'state' => 'activate', 'version' => '3.4.51'],
                ['name' => 'Log', 'state' => 'download'],
            ],
            'themes' => ['default'],
            'vocabularies' => [
                ['prefix' => 'schema', 'namespaceUri' => 'https://schema.org/', 'label' => 'schema.org', 'url' => 'https://schema.org/x.rdf'],
            ],
            'settings' => ['installation_title' => 'x'],
            'users' => [['email' => 'a@b.c', 'password' => 'x', 'role' => 'global_admin']],
        ];
        $this->assertSame([], (new BlueprintValidator())->validateBlueprint($blueprint));
    }

    public function testRejectsAnUnknownModuleState(): void
    {
        $errors = (new BlueprintValidator())->validateBlueprint([
            'modules' => [['name' => 'X', 'state' => 'frobnicate']],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testReferentialCheckCatchesUnknownSitePermissionUser(): void
    {
        $errors = (new BlueprintValidator())->validateBlueprint([
            'users' => [['email' => 'a@b.c', 'password' => 'x']],
            'site' => [
                'title' => 'X',
                'permissions' => [['user' => 'ghost@nowhere.org', 'role' => 'admin']],
            ],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ghost@nowhere.org', implode("\n", $errors));
    }

    public function testReferentialCheckCatchesUnknownItemSet(): void
    {
        $errors = (new BlueprintValidator())->validateBlueprint([
            'itemSets' => [['title' => 'Known']],
            'items' => [['title' => 'Item', 'itemSets' => ['Unknown']]],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unknown', implode("\n", $errors));
    }

    public function testValidatesAStandaloneModulePartial(): void
    {
        $validator = new BlueprintValidator();
        $this->assertSame([], $validator->validatePartial(['Common', ['name' => 'X', 'state' => 'install']], 'modules'));
        $this->assertNotEmpty($validator->validatePartial([['state' => 'install']], 'modules'));
    }

    public function testPlaygroundBlueprintWithRuntimeOnlyKeysStillValidates(): void
    {
        $blueprint = [
            'phpConstants' => ['FOO' => true],
            'debug' => ['enabled' => false],
            'login' => ['email' => 'admin@example.com', 'password' => 'password'],
            'landingPage' => '/admin',
            'site' => ['title' => 'Playground', 'slug' => 'playground', 'theme' => 'default'],
            'modules' => [],
            'themes' => [],
        ];
        $this->assertSame([], (new BlueprintValidator())->validateBlueprint($blueprint));
    }
}
