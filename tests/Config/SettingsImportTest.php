<?php

namespace Tests\Config;

use Doctrine\DBAL\Connection;
use Exception;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Module\Module;
use OSC\Settings\LogEntry;
use OSC\Settings\SettingsDocument;
use OSC\Settings\SettingsImport;
use OSC\Settings\SettingsImportReport;
use OSC\Settings\SettingsScope;
use OSC\Settings\SettingType;
use OSC\Settings\Severity;
use OSC\Helper\ModuleSettingsMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsImport::class)]
#[CoversClass(SettingsImportReport::class)]
#[CoversClass(LogEntry::class)]
#[CoversClass(Severity::class)]
class SettingsImportTest extends TestCase
{
    /**
     * A service manager seeded with mock services, so SettingsImport (and the ModuleApi it builds
     * from the same manager) resolve everything without booting Omeka.
     *
     * @param array<string, object> $extra Additional service id => instance overrides
     */
    private function serviceManager(array $extra = []): ServiceManager
    {
        return new ServiceManager(['services' => array_merge([
            'Omeka\Connection' => $this->createMock(Connection::class),
            'Omeka\ApiManager' => $this->createMock(ApiManager::class),
            'Omeka\ModuleManager' => $this->moduleManager([]),
            'Omeka\Settings' => $this->createMock(\Omeka\Settings\Settings::class),
        ], $extra)]);
    }

    /**
     * A mock ModuleManager whose getModules() returns the given id => module map.
     *
     * @param array<string, Module> $modules
     */
    private function moduleManager(array $modules): ModuleManager
    {
        $manager = $this->createMock(ModuleManager::class);
        $manager->method('getModules')->willReturn($modules);
        return $manager;
    }

    private function messages(SettingsImportReport $report): string
    {
        return implode("\n", array_map(static fn(LogEntry $e) => $e->message, $report->entries()));
    }

    public function testOutOfScopeTypeIsSkipped(): void
    {
        // Scope only covers global settings; a site_setting document is skipped without touching services.
        $import = new SettingsImport(
            $this->serviceManager(),
            new ModuleSettingsMapper(['Log']),
            new SettingsScope([SettingType::Setting]),
            '4.2.1',
        );
        $doc = new SettingsDocument(SettingType::SiteSetting, 'core', [], site: 'main', scopeLabel: 'main');

        $report = $import->import('core.site_setting.main.jsonc', $doc);

        $this->assertFalse($report->isApplied());
        $this->assertStringContainsString('out of scope', $this->messages($report));
    }

    public function testCoreVersionMismatchThrowsUnderStrict(): void
    {
        $import = new SettingsImport(
            $this->serviceManager(),
            new ModuleSettingsMapper(['Log']),
            SettingsScope::all(),
            '4.2.1',
            strict: true,
        );
        $doc = new SettingsDocument(SettingType::Setting, 'core', ['x' => 1], omekaVersion: '4.0.0');

        $this->expectException(Exception::class);
        $import->import('core.setting.jsonc', $doc);
    }

    public function testProtectedVersionKeyIsSkippedWithoutForce(): void
    {
        // Dry run: no settings service write is made, so the mocked settings service is untouched.
        $import = new SettingsImport(
            $this->serviceManager(),
            new ModuleSettingsMapper(['Log']),
            SettingsScope::all(),
            '4.2.1',
            dryRun: true,
        );
        $doc = new SettingsDocument(SettingType::Setting, 'core', ['version' => '4.2.1', 'administrator_email' => 'a@b.c']);

        $report = $import->import('core.setting.jsonc', $doc);

        $this->assertTrue($report->isApplied());
        $this->assertStringContainsString("Skipping protected key 'version'", $this->messages($report));
        $warnings = array_filter($report->entries(), static fn(LogEntry $e) => $e->severity === Severity::Warning);
        $this->assertNotEmpty($warnings);
    }

    public function testInactiveModuleIsSkipped(): void
    {
        $module = $this->createMock(Module::class);
        $module->method('getState')->willReturn(ModuleManager::STATE_NOT_ACTIVE);

        $import = new SettingsImport(
            $this->serviceManager(['Omeka\ModuleManager' => $this->moduleManager(['Log' => $module])]),
            new ModuleSettingsMapper(['Log']),
            SettingsScope::all(),
            '4.2.1',
        );
        $doc = new SettingsDocument(SettingType::Setting, 'Log', ['log_archive_days' => 30]);

        $report = $import->import('Log.setting.jsonc', $doc);

        $this->assertFalse($report->isApplied());
        $this->assertStringContainsString("not active", $this->messages($report));
    }
}
