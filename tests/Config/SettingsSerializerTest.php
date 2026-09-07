<?php

namespace Tests\Config;

use Exception;
use OSC\Settings\SettingsDocument;
use OSC\Settings\SettingsSerializer;
use OSC\Settings\SettingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsSerializer::class)]
class SettingsSerializerTest extends TestCase
{
    private SettingsSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new SettingsSerializer();
    }

    public function testEncodeStartsWithCommentAndIsValidJsonc(): void
    {
        $doc = new SettingsDocument(SettingType::Setting, 'Log', ['log_archive_days' => 180]);

        $content = $this->serializer->encode($doc);

        $this->assertStringStartsWith('// Log.setting.jsonc', $content);
        // The leading comment must not break decoding.
        $decoded = $this->serializer->decode($content);
        $this->assertEquals(['log_archive_days' => 180], $decoded->settings());
    }

    public function testEncodeDecodeRoundTrips(): void
    {
        $doc = SettingsDocument::fromArray([
            'type' => 'user_setting',
            'module' => 'core',
            'user' => 'admin@example.com',
            'targetId' => 1,
            'exportedAt' => '2026-09-07T00:00:00+00:00',
            'settings' => ['locale' => 'nl'],
        ]);

        $again = $this->serializer->decode($this->serializer->encode($doc));

        $this->assertEquals($doc->toArray(), $again->toArray());
    }

    public function testDecodeToleratesCommentsAndTrailingCommas(): void
    {
        $content = <<<'JSONC'
        {
            // a comment
            "type": "setting",
            "module": "Log",
            "settings": {
                "log_archive_days": 180, // inline comment
            },
        }
        JSONC;

        $doc = $this->serializer->decode($content);

        $this->assertEquals('Log', $doc->module());
        $this->assertEquals(['log_archive_days' => 180], $doc->settings());
    }

    public function testDecodeErrorNamesTheSource(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('bad.jsonc');
        $this->serializer->decode('{ "type": "nope", "module": "x", "settings": {} }', 'bad.jsonc');
    }

    public function testWriteThenReadRoundTripsThroughDisk(): void
    {
        $dir = sys_get_temp_dir() . '/osc-serializer-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $doc = SettingsDocument::fromArray([
                'type' => 'site_setting',
                'module' => 'core',
                'site' => 'main',
                'targetId' => 1,
                'settings' => ['locale' => 'nl'],
            ]);

            // write() returns the path and uses the document's own filename.
            $path = $this->serializer->write($doc, $dir);
            $this->assertSame($dir . '/core.site_setting.main.jsonc', $path);
            $this->assertFileExists($path);

            // read() fetches and decodes it back into an equivalent document.
            $again = $this->serializer->read($path);
            $this->assertEquals($doc->toArray(), $again->toArray());
            $this->assertEquals('core.site_setting.main.jsonc', $again->filename());
        } finally {
            @unlink($dir . '/core.site_setting.main.jsonc');
            @rmdir($dir);
        }
    }

    public function testWriteTrimsTrailingSeparator(): void
    {
        $dir = sys_get_temp_dir() . '/osc-serializer-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $doc = new SettingsDocument(SettingType::Setting, 'Log', ['log_a' => 1]);
            $path = $this->serializer->write($doc, $dir . '/');
            $this->assertSame($dir . '/Log.setting.jsonc', $path);
        } finally {
            @unlink($dir . '/Log.setting.jsonc');
            @rmdir($dir);
        }
    }
}
