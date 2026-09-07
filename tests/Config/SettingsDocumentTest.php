<?php

namespace Tests\Config;

use InvalidArgumentException;
use OSC\Settings\SettingsDocument;
use OSC\Settings\SettingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsDocument::class)]
class SettingsDocumentTest extends TestCase
{
    public function testFromArrayGlobalSetting(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'setting',
            'module' => 'Log',
            'moduleVersion' => '3.4.40',
            'settings' => ['log_archive_days' => 180],
        ]);

        $this->assertSame(SettingType::Setting, $doc->type());
        $this->assertEquals('Log', $doc->module());
        $this->assertEquals('3.4.40', $doc->moduleVersion());
        $this->assertEquals(['log_archive_days' => 180], $doc->settings());
        $this->assertFalse($doc->isCore());
    }

    public function testFromArraySiteSetting(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'site_setting',
            'module' => 'core',
            'site' => 'main',
            'targetId' => 1,
            'settings' => [],
        ]);

        $this->assertSame(SettingType::SiteSetting, $doc->type());
        $this->assertEquals('main', $doc->site());
        $this->assertEquals(1, $doc->targetId());
        $this->assertTrue($doc->isCore());
    }

    public function testFromArrayUserSetting(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'user_setting',
            'module' => 'core',
            'user' => 'admin@example.com',
            'settings' => ['locale' => 'nl'],
        ]);

        $this->assertSame(SettingType::UserSetting, $doc->type());
        $this->assertEquals('admin@example.com', $doc->user());
    }

    public function testFromArrayRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray(['type' => 'nope', 'module' => 'Log', 'settings' => []]);
    }

    public function testFromArrayRejectsMissingModule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray(['type' => 'setting', 'settings' => []]);
    }

    public function testFromArrayRejectsNonArraySettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray(['type' => 'setting', 'module' => 'Log', 'settings' => 'nope']);
    }

    public function testFromArrayRejectsScopedWithoutTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray(['type' => 'site_setting', 'module' => 'core', 'settings' => []]);
    }

    public function testToArrayOmitsNullFieldsAndRoundTrips(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'setting',
            'module' => 'core',
            'omekaVersion' => '4.2.1',
            'exportedAt' => '2026-09-07T00:00:00+00:00',
            'settings' => ['administrator_email' => 'admin@example.com'],
        ]);

        $array = $doc->toArray();

        $this->assertArrayNotHasKey('moduleVersion', $array);
        $this->assertArrayNotHasKey('site', $array);
        $this->assertArrayNotHasKey('targetId', $array);
        $this->assertEquals('4.2.1', $array['omekaVersion']);

        // Re-decoding the rendered array yields an equivalent document.
        $again = SettingsDocument::fromArray($array);
        $this->assertEquals($doc->toArray(), $again->toArray());
    }

    public function testFilename(): void
    {
        $global = new SettingsDocument(SettingType::Setting, 'Log', []);
        $this->assertEquals('Log.setting.jsonc', $global->filename());

        $site = new SettingsDocument(SettingType::SiteSetting, 'core', [], scopeLabel: 'main');
        $this->assertEquals('core.site_setting.main.jsonc', $site->filename());

        $user = new SettingsDocument(SettingType::UserSetting, 'core', [], scopeLabel: 'admin-example-com');
        $this->assertEquals('core.user_setting.admin-example-com.jsonc', $user->filename());
    }

    public function testAddSettingAccumulates(): void
    {
        $doc = new SettingsDocument(SettingType::Setting, 'Log', []);
        $doc->addSetting('log_a', 1);
        $doc->addSetting('log_b', 2);

        $this->assertEquals(['log_a' => 1, 'log_b' => 2], $doc->settings());
    }

    public function testToArrayEmitsSortedKeys(): void
    {
        $doc = new SettingsDocument(SettingType::Setting, 'Log', []);
        $doc->addSetting('log_b', 2);
        $doc->addSetting('log_a', 1);

        $this->assertSame(['log_a', 'log_b'], array_keys($doc->toArray()['settings']));
    }

    public function testFromArrayDerivesSiteScopeLabelForFilename(): void
    {
        // A decoded site document must reproduce the filename it was written under (regression:
        // fromArray() used to drop the scope label, collapsing every site into one file).
        $doc = SettingsDocument::fromArray([
            'type' => 'site_setting',
            'module' => 'core',
            'site' => 'main',
            'targetId' => 1,
            'settings' => [],
        ]);

        $this->assertEquals('core.site_setting.main.jsonc', $doc->filename());
    }

    public function testFromArrayDerivesUserScopeLabelFromEmail(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'user_setting',
            'module' => 'core',
            'user' => 'Admin@Example.com',
            'settings' => [],
        ]);

        $this->assertEquals('core.user_setting.admin-example-com.jsonc', $doc->filename());
    }

    public function testFromArrayRejectsNonNumericTargetId(): void
    {
        // A garbage id must be rejected, not cast to 0 and then pass the "must identify a target" check.
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray([
            'type' => 'site_setting',
            'module' => 'core',
            'targetId' => 'not-a-number',
            'settings' => [],
        ]);
    }

    public function testFromArrayRejectsZeroTargetId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray([
            'type' => 'site_setting',
            'module' => 'core',
            'targetId' => 0,
            'settings' => [],
        ]);
    }

    public function testFromArrayRejectsNonScalarSite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SettingsDocument::fromArray([
            'type' => 'site_setting',
            'module' => 'core',
            'site' => ['main'],
            'settings' => [],
        ]);
    }
}
