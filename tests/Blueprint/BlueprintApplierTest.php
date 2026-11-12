<?php
namespace Tests\Blueprint;

use Exception;
use Omeka\Api\Representation\SiteRepresentation;
use OSC\Addon\AddonIni;
use OSC\Addon\AddonType;
use OSC\Addon\AddonVersion;
use OSC\Addon\InstalledAddons;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintApplier;
use OSC\Blueprint\OnError;
use OSC\Commands\AbstractCommand;
use OSC\Exceptions\WarningException;
use OSC\Helper\Config\MediaConfig;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use OSC\Omeka\OmekaInstance;
use OSC\Omeka\SiteApi;
use OSC\Resource\PortableJsonLd;
use OSC\Resource\ResourceImportException;
use OSC\Resource\SimpleContentMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(BlueprintApplier::class)]
#[UsesClass(\OSC\Downloader\AddonResolver::class)]
#[UsesClass(Blueprint::class)]
#[UsesClass(InstalledAddons::class)]
#[UsesClass(AddonIni::class)]
#[UsesClass(AddonType::class)]
#[UsesClass(AddonVersion::class)]
#[UsesClass(ZipDownloader::class)]
#[UsesClass(Path::class)]
#[UsesClass(ResourceFetcher::class)]
#[UsesClass(OnError::class)]
#[UsesClass(SimpleContentMapper::class)]
#[UsesClass(PortableJsonLd::class)]
#[UsesClass(MediaConfig::class)]
#[UsesClass(ResourceImportException::class)]
class BlueprintApplierTest extends TestCase
{
    /** A scratch directory holding the fake Omeka S root (`omeka/`) and the file sources. */
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/bp_applier_' . uniqid();
        mkdir($this->dir . '/omeka', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Path::removeFolder($this->dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /** @param array<string,string> $entries Archive path => content */
    private function zip(string $name, array $entries): string
    {
        $path = $this->dir . '/' . $name;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        return $path;
    }

    /** Run the files phase (only) against the scratch Omeka S root. */
    private function applyFiles(array $files, bool $dryRun = false): void
    {
        $command = $this->createMock(AbstractCommand::class);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        (new BlueprintApplier($command, $dryRun))->applyModulesAndThemes(new Blueprint(['files' => $files]));
    }

    public function testCopiesAFileAndCreatesMissingDirectories(): void
    {
        $source = $this->write('cleanurl.config.php', '<?php return [];');

        $this->applyFiles([['source' => $source, 'destination' => 'config/sub/cleanurl.config.php']]);

        $this->assertStringEqualsFile($this->dir . '/omeka/config/sub/cleanurl.config.php', '<?php return [];');
    }

    public function testOverwritesAnExistingFile(): void
    {
        mkdir($this->dir . '/omeka/config');
        file_put_contents($this->dir . '/omeka/config/a.php', 'old');
        $source = $this->write('a.php', 'new');

        $this->applyFiles([['source' => $source, 'destination' => 'config/a.php']]);

        $this->assertStringEqualsFile($this->dir . '/omeka/config/a.php', 'new');
    }

    public function testExtractStripsASingleTopLevelDirectory(): void
    {
        $source = $this->zip('assets.zip', ['assets/css/site.css' => 'css', 'assets/logo.svg' => 'svg']);

        $this->applyFiles([['source' => $source, 'destination' => 'files/asset', 'extract' => true]]);

        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/css/site.css', 'css');
        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/logo.svg', 'svg');
        $this->assertDirectoryDoesNotExist($this->dir . '/omeka/files/asset/assets');
    }

    public function testExtractKeepsAnArchiveWithSeveralTopLevelEntries(): void
    {
        $source = $this->zip('assets.zip', ['css/site.css' => 'css', 'logo.svg' => 'svg']);

        $this->applyFiles([['source' => $source, 'destination' => 'files/asset', 'extract' => true]]);

        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/css/site.css', 'css');
        $this->assertStringEqualsFile($this->dir . '/omeka/files/asset/logo.svg', 'svg');
    }

    public function testDryRunWritesNothing(): void
    {
        $file = $this->write('a.php', 'x');
        $archive = $this->zip('assets.zip', ['logo.svg' => 'svg']);

        $this->applyFiles([
            ['source' => $file, 'destination' => 'config/a.php'],
            ['source' => $archive, 'destination' => 'files/asset', 'extract' => true],
        ], true);

        $this->assertSame(['.', '..'], scandir($this->dir . '/omeka'));
    }

    public function testAcceptsADestinationThatOnlyStartsWithDots(): void
    {
        $source = $this->write('a.txt', 'x');

        $this->applyFiles([['source' => $source, 'destination' => 'files/..hidden']]);

        $this->assertFileExists($this->dir . '/omeka/files/..hidden');
    }

    public function testRejectsADestinationOutsideTheOmekaRoot(): void
    {
        $source = $this->write('a.txt', 'x');
        foreach (['', '/etc/passwd', '../x', 'config/../../x', 'config\\..\\x', 'C:/x', 'files/..'] as $unsafe) {
            try {
                $this->applyFiles([['source' => $source, 'destination' => $unsafe]]);
                $this->fail("accepted unsafe destination '{$unsafe}'");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileDoesNotExist($this->dir . '/x');
    }

    public function testRejectsAnUnsafeDestinationEvenInADryRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->applyFiles([['source' => 'a.txt', 'destination' => '../x']], true);
    }

    // ── add-on download arguments (asserted through the dry-run report) ──────────────────────────

    /**
     * Dry-run the modules and themes phases and return the reported lines.
     *
     * @return string[]
     */
    private function dryRunAddons(array $blueprint, bool $update = false): array
    {
        $notes = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        $command->method('note')->willReturnCallback(function (string $message) use (&$notes) {
            $notes[] = $message;
        });
        (new BlueprintApplier($command, true, $update))->applyModulesAndThemes(new Blueprint($blueprint));
        return $notes;
    }

    /**
     * Dry-run the download-only phases and return the reported lines, phase status lines included
     * ("<Phase>: <status>").
     *
     * @param string[] $skip
     * @return string[]
     */
    private function dryRunDownloads(array $blueprint, array $skip = []): array
    {
        $lines = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        $command->method('note')->willReturnCallback(function (string $message) use (&$lines) {
            $lines[] = $message;
        });
        $command->method('section')->willReturnCallback(function (string $title, ?string $detail = null, ?string $status = null) use (&$lines) {
            $lines[] = "{$title}: " . ($status ?? 'run');
        });
        (new BlueprintApplier($command, true, false, $skip))->applyDownloads(new Blueprint($blueprint));
        return $lines;
    }

    public function testDownloadsGetEveryModuleWhateverItsStateAndInstallNone(): void
    {
        $lines = $this->dryRunDownloads(['modules' => [
            ['name' => 'A', 'state' => 'download'],
            ['name' => 'B', 'state' => 'install'],
            'C',
        ]]);

        $this->assertContains("would download module 'A' (A)", $lines);
        $this->assertContains("would download module 'B' (B)", $lines);
        $this->assertContains("would download module 'C' (C)", $lines);
        $this->assertSame([], preg_grep('/would (install|enable)/', $lines));
    }

    public function testDownloadsAlsoRunThemesAndFiles(): void
    {
        $source = $this->write('logo.png', 'png');
        $lines = $this->dryRunDownloads([
            'themes' => ['freedom'],
            'files' => [['source' => $source, 'destination' => 'themes/freedom/asset/img/logo.png']],
        ]);

        $this->assertContains("would download theme 'freedom' (freedom)", $lines);
        $this->assertContains("would copy '{$source}' to 'themes/freedom/asset/img/logo.png'", $lines);
    }

    public function testDownloadsSkipTheRequestedPhasesAndNoConfiguration(): void
    {
        $lines = $this->dryRunDownloads(
            ['modules' => ['A'], 'themes' => ['freedom'], 'settings' => ['installation_title' => 'X']],
            ['modules']
        );

        $this->assertSame(['Modules: skipped (--skip)', 'Themes: run', "would download theme 'freedom' (freedom)", 'Files: nothing to do'], $lines);
    }

    public function testAddonWithoutSourceResolvesByNameAndVersion(): void
    {
        $notes = $this->dryRunAddons([
            'modules' => ['Common', ['name' => 'Log', 'version' => '3.4.30']],
            'themes' => [['name' => 'freedom', 'version' => '1.0.0']],
        ]);

        $this->assertContains("would download module 'Common' (Common)", $notes);
        $this->assertContains("would download module 'Log' (Log:3.4.30)", $notes);
        $this->assertContains("would download theme 'freedom' (freedom:1.0.0)", $notes);
    }

    public function testAddonSourceIsPassedThroughAndAZipUrlWinsOverTheVersion(): void
    {
        $zip = 'https://example.org/Mapping-2.1.0.zip';
        $notes = $this->dryRunAddons(['modules' => [
            ['name' => 'Mapping', 'source' => $zip, 'version' => '2.0.0'],
            ['name' => 'X', 'source' => 'gh:owner/repo'],
        ]]);

        $this->assertContains("would download module 'Mapping' ({$zip})", $notes);
        $this->assertContains("would download module 'X' (gh:owner/repo)", $notes);
    }

    public function testVersionSelectsTheTagOfAGitSourceUnlessItHasARef(): void
    {
        $notes = $this->dryRunAddons(['modules' => [
            ['name' => 'A', 'source' => 'gh:owner/a', 'version' => '1.2.0'],
            ['name' => 'B', 'source' => 'https://example.org/owner/b.git', 'version' => '1.2.0'],
            ['name' => 'C', 'source' => 'gh:owner/c#main', 'version' => '1.2.0'],
        ]]);

        $this->assertContains("would download module 'A' (gh:owner/a#1.2.0)", $notes);
        $this->assertContains("would download module 'B' (https://example.org/owner/b.git#1.2.0)", $notes);
        $this->assertContains("would download module 'C' (gh:owner/c#main)", $notes);
    }

    public function testAddonOnDiskWithoutSourceIsUsedAsIsUnlessUpdating(): void
    {
        mkdir($this->dir . '/omeka/themes/default', 0777, true);
        $blueprint = ['themes' => ['default']];

        $this->assertContains('default: already present, nothing to download', $this->dryRunAddons($blueprint));
        $this->assertContains("would download theme 'default' (default)", $this->dryRunAddons($blueprint, true));
    }

    public function testAddonOnDiskWithASourceIsUsedAsIs(): void
    {
        // e.g. a module mounted for development, whose entry has a source for other consumers
        mkdir($this->dir . '/omeka/modules/Dev/config', 0777, true);
        file_put_contents($this->dir . '/omeka/modules/Dev/config/module.ini', "[info]\nversion = \"1.2.0\"\n");
        mkdir($this->dir . '/omeka/themes/dev', 0777, true);

        $notes = $this->dryRunAddons([
            'modules' => [['name' => 'Dev', 'source' => 'https://example.org/Dev-1.2.0.zip', 'version' => '1.2.0']],
            'themes' => [['name' => 'dev', 'source' => 'gh:owner/dev']],
        ]);

        $this->assertContains('Dev: already present, nothing to download', $notes);
        $this->assertContains('dev: already present, nothing to download', $notes);
    }

    public function testAddonOnDiskIsDownloadedAgainWhenThePinnedVersionDiffers(): void
    {
        mkdir($this->dir . '/omeka/modules/Dev/config', 0777, true);
        file_put_contents($this->dir . '/omeka/modules/Dev/config/module.ini', "[info]\nversion = \"1.2.0\"\n");
        $zip = 'https://example.org/Dev-1.3.0.zip';

        $notes = $this->dryRunAddons(['modules' => [['name' => 'Dev', 'source' => $zip, 'version' => '1.3.0']]]);

        $this->assertContains("would download module 'Dev' ({$zip})", $notes);
    }

    /**
     * Run the configuration phases as a dry run and capture the notes and warnings. Without a site
     * API the instance is "not installed" (getOmekaInstance throws), as on a fresh dry-run deploy.
     *
     * @return array{0: string[], 1: string[]} [notes, warnings]
     */
    private function dryRunSites(array $sites, ?SiteApi $siteApi, bool $update = false): array
    {
        $notes = [];
        $warnings = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('note')->willReturnCallback(function (string $m) use (&$notes) { $notes[] = $m; });
        $command->method('warn')->willReturnCallback(function (string $m) use (&$warnings) { $warnings[] = $m; });
        if ($siteApi) {
            $instance = $this->createMock(OmekaInstance::class);
            $instance->method('getSiteApi')->willReturn($siteApi);
            $command->method('getOmekaInstance')->willReturn($instance);
        } else {
            $command->method('getOmekaInstance')->willThrowException(new Exception('Omeka S is not installed.'));
        }
        (new BlueprintApplier($command, true, $update))->applyConfiguration(new Blueprint(['sites' => $sites]));
        return [$notes, $warnings];
    }

    /** A site API that knows one site, 'site-a' (titled 'Site A'), with the given permissions. */
    private function siteApiWithSiteA(array $permissions = []): SiteApi
    {
        $site = $this->createMock(SiteRepresentation::class);
        $site->method('slug')->willReturn('site-a');
        $siteApi = $this->createMock(SiteApi::class);
        $siteApi->method('findSiteBySlug')->willReturnCallback(fn(string $slug) => $slug === 'site-a' ? $site : null);
        $siteApi->method('findSiteByTitle')->willReturnCallback(fn(string $title) => strcasecmp($title, 'Site A') === 0 ? $site : null);
        $siteApi->method('getPermissionMap')->willReturn($permissions);
        return $siteApi;
    }

    public function testDryRunImportsSimpleThenExtensionContent(): void
    {
        $notes = [];
        $sections = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('note')->willReturnCallback(function (string $m) use (&$notes) { $notes[] = $m; });
        $command->method('section')->willReturnCallback(function (string $label) use (&$sections) { $sections[] = $label; });

        (new BlueprintApplier($command, true))->applyConfiguration(new Blueprint([
            'itemSets' => [['title' => 'Paintings']],
            'items' => [['title' => 'Mona Lisa']],
            'x-omeka-s-cli' => [
                'itemSets' => [['source' => '/bp/content/drawings.jsonld']],
                'items' => [['source' => '/bp/content/study.jsonld', 'title' => 'Study'], ['title' => 'Title only']],
            ],
        ]));

        $this->assertSame([
            "would import item set 'Paintings'",
            "would import item set 'drawings.jsonld'",
            "would import item 'Mona Lisa'",
            "would import item 'Study'",
            "would import item 'Title only'",
        ], $notes);
        $this->assertContains('Item sets', $sections);
        $this->assertContains('Items', $sections);
    }

    public function testItemPhasesCanBeSkipped(): void
    {
        $notes = [];
        $skipped = [];
        $command = $this->createMock(AbstractCommand::class);
        $command->method('note')->willReturnCallback(function (string $m) use (&$notes) { $notes[] = $m; });
        $command->method('section')->willReturnCallback(function (string $label, $x = null, ?string $status = null) use (&$skipped) {
            if ($status !== null) {
                $skipped[$label] = $status;
            }
        });

        (new BlueprintApplier($command, true, false, ['items']))->applyConfiguration(new Blueprint(['items' => [['title' => 'Mona Lisa']]]));

        $this->assertSame([], $notes);
        $this->assertSame('skipped (--skip)', $skipped['Items']);
    }

    public function testDryRunCreatesEverySiteWhenOmekaIsNotInstalled(): void
    {
        [$notes] = $this->dryRunSites([
            ['title' => 'Site A', 'slug' => 'site-a', 'permissions' => [['user' => 'e@x.org', 'role' => 'admin']]],
            ['title' => 'Site B'],
        ], null);

        $this->assertSame([
            "would create site 'site-a'",
            "would grant 'e@x.org' the admin role on site 'site-a'",
            "would create site 'Site B'",
        ], $notes);
    }

    public function testAPermissionWithoutRoleDefaultsToViewer(): void
    {
        [$notes] = $this->dryRunSites([['title' => 'Site B', 'permissions' => [['user' => 'e@x.org']]]], null);

        $this->assertContains("would grant 'e@x.org' the viewer role on site 'Site B'", $notes);
    }

    public function testAnExistingSiteIsSkippedButMissingPermissionsAreStillAdded(): void
    {
        [$notes, $warnings] = $this->dryRunSites(
            [['title' => 'Site A', 'slug' => 'site-a', 'permissions' => [['user' => 'e@x.org', 'role' => 'editor']]]],
            $this->siteApiWithSiteA(['admin@x.org' => 'admin'])
        );

        $this->assertSame(["site 'site-a' already exists, skipping (use --update to update it)."], $warnings);
        $this->assertSame(["would grant 'e@x.org' the editor role on site 'site-a'"], $notes);
    }

    public function testAnExistingSiteIsUpdatedWithUpdate(): void
    {
        [$notes, $warnings] = $this->dryRunSites([['title' => 'Site A', 'slug' => 'site-a']], $this->siteApiWithSiteA(), true);

        $this->assertSame([], $warnings);
        $this->assertSame(["would update site 'site-a'"], $notes);
    }

    public function testADifferentRoleOnlyWarnsWithoutUpdate(): void
    {
        [$notes, $warnings] = $this->dryRunSites(
            [['title' => 'Site A', 'slug' => 'site-a', 'permissions' => [['user' => 'E@x.org', 'role' => 'editor']]]],
            $this->siteApiWithSiteA(['e@x.org' => 'viewer'])
        );

        $this->assertContains("site 'site-a': 'E@x.org' has the viewer role, the blueprint asks for editor (use --update to change it).", $warnings);
        $this->assertSame([], $notes);
    }

    public function testADifferentRoleIsChangedWithUpdate(): void
    {
        [$notes] = $this->dryRunSites(
            [['title' => 'Site A', 'slug' => 'site-a', 'permissions' => [['user' => 'e@x.org', 'role' => 'editor']]]],
            $this->siteApiWithSiteA(['e@x.org' => 'viewer']),
            true
        );

        $this->assertContains("would grant 'e@x.org' the editor role on site 'site-a'", $notes);
    }

    public function testAMatchingRoleIsLeftAlone(): void
    {
        [$notes] = $this->dryRunSites(
            [['title' => 'Site A', 'slug' => 'site-a', 'permissions' => [['user' => 'e@x.org', 'role' => 'editor']]]],
            $this->siteApiWithSiteA(['e@x.org' => 'editor']),
            true
        );

        $this->assertSame(["would update site 'site-a'"], $notes);
    }

    public function testASiteWithoutSlugIsMatchedByTitle(): void
    {
        [$notes, $warnings] = $this->dryRunSites([['title' => 'site a']], $this->siteApiWithSiteA());

        $this->assertSame(["site 'site a' already exists, skipping (use --update to update it)."], $warnings);
        $this->assertSame([], $notes);
    }

    public function testASourcedThemeIsInstalledIntoItsName(): void
    {
        $zip = 'https://example.org/freedom-1.0.7.zip';
        $notes = $this->dryRunAddons(['themes' => [
            ['name' => 'freedom', 'source' => $zip],
            ['name' => 'cozy', 'version' => '1.0.0'],
        ]]);

        $this->assertContains("would download theme 'freedom' ({$zip}) into themes/freedom", $notes);
        // a catalog theme keeps the catalog's folder name
        $this->assertContains("would download theme 'cozy' (cozy:1.0.0)", $notes);
    }

    // ── content: onError ─────────────────────────────────────────────────────────────────────────

    /**
     * An import command that hands each import config (decoded) to $onImport, in place of
     * item:import / item-set:import.
     */
    private function fakeImportCommand(string $name, \Closure $onImport): AbstractCommand
    {
        return new class ($name, $onImport) extends AbstractCommand {
            public function __construct(string $name, private \Closure $onImport)
            {
                parent::__construct($name);
            }

            public function execute(?string $source = null, ?string $config = null): void
            {
                ($this->onImport)(json_decode((string) file_get_contents((string) $config), true));
            }
        };
    }

    /**
     * Run the content phases for real against fake import commands. $onImport receives the kind
     * ('item' or 'item set') and the import config, and may throw.
     *
     * @param array<string,AbstractCommand>|null $commands Replaces the fake commands when given
     * @return array{0: BlueprintApplier, 1: string[]} [the applier, the warnings]
     */
    private function applyContent(array $blueprint, \Closure $onImport, ?array $commands = null): array
    {
        $warnings = [];
        $commands ??= [
            'item-set:import' => $this->fakeImportCommand('item-set:import', fn(array $config) => $onImport('item set', $config)),
            'item:import' => $this->fakeImportCommand('item:import', fn(array $config) => $onImport('item', $config)),
        ];
        $app = $this->createMock(\Ahc\Cli\Application::class);
        $app->method('commands')->willReturn($commands);
        $command = $this->createMock(AbstractCommand::class);
        $command->method('app')->willReturn($app);
        $command->method('resolveOmekaPath')->willReturn($this->dir . '/omeka');
        $command->method('warn')->willReturnCallback(function (string $m) use (&$warnings) { $warnings[] = $m; });

        $applier = new BlueprintApplier($command);
        $applier->applyConfiguration(new Blueprint($blueprint));
        return [$applier, $warnings];
    }

    /** A fake import that fails for the entry titled $title and records the others in $imported. */
    private function failingFor(string $title, array &$imported, ?\Throwable $error = null): \Closure
    {
        return function (string $kind, array $config) use ($title, &$imported, $error) {
            if (($config['title'] ?? null) === $title) {
                throw $error ?? new ResourceImportException($title, ['link "o:item_set" to item sets with dcterms:title = "Postcards" not found']);
            }
            $imported[] = "{$kind} " . ($config['title'] ?? basename((string) ($config['source'] ?? '?')));
        };
    }

    public function testAContentErrorStopsTheDeployByDefault(): void
    {
        $imported = [];
        try {
            $this->applyContent(['items' => [['title' => 'A'], ['title' => 'B']]], $this->failingFor('A', $imported));
            $this->fail('expected the deploy to stop');
        } catch (ResourceImportException $e) {
            $this->assertSame('A', $e->label());
        }
        $this->assertSame([], $imported);
    }

    public function testAContentErrorIsSkippedWithOnErrorSkip(): void
    {
        $imported = [];
        [$applier, $warnings] = $this->applyContent(
            ['itemSets' => [['title' => 'S']], 'items' => [['title' => 'A', 'onError' => 'skip'], ['title' => 'B']]],
            $this->failingFor('A', $imported)
        );

        $this->assertSame(['item set S', 'item B'], $imported);
        $this->assertSame(["skipped item 'A': link \"o:item_set\" to item sets with dcterms:title = \"Postcards\" not found"], $warnings);
        $this->assertSame(
            [['kind' => 'item', 'label' => 'A', 'message' => 'link "o:item_set" to item sets with dcterms:title = "Postcards" not found']],
            $applier->takeSkipped()
        );
        $this->assertSame([], $applier->takeSkipped());
    }

    public function testAnExtensionEntryTakesOnErrorToo(): void
    {
        $imported = [];
        [$applier] = $this->applyContent(
            ['x-omeka-s-cli' => ['itemSets' => [['source' => '/bp/a.jsonld', 'title' => 'A', 'onError' => 'skip'], ['source' => '/bp/b.jsonld']]]],
            $this->failingFor('A', $imported)
        );

        $this->assertSame(['item set b.jsonld'], $imported);
        $this->assertSame('A', $applier->takeSkipped()[0]['label']);
    }

    public function testOnErrorIsNotPassedToTheImportCommand(): void
    {
        $configs = [];
        $this->applyContent(
            [
                'items' => [['title' => 'A', 'onError' => 'skip']],
                'x-omeka-s-cli' => ['items' => [['source' => '/bp/b.jsonld', 'onError' => 'fail']]],
            ],
            function (string $kind, array $config) use (&$configs) { $configs[] = $config; }
        );

        $this->assertCount(2, $configs);
        foreach ($configs as $config) {
            $this->assertArrayNotHasKey('onError', $config);
        }
    }

    public function testAnExistingRecordIsNotAnError(): void
    {
        $imported = [];
        [$applier, $warnings] = $this->applyContent(
            ['items' => [['title' => 'A'], ['title' => 'B']]],
            $this->failingFor('A', $imported, new WarningException("Item 'A' already exists (id 3); skipped."))
        );

        $this->assertSame(['item B'], $imported);
        $this->assertSame(["Item 'A' already exists (id 3); skipped."], $warnings);
        $this->assertSame([], $applier->takeSkipped());
    }

    public function testABugIsNotSkipped(): void
    {
        $imported = [];
        $this->expectException(\TypeError::class);
        $this->applyContent(
            ['items' => [['title' => 'A', 'onError' => 'skip']]],
            $this->failingFor('A', $imported, new \TypeError('bug'))
        );
    }

    public function testAMissingImportCommandStopsEvenWithSkip(): void
    {
        $this->expectExceptionMessage("Required command 'item:import' is not available.");
        $this->applyContent(['items' => [['title' => 'A', 'onError' => 'skip']]], fn() => null, []);
    }
}
