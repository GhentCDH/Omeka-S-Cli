<?php
namespace Tests\Omeka;

use OSC\Omeka\OmekaDotOrgApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

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

    public function testTheLatestVersionIsTrimmed(): void
    {
        $api = new OmekaDotOrgApi(fn(string $url) => " 4.1.1\n");
        $this->assertSame('4.1.1', $api->getLatestOmekaVersion());
    }

    public function testAnAnswerThatIsNotAVersionIsRejected(): void
    {
        $api = new OmekaDotOrgApi(fn(string $url) => "<!DOCTYPE html><html><body>Maintenance</body></html>");
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The Omeka S version API returned an unexpected answer');
        $api->getLatestOmekaVersion();
    }
}
