<?php
namespace Tests\Exceptions;

use OSC\Addon\AddonType;
use OSC\Exceptions\AddonExistsException;
use OSC\Exceptions\WarningException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddonExistsException::class)]
#[UsesClass(AddonType::class)]
class AddonExistsExceptionTest extends TestCase
{
    public function testTheMessageNamesTheAddonAndTheForceOption(): void
    {
        $e = new AddonExistsException(AddonType::Module, 'Common', '/var/www/omeka-s/modules/Common');
        $this->assertSame(
            "Module 'Common' already exists in '/var/www/omeka-s/modules/Common'. Use the --force option to download anyway.",
            $e->getMessage()
        );
    }

    public function testAThemeIsNamedATheme(): void
    {
        $e = new AddonExistsException(AddonType::Theme, 'freedom', '/x/themes/freedom');
        $this->assertStringStartsWith("Theme 'freedom' already exists", $e->getMessage());
        $this->assertSame(AddonType::Theme, $e->type());
        $this->assertSame('freedom', $e->folder());
    }

    public function testItIsAWarning(): void
    {
        $this->assertInstanceOf(WarningException::class, new AddonExistsException(AddonType::Module, 'Common', '/x'));
    }
}
