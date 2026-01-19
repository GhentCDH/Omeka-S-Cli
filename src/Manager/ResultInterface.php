<?php

namespace OSC\Manager;

use OSC\Repository\RepositoryInterface;
use OSC\Repository\RepositoryItemInterface;

/**
 * @template T
 */
interface ResultInterface {

    /**
     * @param RepositoryItemInterface<T> $item
     * @param RepositoryInterface $repository
     */
    public function __construct(RepositoryItemInterface $item, RepositoryInterface $repository);

    /**
     * @return T
     */
    public function getItem(): object;

    /**
     * @return RepositoryInterface<T>
     */
    public function getRepository(): RepositoryInterface;
}