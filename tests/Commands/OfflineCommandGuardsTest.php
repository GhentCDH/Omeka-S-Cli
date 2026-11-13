<?php
namespace Tests\Commands;

use OSC\Commands\Core\DownloadCommand as CoreDownloadCommand;
use OSC\Commands\Core\UpdateCommand as CoreUpdateCommand;
use OSC\Commands\Module\ListCommand as ModuleListCommand;
use OSC\Commands\Module\SearchCommand as ModuleSearchCommand;
use OSC\Commands\Module\UpdateCommand as ModuleUpdateCommand;
use OSC\Commands\Theme\ListCommand as ThemeListCommand;
use OSC\Commands\Theme\SearchCommand as ThemeSearchCommand;
use OSC\Exceptions\OfflineException;
use OSC\Helper\Offline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Commands that cannot do their job without the module/theme repositories fail fast offline, before they
 * bootstrap Omeka S or touch the network.
 */
#[CoversClass(CoreDownloadCommand::class)]
#[CoversClass(CoreUpdateCommand::class)]
#[CoversClass(ModuleSearchCommand::class)]
#[CoversClass(ThemeSearchCommand::class)]
#[CoversClass(ModuleUpdateCommand::class)]
#[CoversClass(ModuleListCommand::class)]
#[CoversClass(ThemeListCommand::class)]
#[UsesClass(Offline::class)]
#[UsesClass(OfflineException::class)]
class OfflineCommandGuardsTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv(Offline::ENV);
        Offline::enable();
    }

    protected function tearDown(): void
    {
        putenv(Offline::ENV);
        unset($_ENV[Offline::ENV]);
        if ($this->saved !== false) {
            putenv(Offline::ENV . '=' . $this->saved);
        }
    }

    public function testCoreDownloadWithoutAVersionFailsBeforeTouchingTheDestination(): void
    {
        $destination = sys_get_temp_dir() . '/osc-offline-core-' . uniqid();
        try {
            (new CoreDownloadCommand())->execute(null, $destination, true);
            $this->fail('core:download should not look up the latest version offline');
        } catch (OfflineException $e) {
            $this->assertStringContainsString('cannot look up the latest Omeka S version. Pass a version number instead', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($destination);
    }

    public function testCoreDownloadFromASourceNeedsNoVersionLookup(): void
    {
        // a release ZIP needs no lookup: the command goes on (and fails later, on the missing file)
        $this->expectExceptionMessageMatches('/^(?!Offline mode)/');

        (new CoreDownloadCommand())->execute(null, sys_get_temp_dir() . '/osc-offline-core-' . uniqid(), false, false, '/no/such/omeka-s.zip');
    }

    public function testCoreUpdateWithoutAVersionFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot look up the latest Omeka S version. Pass a version number instead');

        (new CoreUpdateCommand())->execute(null, false, false);
    }

    public function testModuleSearchFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot search the module repositories');

        (new ModuleSearchCommand())->execute('mapping');
    }

    public function testThemeSearchFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot search the theme repositories');

        (new ThemeSearchCommand())->execute('foundation');
    }

    public function testBulkModuleUpdateFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot check for module updates');

        (new ModuleUpdateCommand())->execute(null, false, true);
    }

    public function testModuleUpdateOfOneModuleFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot check for an update of the module. Drop --offline');

        (new ModuleUpdateCommand())->execute('Common');
    }

    public function testModuleListOutdatedFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot check for outdated modules');

        (new ModuleListCommand())->execute(false, true);
    }

    public function testThemeListOutdatedFails(): void
    {
        $this->expectException(OfflineException::class);
        $this->expectExceptionMessage('cannot check for outdated themes');

        (new ThemeListCommand())->execute(false, false, true);
    }
}
