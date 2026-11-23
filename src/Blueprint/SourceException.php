<?php
namespace OSC\Blueprint;

use Exception;

/** A source written in a blueprint that cannot be used: not a source at all, or outside the root. */
final class SourceException extends Exception
{
    public const INVALID = 'invalid';
    public const OUTSIDE_ROOT = 'outside-root';

    public function __construct(string $message, public readonly string $reason = self::INVALID)
    {
        parent::__construct($message);
    }
}
