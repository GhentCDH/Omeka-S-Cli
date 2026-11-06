<?php
namespace Tests\Addon;

use OSC\Addon\AddonVersion;
use OSC\Repository\Module\ModuleVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonVersion::class)]
#[UsesClass(ModuleVersion::class)]
class AddonVersionTest extends TestCase
{
    public function testNormalizeStripsBlanksAndALeadingV(): void
    {
        $this->assertSame('1.2.0', AddonVersion::normalize(' v1.2.0 '));
        $this->assertSame('1.2.0', AddonVersion::normalize('V1.2.0'));
    }

    public function testEqualsComparesNormalizedVersions(): void
    {
        $this->assertTrue(AddonVersion::equals('v1.2.0', '1.2.0'));
        $this->assertFalse(AddonVersion::equals('1.2.0', '1.3.0'));
    }

    public function testNoConstraintIsCompatible(): void
    {
        $this->assertTrue(AddonVersion::satisfies('4.1.0', null));
        $this->assertTrue(AddonVersion::satisfies('4.1.0', ''));
    }

    public function testSatisfiesChecksTheConstraint(): void
    {
        $this->assertTrue(AddonVersion::satisfies('4.1.0', '^4.0'));
        $this->assertFalse(AddonVersion::satisfies('3.2.0', '^4.0'));
    }

    public function testLatestCompatibleSkipsIncompatibleAndUnconstrainedVersions(): void
    {
        $versions = [
            self::version('1.0.0', '^4.0'),
            self::version('2.0.0', '^4.0'),
            self::version('3.0.0', '^5.0'),
            self::version('4.0.0', null),
        ];

        $this->assertSame('2.0.0', AddonVersion::latestCompatible($versions, '4.1.0')?->getVersionNumber());
        $this->assertNull(AddonVersion::latestCompatible($versions, '3.0.0'));
    }

    public function testIsCompatibleAcceptsAVersionWithoutConstraint(): void
    {
        $this->assertTrue(AddonVersion::isCompatible(self::version('1.0.0', null), '4.1.0'));
        $this->assertFalse(AddonVersion::isCompatible(self::version('1.0.0', '^5.0'), '4.1.0'));
    }

    private static function version(string $number, ?string $constraint): ModuleVersion
    {
        return new ModuleVersion($number, '2026-01-01', "https://example.org/{$number}.zip", $constraint);
    }
}
