<?php
namespace Tests\Dummy\Config;

use OSC\Dummy\Config\ItemConfig;
use OSC\Dummy\Config\ItemSetConfig;
use OSC\Dummy\Config\ResourceConfig;
use OSC\Helper\ResourceFetcher;
use OSC\Source\LocalPath;
use OSC\Source\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceConfig::class)]
#[CoversClass(ItemConfig::class)]
#[CoversClass(ItemSetConfig::class)]
#[UsesClass(ResourceFetcher::class)]
#[UsesClass(LocalPath::class)]
#[UsesClass(Location::class)]
class ResourceConfigTest extends TestCase
{
    public function testAConfigIsReadFromALocation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dummy');
        file_put_contents($file, '{ "dcterms:title": [ { "generator": "literal", "mode": "values", "values": [ "A" ] } ] }');
        try {
            $this->assertInstanceOf(ItemSetConfig::class, ItemSetConfig::fromSource(LocalPath::fromAbsolute($file)));
            $this->assertInstanceOf(ItemConfig::class, ItemConfig::fromSource(LocalPath::fromAbsolute($file)));
        } finally {
            unlink($file);
        }
    }
}
