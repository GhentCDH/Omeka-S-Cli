<?php

namespace OSC\Database;

use Exception;

/**
 * Open dump files, transparently compressed or not.
 *
 * "compress.zlib://" is the stream equivalent of gzopen(), so no gzip binary has to be present.
 */
class StreamFactory
{
    private const GZIP_MAGIC = "\x1f\x8b";

    /**
     * @return resource
     */
    public static function openWrite(string $path, bool $gzip)
    {
        if ($path === 'php://stdout') {
            $handle = fopen($path, 'wb');
        } else {
            $handle = fopen($gzip ? 'compress.zlib://' . $path : $path, 'wb');
        }

        if (!$handle) {
            throw new Exception("Could not open '{$path}' for writing.");
        }

        return $handle;
    }

    /**
     * @return resource
     */
    public static function openRead(string $path)
    {
        if (!file_exists($path)) {
            throw new Exception("The file '{$path}' does not exist.");
        }
        if (!is_readable($path)) {
            throw new Exception("The file '{$path}' is not readable.");
        }

        $handle = fopen(self::isGzip($path) ? 'compress.zlib://' . $path : $path, 'rb');
        if (!$handle) {
            throw new Exception("Could not open '{$path}' for reading.");
        }

        return $handle;
    }

    /**
     * Detect compression from the file content, not from its name.
     */
    public static function isGzip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $magic = (string) fread($handle, 2);
        fclose($handle);

        return $magic === self::GZIP_MAGIC;
    }

    /**
     * Whether a target file name asks for compression.
     */
    public static function wantsGzip(string $path): bool
    {
        return str_ends_with(strtolower($path), '.gz');
    }
}
