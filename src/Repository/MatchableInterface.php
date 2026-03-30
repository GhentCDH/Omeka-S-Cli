<?php
namespace OSC\Repository;

/**
 * An interface for items that can be fuzzy/partial matched against a query.
 */
interface MatchableInterface
{
    public function matches($query): bool;
}