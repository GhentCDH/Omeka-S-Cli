<?php
namespace OSC\Helper;

/**
 * Turn the errors of an Omeka ValidationException (ErrorStore::getErrors()) into readable lines, one
 * per message, as "key: message". Nested keys (e.g. the errors of an item's media) are joined with
 * dots: "o:media.0.ingest_source: ...".
 */
class ErrorStoreFormatter
{
    /** @param array<string|int,mixed> $errors */
    public static function format(array $errors): string
    {
        return implode("\n", self::lines($errors, ''));
    }

    /** @return string[] */
    private static function lines(array $errors, string $prefix): array
    {
        $lines = [];
        foreach ($errors as $key => $messages) {
            $path = $prefix;
            if (is_string($key)) {
                $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            } elseif (is_array($messages)) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            }
            if (is_array($messages)) {
                array_push($lines, ...self::lines($messages, $path));
            } else {
                $lines[] = ($path === '' ? '' : "{$path}: ") . (string) $messages;
            }
        }
        return $lines;
    }
}
