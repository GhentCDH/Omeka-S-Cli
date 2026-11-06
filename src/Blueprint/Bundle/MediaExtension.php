<?php
namespace OSC\Blueprint\Bundle;

/**
 * A file extension for a media downloaded without one (e.g. https://example.org/image?id=3), from its
 * Content-Type: Omeka S checks a file's extension against its whitelist, so a bundled media file needs
 * one.
 */
final class MediaExtension
{
    private const BY_CONTENT_TYPE = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/tiff'      => 'tif',
        'application/pdf' => 'pdf',
        'audio/mpeg'      => 'mp3',
        'video/mp4'       => 'mp4',
        'text/plain'      => 'txt',
    ];

    /** The extension for a Content-Type (parameters ignored), or null when it is not known. */
    public static function fromContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }
        $type = strtolower(trim(explode(';', $contentType)[0]));
        return self::BY_CONTENT_TYPE[$type] ?? null;
    }
}
