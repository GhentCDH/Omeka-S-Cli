<?php
namespace Tests\Repository;

use OSC\Helper\Offline;
use OSC\Repository\AbstractRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AbstractRepository::class)]
class AbstractRepositoryTest extends TestCase
{
    use TemporaryCache;

    protected function setUp(): void
    {
        $this->setUpTemporaryCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryCache();
    }

    public function testAnUnreachableRepositoryIsFetchedOncePerRun(): void
    {
        $repository = new FakeRepository(failure: 'cURL error: Connection timed out');

        foreach ([1, 2, 3] as $attempt) {
            try {
                $repository->list();
                $this->fail('list() should rethrow the failure');
            } catch (RuntimeException $e) {
                $this->assertSame('cURL error: Connection timed out', $e->getMessage(), "attempt {$attempt}");
            }
        }

        $this->assertSame(1, $repository->fetches);
    }

    public function testRefreshRetriesAnUnreachableRepository(): void
    {
        $repository = new FakeRepository(failure: 'down');
        try {
            $repository->list();
        } catch (RuntimeException) {
        }

        $repository->refresh();
        try {
            $repository->list();
        } catch (RuntimeException) {
        }

        $this->assertSame(2, $repository->fetches);
    }

    public function testARepositoryIgnoresOfflineMode(): void
    {
        // offline mode is a use-case policy: the commands decide, repositories just fetch
        Offline::enable();
        $repository = new FakeRepository(entries: [new \stdClass()]);

        $this->assertCount(1, $repository->list());
        $this->assertSame(1, $repository->fetches);
    }
}
