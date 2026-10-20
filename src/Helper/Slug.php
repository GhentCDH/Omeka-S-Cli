<?php

namespace OSC\Helper;

/**
 * Turn instance identifiers into filesystem-friendly, human-readable slugs.
 *
 * Used for the labels in exported settings filenames. The slug is a label only: the authoritative
 * identifier (site slug, user email) is always carried in the file's metadata, so slugifying an
 * email need not be reversible.
 */
class Slug
{
    /**
     * Slugify an email address, e.g. "Admin@Example.com" => "admin-example-com".
     *
     * Any run of characters that is not a-z or 0-9 becomes a single dash; leading and trailing
     * dashes are trimmed. Two different emails can in principle collapse to the same slug, so
     * callers that need uniqueness must disambiguate (e.g. by appending the numeric id).
     */
    public static function email(string $email): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($email)));

        return trim((string) $slug, '-');
    }

    /**
     * Slugify a free text such as a title, e.g. "Mona Lisa (La Joconde)" => "mona-lisa-la-joconde", for
     * use in file names. At most 60 characters, cut at a dash when there is one; empty when nothing
     * usable is left.
     */
    public static function text(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
        if (strlen($slug) > 60) {
            $slug = substr($slug, 0, 60);
            $dash = strrpos($slug, '-');
            if ($dash !== false) {
                $slug = substr($slug, 0, $dash);
            }
        }
        return trim($slug, '-');
    }
}
