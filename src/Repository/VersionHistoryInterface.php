<?php

namespace OSC\Repository;

interface VersionHistoryInterface
{
    /**
     * @return VersionableInterface[]
     */
    public function getVersions(): array;

    /**
     * @param string $version
     * @return VersionableInterface|null
     */
    public function getVersion(string $versionNumber): ?VersionableInterface;

    public function getLatestVersion(): ?VersionableInterface;
}