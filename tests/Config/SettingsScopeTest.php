<?php

namespace Tests\Config;

use OSC\Settings\SettingsScope;
use OSC\Settings\SettingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsScope::class)]
class SettingsScopeTest extends TestCase
{
    public function testAllIncludesEverything(): void
    {
        $scope = SettingsScope::all();

        foreach (SettingType::cases() as $type) {
            $this->assertTrue($scope->includesType($type));
        }
        $this->assertTrue($scope->includesModule('Log'));
        $this->assertTrue($scope->includesSite(1));
        $this->assertTrue($scope->includesUser(1));
        $this->assertTrue($scope->includesKey('anything'));
    }

    public function testTypeFilter(): void
    {
        $scope = new SettingsScope([SettingType::Setting]);

        $this->assertTrue($scope->includesType(SettingType::Setting));
        $this->assertFalse($scope->includesType(SettingType::SiteSetting));
        $this->assertFalse($scope->includesType(SettingType::UserSetting));
    }

    public function testModuleFilterIsCaseInsensitive(): void
    {
        $scope = new SettingsScope(SettingType::cases(), modules: ['Log']);

        $this->assertTrue($scope->includesModule('log'));
        $this->assertTrue($scope->includesModule('LOG'));
        $this->assertFalse($scope->includesModule('core'));
    }

    public function testSiteAndUserFilters(): void
    {
        $scope = new SettingsScope(SettingType::cases(), siteIds: [1, 2], userIds: [5]);

        $this->assertTrue($scope->includesSite(2));
        $this->assertFalse($scope->includesSite(3));
        $this->assertTrue($scope->includesUser(5));
        $this->assertFalse($scope->includesUser(6));
    }

    public function testExcludedKeysAreCaseInsensitive(): void
    {
        $scope = new SettingsScope(SettingType::cases(), excludedKeys: ['Installation_Title']);

        $this->assertFalse($scope->includesKey('installation_title'));
        $this->assertTrue($scope->includesKey('administrator_email'));
    }

    public function testFromOptionsAllYieldsEveryType(): void
    {
        $scope = SettingsScope::fromOptions('all', null);

        foreach (SettingType::cases() as $type) {
            $this->assertTrue($scope->includesType($type));
        }
        $this->assertTrue($scope->includesModule('anything'));
    }

    public function testFromOptionsSingleTypeAndModule(): void
    {
        $scope = SettingsScope::fromOptions('setting', 'Log');

        $this->assertTrue($scope->includesType(SettingType::Setting));
        $this->assertFalse($scope->includesType(SettingType::SiteSetting));
        $this->assertTrue($scope->includesModule('log'));
        $this->assertFalse($scope->includesModule('Common'));
    }

    public function testFromOptionsNullScopeDefaultsToAll(): void
    {
        $scope = SettingsScope::fromOptions(null, null);
        $this->assertTrue($scope->includesType(SettingType::UserSetting));
    }

    public function testFromOptionsRejectsUnknownScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingsScope::fromOptions('bogus', null);
    }
}
