<?php
namespace Tests\Manager;

use OSC\Manager\AbstractManager;
use OSC\Manager\Result;
use OSC\Repository\AbstractRepository;
use OSC\Repository\Module\ModuleDetails;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tests\Repository\FakeRepository;
use Tests\Repository\TemporaryCache;

#[CoversClass(AbstractManager::class)]
#[UsesClass(AbstractRepository::class)]
#[UsesClass(ModuleDetails::class)]
#[UsesClass(Result::class)]
class AbstractManagerTest extends TestCase
{
    use TemporaryCache;

    protected function setUp(): void
    {
        $this->setUpTemporaryCache();
        AbstractManager::takeWarnings();
    }

    protected function tearDown(): void
    {
        AbstractManager::takeWarnings();
        $this->tearDownTemporaryCache();
    }

    private static function common(): ModuleDetails
    {
        return new ModuleDetails('Common', 'Common', '1.0.0', []);
    }

    private static function manager(FakeRepository ...$repositories): AbstractManager
    {
        $manager = new class extends AbstractManager {
            protected function registerRepositories(): void
            {
            }
        };
        foreach ($repositories as $repository) {
            $manager->addRepository($repository);
        }
        return $manager;
    }

    public function testAnUnreachableRepositoryWarnsOnceAndIsFetchedOnce(): void
    {
        $repository = new FakeRepository('omeka.org', failure: 'cURL error: timed out');
        $manager = self::manager($repository);

        $this->assertNull($manager->find('Common'));
        $this->assertNull($manager->find('Log'));

        $this->assertSame(["Repository 'Omeka.org' is unavailable: cURL error: timed out"], AbstractManager::takeWarnings());
        $this->assertSame(1, $repository->fetches);
    }
}
