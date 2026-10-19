<?php
namespace Tests\Blueprint\Bundle;

use OSC\Blueprint\Bundle\MediaExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MediaExtension::class)]
class MediaExtensionTest extends TestCase
{
    public function testKnownTypes(): void
    {
        $expected = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/tiff' => 'tif',
            'application/pdf' => 'pdf', 'audio/mpeg' => 'mp3', 'video/mp4' => 'mp4', 'text/plain' => 'txt',
        ];
        foreach ($expected as $type => $extension) {
            $this->assertSame($extension, MediaExtension::fromContentType($type), $type);
        }
        $this->assertNull(MediaExtension::fromContentType('application/octet-stream'));
        $this->assertNull(MediaExtension::fromContentType(null));
    }

    public function testParametersAreIgnored(): void
    {
        $this->assertSame('txt', MediaExtension::fromContentType('Text/Plain; charset=utf-8'));
    }
}
