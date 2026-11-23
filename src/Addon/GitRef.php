<?php
namespace OSC\Addon;

use InvalidArgumentException;

/**
 * A git ref (tag, branch or commit) from a blueprint or a command argument: checked before it reaches
 * git, so it is never read as an option (no leading `-`) and follows the rules of
 * `git check-ref-format`: no `..`, `@{`, `//`, control characters, space or `~^:?*[\`; no component
 * that starts with `.` or ends with `.lock`; no leading or trailing `/`, no trailing `.`.
 */
final class GitRef
{
    public static function isValid(string $ref): bool
    {
        if ($ref === '' || $ref === '@' || $ref[0] === '-' || str_ends_with($ref, '.')) {
            return false;
        }
        if (preg_match('#[\x00-\x20\x7f~^:?*\[\\\\]|\.\.|@\{|//#', $ref) === 1) {
            return false;
        }
        if (str_starts_with($ref, '/') || str_ends_with($ref, '/')) {
            return false;
        }
        foreach (explode('/', $ref) as $component) {
            if (str_starts_with($component, '.') || str_ends_with($component, '.lock')) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string The ref, unchanged
     * @throws InvalidArgumentException When it is not a valid ref
     */
    public static function assertValid(string $ref): string
    {
        if (!self::isValid($ref)) {
            throw new InvalidArgumentException("Invalid git ref '{$ref}': a tag, branch or commit, not starting with '-'.");
        }
        return $ref;
    }
}
