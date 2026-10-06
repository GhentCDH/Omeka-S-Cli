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
                ['prefix' => 'schema', 'namespaceUri' => 'https://schema.org/', 'label' => 'schema.org', 'source' => 'https://schema.org/x.rdf'],
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
            'sites' => [[
                'title' => 'X',
                'permissions' => [['user' => 'ghost@nowhere.org', 'role' => 'admin']],
            ]],
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

    public function testAddonSourceIsASingleString(): void
    {
        $v = $this->validator();
        $this->assertSame([], $v->validateBlueprint([
            'modules' => [
                ['name' => 'Mapping', 'source' => 'https://example.org/Mapping-2.1.0.zip'],
                ['name' => 'Log', 'source' => 'gh:Daniel-KM/Omeka-S-module-Log', 'version' => '3.4.30'],
            ],
            'themes' => [['name' => 'freedom', 'source' => 'gh:omeka-s-themes/freedom']],
        ]));
        // the pre-v0.1 object form is rejected
        $this->assertNotEmpty($v->validateBlueprint([
            'modules' => [['name' => 'Common', 'source' => ['type' => 'omeka.org', 'slug' => 'Common']]],
        ]));
    }

    public function testValidatesRootFilesAndRejectsUnsafeDestinations(): void
    {
        $v = $this->validator();
        $this->assertSame([], $v->validateBlueprint([
            'files' => [
                ['source' => './cleanurl.config.php', 'destination' => 'config/cleanurl.config.php'],
                ['source' => 'https://example.org/extra.zip', 'destination' => 'modules/X/asset', 'extract' => true],
            ],
        ]));
        foreach (['../config/local.config.php', '/etc/passwd', 'config/../../x'] as $destination) {
            $this->assertNotEmpty(
                $v->validateBlueprint(['files' => [['source' => './x', 'destination' => $destination]]]),
                $destination
            );
        }
        // modules[].assets was replaced by the root files list
        $this->assertNotEmpty($v->validateBlueprint([
            'modules' => [['name' => 'X', 'assets' => [['url' => 'https://example.org/extra.zip', 'destination' => 'asset']]]],
        ]));
    }

    public function testValidatesAStandaloneFilesPartial(): void
    {
        $this->assertSame([], $this->validator()->validatePartial([['source' => './a', 'destination' => 'config/a']], 'files'));
    }

    public function testInstallReplacesSiteOptions(): void
    {
        $v = $this->validator();
        $this->assertSame([], $v->validateBlueprint([
            'install' => ['title' => 'Demo', 'locale' => 'en_US', 'timezone' => 'UTC', 'admin' => ['email' => 'admin@example.com']],
        ]));
        $this->assertNotEmpty($v->validateBlueprint(['siteOptions' => ['title' => 'Demo']]));
    }

    public function testImplementationSpecificKeysLiveUnderTopLevelExtensions(): void
    {
        $v = $this->validator();
        $this->assertSame([], $v->validateBlueprint([
            'x-playground' => ['landingPage' => '/admin', 'login' => ['email' => 'admin@example.com']],
            'x-omeka-s-cli' => ['anything' => true],
            'sites' => [['title' => 'Playground', 'slug' => 'playground', 'theme' => 'default']],
        ]));
        // the former runtime keys and the singular site are no longer top-level keys
        foreach (['landingPage' => '/admin', 'phpConstants' => ['FOO' => true], 'site' => ['title' => 'X']] as $key => $value) {
            $this->assertNotEmpty($v->validateBlueprint([$key => $value]), $key);
        }
        // extensions are top-level only
        $this->assertNotEmpty($v->validateBlueprint(['modules' => [['name' => 'X', 'x-playground' => []]]]));
    }

    public function testRejectsAnEmptyUserRole(): void
    {
        // the shared schema treats `role` as a free, non-empty string (minLength 1) so module-
        // registered roles are allowed; only an empty role is invalid
        $errors = $this->validator()->validateBlueprint([
            'users' => [['email' => 'a@b.c', 'role' => '']],
        ]);
        $this->assertNotEmpty($errors);
    }

    public function testAcceptsANonCoreUserRole(): void
    {
        // a module-registered role (e.g. a guest role) is a valid non-empty string
        $errors = $this->validator()->validateBlueprint([
            'users' => [['email' => 'a@b.c', 'role' => 'guest']],
        ]);
        $this->assertSame([], $errors);
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
                'source' => 'https://ex.org/ex.rdf',
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
