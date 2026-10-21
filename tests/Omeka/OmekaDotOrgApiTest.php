<?php
namespace Tests\Omeka;

use OSC\Omeka\OmekaDotOrgApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OmekaDotOrgApi::class)]
class OmekaDotOrgApiTest extends TestCase
{
    public function testCoreReleaseUrl(): void
    {
        $this->assertSame(
            'https://github.com/omeka/omeka-s/releases/download/v4.1.1/omeka-s-4.1.1.zip',
            OmekaDotOrgApi::coreReleaseUrl('4.1.1')
        );
    }
}
