<?php
namespace OSC\Helper;

/**
 * Keeps the credentials of a URL (`https://user:password@host/…`) out of what the CLI prints: a shared
 * blueprint or a log must not show them. Every message goes through redact() on its way out (the
 * command output helpers and the application's error output); code that collects text for data output
 * (validation errors, violations) redacts it itself.
 */
final class UrlCredentials
{
    /** $text (a URL, or a message with URLs in it) with the user part of every http(s) URL replaced by `***`. */
    public static function redact(string $text): string
    {
        return preg_replace('#\b(https?://)[^/?\#\s\'"]+@#i', '$1***@', $text) ?? $text;
    }
}
