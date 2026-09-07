<?php

namespace Tests\Config;

use OSC\Settings\SettingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingType::class)]
class SettingTypeTest extends TestCase
{
    public function testServiceIds(): void
    {
        $this->assertEquals('Omeka\Settings', SettingType::Setting->serviceId());
        $this->assertEquals('Omeka\Settings\Site', SettingType::SiteSetting->serviceId());
        $this->assertEquals('Omeka\Settings\User', SettingType::UserSetting->serviceId());
    }

    public function testTableNameMatchesValue(): void
    {
        $this->assertEquals('setting', SettingType::Setting->tableName());
        $this->assertEquals('site_setting', SettingType::SiteSetting->tableName());
        $this->assertEquals('user_setting', SettingType::UserSetting->tableName());
    }

    public function testScopedAndTargetColumns(): void
    {
        $this->assertFalse(SettingType::Setting->isScoped());
        $this->assertTrue(SettingType::SiteSetting->isScoped());
        $this->assertTrue(SettingType::UserSetting->isScoped());

        $this->assertNull(SettingType::Setting->targetColumn());
        $this->assertEquals('site_id', SettingType::SiteSetting->targetColumn());
        $this->assertEquals('user_id', SettingType::UserSetting->targetColumn());
    }

    public function testTargetResource(): void
    {
        $this->assertNull(SettingType::Setting->targetResource());
        $this->assertEquals('sites', SettingType::SiteSetting->targetResource());
        $this->assertEquals('users', SettingType::UserSetting->targetResource());
    }

    public function testTryFromRejectsUnknown(): void
    {
        $this->assertNull(SettingType::tryFrom('block_setting'));
        $this->assertSame(SettingType::Setting, SettingType::tryFrom('setting'));
    }
}
