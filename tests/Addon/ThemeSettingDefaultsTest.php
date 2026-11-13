<?php
namespace Tests\Addon;

use OSC\Addon\ThemeSettingDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeSettingDefaults::class)]
class ThemeSettingDefaultsTest extends TestCase
{
    public function testTakesTheValueOfAnElement(): void
    {
        $spec = ['elements' => [
            'accent_color' => ['name' => 'accent_color', 'attributes' => ['value' => '#920b0b']],
        ]];

        $this->assertSame(['accent_color' => '#920b0b'], ThemeSettingDefaults::fromConfigSpec($spec));
    }

    public function testLeavesOutAnElementWithoutAValue(): void
    {
        $spec = ['elements' => [
            'logo' => ['name' => 'logo', 'type' => 'Omeka\Form\Element\Asset'],
            'banner_position' => ['name' => 'banner_position', 'attributes' => ['id' => 'banner_position']],
            'footer' => ['name' => 'footer', 'attributes' => ['value' => 'Powered by Omeka S']],
        ]];

        $this->assertSame(['footer' => 'Powered by Omeka S'], ThemeSettingDefaults::fromConfigSpec($spec));
    }

    public function testKeepsAZeroAndAnEmptyValue(): void
    {
        // the default theme declares nav_depth = 0
        $spec = ['elements' => [
            'nav_depth' => ['name' => 'nav_depth', 'attributes' => ['value' => '0']],
            'banner_height' => ['name' => 'banner_height', 'attributes' => ['value' => '']],
        ]];

        $this->assertSame(['nav_depth' => '0', 'banner_height' => ''], ThemeSettingDefaults::fromConfigSpec($spec));
    }

    public function testKeepsAnArrayValue(): void
    {
        // a multi-select: elements.x.attributes.value[] = ...
        $spec = ['elements' => [
            'regions' => ['name' => 'regions', 'attributes' => ['value' => ['main', 'sidebar']]],
        ]];

        $this->assertSame(['regions' => ['main', 'sidebar']], ThemeSettingDefaults::fromConfigSpec($spec));
    }

    public function testUsesTheElementKeyWhenTheNameIsMissing(): void
    {
        $spec = ['elements' => [
            'accent_color' => ['attributes' => ['value' => '#920b0b']],
        ]];

        $this->assertSame(['accent_color' => '#920b0b'], ThemeSettingDefaults::fromConfigSpec($spec));
    }

    public function testANullOrEmptySpecHasNoDefaults(): void
    {
        $this->assertSame([], ThemeSettingDefaults::fromConfigSpec(null));
        $this->assertSame([], ThemeSettingDefaults::fromConfigSpec([]));
        $this->assertSame([], ThemeSettingDefaults::fromConfigSpec(['elements' => []]));
    }

    public function testResourcePageBlocksAreNotDefaults(): void
    {
        // Omeka falls back to the ini for these itself (ResourcePageBlockLayout\Manager)
        $spec = ['resource_page_blocks' => ['items' => ['main' => ['mediaEmbeds', 'values']]]];

        $this->assertSame([], ThemeSettingDefaults::fromConfigSpec($spec));
    }
}
