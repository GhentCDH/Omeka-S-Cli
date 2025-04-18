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
     * @param string|null $version
     */
    public function __construct(RepositoryItemInterface $item, RepositoryInterface $repository, ?string $version);

    /**
     * @return T
     */
    public function getItem(): object;

    /**
     * @return RepositoryInterface<T>
     */
    public function getRepository(): RepositoryInterface;

    public function getVersionNumber(): ?string;
}