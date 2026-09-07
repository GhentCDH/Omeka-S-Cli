<?php

namespace Tests\Helper;

use OSC\Helper\ModuleSettingsMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleSettingsMapper::class)]
class ModuleSettingsMapperTest extends TestCase
{
    public function testKeyIsAttributedToItsModuleByPrefix(): void
    {
        $mapper = new ModuleSettingsMapper(['Log', 'Common']);

        $this->assertEquals('Log', $mapper->moduleForKey('log_archive_days'));
        $this->assertEquals('Common', $mapper->moduleForKey('common_something'));
    }

    public function testUnknownPrefixFallsBackToCore(): void
    {
        $mapper = new ModuleSettingsMapper(['Log']);

        $this->assertEquals(ModuleSettingsMapper::CORE, $mapper->moduleForKey('administrator_email'));
        $this->assertEquals(ModuleSettingsMapper::CORE, $mapper->moduleForKey('version'));
    }

    public function testLongestPrefixWins(): void
    {
        $mapper = new ModuleSettingsMapper(['Value', 'ValueSuggest']);

        $this->assertEquals('ValueSuggest', $mapper->moduleForKey('valuesuggest_settings'));
        $this->assertEquals('Value', $mapper->moduleForKey('value_thing'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $mapper = new ModuleSettingsMapper(['Log']);

        $this->assertEquals('Log', $mapper->moduleForKey('LOG_ARCHIVE_DAYS'));
    }
}
