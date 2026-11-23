<?php

namespace OSC\Helper;

use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Blueprint\SourceForm;

/**
 * Parses the add-on argument of module:download / theme:download (and their update commands): the
 * source grammar of a blueprint (AddonReference::fromSource()), plus what only a command line has:
 * `id[:version]`, a repository lookup by name, and a relative path to a ZIP (resolved against the
 * working directory, so it must end in `.zip`).
 */
class ResourceUriParser
{
    /** @throws InvalidArgumentException When the argument has no known form */
    public static function parse(string $argument): AddonReference
    {
        if (preg_match('/\A([A-Za-z0-9_-]+)(?::([A-Za-z0-9_.-]+))?\z/', $argument, $m) === 1) {
            return AddonReference::byName($m[1], $m[2] ?? null);
        }
        if (!str_starts_with($argument, '/') && SourceForm::of($argument) === SourceForm::RelativePath) {
            if (preg_match('/\.zip\z/i', $argument) === 1) {
                return AddonReference::zipFile($argument);
            }
            throw new InvalidArgumentException("Could not determine argument type: '{$argument}'");
        }
        return AddonReference::fromSource($argument);
    }
}
