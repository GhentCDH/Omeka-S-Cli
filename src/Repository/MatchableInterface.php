<?php
namespace OSC\Repository;

interface MatchableInterface
{
    public function matches($query): bool;
}