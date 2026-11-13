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

    public function testIsNewerComparesVersionsNumerically(): void
    {
        $this->assertTrue(AddonVersion::isNewer('1.10.0', '1.9.0'));
        $this->assertFalse(AddonVersion::isNewer('1.9.0', '1.10.0'));
    }

    public function testIsNewerIgnoresAVPrefix(): void
    {
        $this->assertFalse(AddonVersion::isNewer('v1.1.0', '1.1.0'));
        $this->assertFalse(AddonVersion::isNewer('1.1.0', 'v1.1.0'));
        $this->assertTrue(AddonVersion::isNewer('v1.2.0', '1.1.0'));
    }

    public function testAnOlderRepositoryVersionIsNotNewer(): void
    {
        $this->assertFalse(AddonVersion::isNewer('1.0.7', '1.1.0'));
    }

    public function testIsNewerTreatsAMissingTrailingZeroAsEqual(): void
    {
        $this->assertFalse(AddonVersion::isNewer('1.1.0', '1.1'));
        $this->assertFalse(AddonVersion::isNewer('1.1', '1.1.0'));
    }

    public function testIsNewerRanksAPreReleaseBelowItsRelease(): void
    {
        $this->assertTrue(AddonVersion::isNewer('1.0.0', '1.0.0-beta'));
        $this->assertFalse(AddonVersion::isNewer('1.0.0-beta', '1.0.0'));
    }

    public function testIsNewerFallsBackForAVersionItCannotParse(): void
    {
        $this->assertTrue(AddonVersion::isNewer('2.0', 'not a version'));
    }

    public function testAnUpdateIsTheLatestCompatibleRelease(): void
    {
        $versions = [self::version('1.2.0', '^4.0'), self::version('1.3.0', '^5.0')];

        $this->assertTrue(AddonVersion::updateAvailable('1.1.0', $versions, '4.2.1'));
        // 1.3.0 is the latest release, but it needs Omeka S 5: the update would install 1.2.0
        $this->assertFalse(AddonVersion::updateAvailable('1.2.0', $versions, '4.2.1'));
    }

    public function testANewerLocalVersionIsNotDowngraded(): void
    {
        $versions = [self::version('1.2.0', '^4.0'), self::version('1.3.0', '^5.0')];
        $this->assertFalse(AddonVersion::updateAvailable('1.2.5', $versions, '4.2.1'));
    }

    public function testNoCompatibleReleaseIsNoUpdate(): void
    {
        $this->assertFalse(AddonVersion::updateAvailable('1.0.0', [self::version('2.0.0', '^5.0')], '4.2.1'));
    }

    public function testWithoutAnInstalledVersionOrRepositoryItIsUnknown(): void
    {
        $this->assertNull(AddonVersion::updateAvailable(null, [self::version('2.0.0', '^4.0')], '4.2.1'));
        $this->assertNull(AddonVersion::updateAvailable('1.0.0', null, '4.2.1'));
    }
}
