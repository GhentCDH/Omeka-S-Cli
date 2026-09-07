<?php

namespace Tests\Config;

use OSC\Settings\SettingsExport;
use OSC\Settings\SettingsDocument;
use OSC\Settings\SettingsScope;
use OSC\Settings\SettingType;
use OSC\Helper\ModuleSettingsMapper;
use RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsExport::class)]
class SettingsExportTest extends TestCase
{
    private function export(SettingsScope $scope): SettingsExport
    {
        return new SettingsExport(
            $scope,
            new ModuleSettingsMapper(['Log']),
            ['Log' => '3.4.40'],
            '4.2.1',
            [1 => 'main'],
            [1 => 'admin@example.com', 2 => 'admin@example.com'],
            '2026-09-07T00:00:00+00:00',
        );
    }

    /** @return array<string, SettingsDocument> keyed by filename */
    private function byFilename(SettingsExport $export): array
    {
        $files = [];
        foreach ($export->files() as $file) {
            $files[$file->filename()] = $file;
        }
        return $files;
    }

    public function testAttributesKeysToModulesAndCore(): void
    {
        $export = $this->export(SettingsScope::all());
        $export->setSettings([
            ['id' => 'log_archive_days', 'value' => '180'],
            ['id' => 'administrator_email', 'value' => '"admin@example.com"'],
        ]);

        $files = $this->byFilename($export);
        $this->assertArrayHasKey('Log.setting.jsonc', $files);
        $this->assertArrayHasKey('core.setting.jsonc', $files);

        $log = $files['Log.setting.jsonc']->toArray();
        $this->assertEquals('3.4.40', $log['moduleVersion']);
        $this->assertEquals(['log_archive_days' => 180], $log['settings']);

        $core = $files['core.setting.jsonc']->toArray();
        $this->assertEquals('4.2.1', $core['omekaVersion']);
        $this->assertEquals(['administrator_email' => 'admin@example.com'], $core['settings']);
    }

    public function testSiteSettingUsesSlugForFilenameAndMeta(): void
    {
        $export = $this->export(SettingsScope::all());
        $export->setSiteSettings([
            ['id' => 'core_setting', 'site_id' => 1, 'value' => 'true'],
        ]);

        $files = $this->byFilename($export);
        $this->assertArrayHasKey('core.site_setting.main.jsonc', $files);
        $meta = $files['core.site_setting.main.jsonc']->toArray();
        $this->assertEquals('main', $meta['site']);
        $this->assertEquals(1, $meta['targetId']);
    }

    public function testUserSlugCollisionIsDisambiguatedById(): void
    {
        // Users 1 and 2 share an email → the second gets its id appended.
        $export = $this->export(SettingsScope::all());
        $export->setUserSettings([
            ['id' => 'locale', 'user_id' => 1, 'value' => '"nl"'],
            ['id' => 'locale', 'user_id' => 2, 'value' => '"en"'],
        ]);

        $files = $this->byFilename($export);
        $this->assertArrayHasKey('core.user_setting.admin-example-com.jsonc', $files);
        $this->assertArrayHasKey('core.user_setting.admin-example-com-2.jsonc', $files);
    }

    public function testScopeExcludesTypeModuleAndKey(): void
    {
        // Only global settings, only the Log module, excluding one key.
        $scope = new SettingsScope([SettingType::Setting], modules: ['Log'], excludedKeys: ['log_cron_last']);
        $export = $this->export($scope);
        $export->setSettings([
            ['id' => 'log_archive_days', 'value' => '180'],
            ['id' => 'log_cron_last', 'value' => '123'],
            ['id' => 'administrator_email', 'value' => '"x"'], // core → excluded by module filter
        ]);
        $export->setSiteSettings([['id' => 'log_x', 'site_id' => 1, 'value' => '1']]); // excluded by type

        $files = $this->byFilename($export);
        $this->assertEquals(['Log.setting.jsonc'], array_keys($files));
        $this->assertEquals(['log_archive_days' => 180], $files['Log.setting.jsonc']->toArray()['settings']);
    }

    public function testSiteSettingForUnknownSiteIsSkipped(): void
    {
        // A row whose site is not in the injected slug map (e.g. an orphan) is skipped, not fatal.
        $export = $this->export(SettingsScope::all());
        $export->setSiteSettings([
            ['id' => 'core_x', 'site_id' => 99, 'value' => 'true'],
        ]);

        $this->assertSame([], $export->files());
    }

    public function testUndecodableValueThrows(): void
    {
        // Silently decoding an invalid value to null would turn a round trip into a deletion.
        $export = $this->export(SettingsScope::all());

        $this->expectException(RuntimeException::class);
        $export->setSettings([
            ['id' => 'log_broken', 'value' => '{not valid json'],
        ]);
    }
}
