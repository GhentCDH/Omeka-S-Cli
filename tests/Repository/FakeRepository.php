<?php
namespace Tests\Repository;

use OSC\Repository\AbstractRepository;
use RuntimeException;

/** A repository whose entries() is counted, and fails when told to. */
class FakeRepository extends AbstractRepository
{
    public int $fetches = 0;

    /**
     * @param object[] $entries
     */
    public function __construct(
        private string $id = 'fake',
        private array $entries = [],
        private ?string $failure = null,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return ucfirst($this->id);
    }

    protected function entries(): array
    {
        $this->fetches++;
        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }
        return $this->entries;
    }

    protected function getCacheKey(): string
    {
        return 'fake_repository_' . $this->id;
    }
}
