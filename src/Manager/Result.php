<?php

namespace OSC\Manager;

use OSC\Repository\RepositoryInterface;
use OSC\Repository\RepositoryItemInterface;

/**
 * @template T
 * @template-extends ResultInterface<T>
 */
class Result implements ResultInterface
{
    /** @var RepositoryItemInterface<T> */
    protected RepositoryItemInterface $item;
    /** @var RepositoryInterface<T> */
    protected RepositoryInterface $repository;
    protected ?string $version;

    public function __construct(RepositoryItemInterface $item, RepositoryInterface $repository)
    {
        $this->item = $item;
        $this->repository = $repository;
    }


    /**
     * @return T
     */
    public function getItem(): RepositoryItemInterface
    {
        return $this->item;
    }

    public function getRepository(): RepositoryInterface
    {
        return $this->repository;
    }
}