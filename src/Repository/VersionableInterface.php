<?php
namespace OSC\Repository;

interface VersionableInterface
{
    public function getVersionNumber(): string;
}