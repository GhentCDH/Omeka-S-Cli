<?php
namespace OSC\Blueprint;

use InvalidArgumentException;

/**
 * What a deploy does when a blueprint entry cannot be applied (the shared format's `onError`): `fail`
 * (the default) stops the deploy, `skip` leaves the entry out with a warning. Taken by `itemSets` and
 * `items` entries; a record that already exists is not an error.
 */
enum OnError: string
{
    case Fail = 'fail';
    case Skip = 'skip';

    public const KEY = 'onError';

    /**
     * The policy of an entry and the entry without its `onError`, so that the rest can be handed to a
     * command that takes only its own fields.
     *
     * @return array{0: self, 1: array}
     * @throws InvalidArgumentException When `onError` is not 'fail' or 'skip'
     */
    public static function split(array $entry): array
    {
        if (!array_key_exists(self::KEY, $entry)) {
            return [self::Fail, $entry];
        }
        $value = $entry[self::KEY];
        $onError = is_string($value) ? self::tryFrom($value) : null;
        if ($onError === null) {
            $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);
            throw new InvalidArgumentException("onError must be 'fail' or 'skip', not {$shown}.");
        }
        unset($entry[self::KEY]);
        return [$onError, $entry];
    }
}
