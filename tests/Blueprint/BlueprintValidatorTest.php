<?php
namespace Tests\Blueprint;

use OSC\Blueprint\BlueprintValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlueprintValidator::class)]
class BlueprintValidatorTest extends TestCase
{
    /**
     * A validator bound to the bundled schema file instead of the remote URL, so the test suite
     * exercises the fetch/registerRaw path without ever hitting the network. ResourceFetcher reads
     * local paths directly and local sources are not cached, so this always sees the current schema.
     */
    private function validator(): BlueprintValidator
    {
        return new BlueprintValidator((new BlueprintValidator())->schemaFile());
    }

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
        $this->assertSame([], $this->validator()->validateBlueprint($blueprint));
    }

    public function testRejectsAnUnknownModuleState(): void
    {
        $errors = $this->validator()->validateBlueprint([
            'modules' => [['name' => 'X', 'state' => 'frobnicate']],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testReferentialCheckCatchesUnknownSitePermissionUser(): void
    {
        $errors = $this->validator()->validateBlueprint([
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
        $errors = $this->validator()->validateBlueprint([
            'itemSets' => [['title' => 'Known']],
            'items' => [['title' => 'Item', 'itemSets' => ['Unknown']]],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unknown', implode("\n", $errors));
    }

    public function testValidatesAStandaloneModulePartial(): void
    {
        $validator = $this->validator();
        $this->assertSame([], $validator->validatePartial(['Common', ['name' => 'X', 'state' => 'install']], 'modules'));
        $this->assertNotEmpty($validator->validatePartial([['state' => 'install']], 'modules'));
    }

    public function testAcceptsModuleAssetsAndRejectsMalformedEntries(): void
    {
        $validator = $this->validator();

        // a well-formed assets array validates
        $this->assertSame([], $validator->validateBlueprint([
            'modules' => [[
                'name' => 'AdvancedSearch',
                'assets' => [['url' => 'https://example.org/extra.zip', 'destination' => 'asset/custom']],
            ]],
        ]));

        // an assets entry missing the required 'destination' is rejected
        $this->assertNotEmpty($validator->validateBlueprint([
            'modules' => [['name' => 'AdvancedSearch', 'assets' => [['url' => 'https://example.org/extra.zip']]]],
        ]));

        // an unknown key inside an assets entry is rejected (item-level additionalProperties: false)
        $this->assertNotEmpty($validator->validateBlueprint([
            'modules' => [['name' => 'AdvancedSearch', 'assets' => [
                ['url' => 'https://example.org/extra.zip', 'destination' => 'x', 'bogus' => 1],
            ]]],
        ]));
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
        $this->assertSame([], $this->validator()->validateBlueprint($blueprint));
    }

    public function testRejectsUnknownUserRole(): void
    {
        $errors = $this->validator()->validateBlueprint([
            'users' => [['email' => 'a@b.c', 'role' => 'wizard']],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testStrictValidationRejectsUnknownKeys(): void
    {
        $v = $this->validator();
        // unknown top-level key
        $this->assertNotEmpty($v->validateBlueprint(['modulez' => []]));
        // unknown key on a fixed object (user)
        $this->assertNotEmpty($v->validateBlueprint([
            'users' => [['email' => 'a@b.c', 'bogus' => 1]],
        ]));
    }

    public function testVocabularyAcceptsLabelAndCommentProperty(): void
    {
        $errors = $this->validator()->validateBlueprint([
            'vocabularies' => [[
                'prefix' => 'ex',
                'namespaceUri' => 'https://ex.org/',
                'label' => 'Ex',
                'url' => 'https://ex.org/ex.rdf',
                'labelProperty' => 'rdfs:label',
                'commentProperty' => 'rdfs:comment',
            ]],
        ]);
        $this->assertSame([], $errors);
    }

    public function testSettingsMapStillAllowsArbitraryKeys(): void
    {
        // settings is a genuine free-form map and stays open under strict validation
        $errors = $this->validator()->validateBlueprint([
            'settings' => ['any_custom_setting_id' => 'value', 'another' => 3],
        ]);
        $this->assertSame([], $errors);
    }

    public function testFetchesSchemaFromAConfiguredSource(): void
    {
        // pointing the source at the bundled file exercises the fetch + registerRaw branch
        $validator = new BlueprintValidator((new BlueprintValidator())->schemaFile());
        $this->assertSame([], $validator->validateBlueprint(['modules' => ['Common']]));
        // and the schema is still enforced through that branch
        $this->assertNotEmpty($validator->validateBlueprint(['modulez' => []]));
    }

    public function testFallsBackToBundledSchemaWhenSourceIsUnavailable(): void
    {
        // an unreachable source must not break validation: it falls back to the bundled schema
        $validator = new BlueprintValidator('/nonexistent/blueprint-schema.json');
        $this->assertSame([], $validator->validateBlueprint(['modules' => ['Common']]));
        $this->assertNotEmpty($validator->validateBlueprint(['modulez' => []]));
    }
}
