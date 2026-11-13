<?php
namespace OSC\Core;

use RuntimeException;
use Throwable;

/** A core swap that failed; restored() tells whether the previous core is back in place. */
class CoreSwapException extends RuntimeException
{
    public function __construct(string $message, private bool $restored, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Whether the rollback put every replaced entry back. */
    public function restored(): bool
    {
        return $this->restored;
    }
}
