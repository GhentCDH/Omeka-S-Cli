<?php
namespace OSC\Blueprint;

/**
 * The form of a source written in a blueprint (`source`, `$import`, a media `url`), told apart by its
 * prefix as the shared spec does (datasource.relativePath | url | git):
 * - Url: `http(s)://` and a host, in any letter case;
 * - Git: `gh:`, `gl:` or `git@host:`;
 * - RelativePath: anything else that is not absolute, has no URI scheme (a drive letter counts as
 *   one) and does not start with whitespace.
 *
 * Only the prefix is checked: the schema validates the blueprint, and SourceResolver resolves the
 * value and keeps it safe. This is the loader's own guard for what the schema may not catch.
 */
enum SourceForm
{
    case RelativePath;
    case Url;
    case Git;

    private const URL = '#\Ahttps?://[^/?\#]#i';
    private const GIT = '#\A(?:gh:|gl:|git@[^/:]*:)#';
    private const SCHEME = '#\A[A-Za-z][A-Za-z0-9+.-]*:#';
    private const ABSOLUTE = '#\A[/\\\\]#';
    private const LEADING_SPACE = '#\A[\s\p{Z}]#u';

    /** The form of $value, or null when it has none (see rejection() for why). */
    public static function of(string $value): ?self
    {
        if (self::rejection($value) !== null) {
            return null;
        }
        if (preg_match(self::URL, $value) === 1) {
            return self::Url;
        }
        return preg_match(self::GIT, $value) === 1 ? self::Git : self::RelativePath;
    }

    /**
     * Whether a source is a git repository rather than a single file (the spec's rule): `gh:owner/repo`
     * without a `:path`, `git@host:path`, an http(s) URL whose path ends in `.git`, or
     * `https://github.com/OWNER/REPO` (a trailing `/` allowed), each with an optional `#ref`. Any other
     * URL, and `gh:`/`gl:` with a path, is a file. Only modules and themes may use a repository.
     */
    public static function isRepository(string $value): bool
    {
        $form = self::of($value);
        if ($form === self::Git) {
            return str_starts_with($value, 'git@') || preg_match('#\Agh:[^/@:\#]+/[^/@:\#]+(?:\#.*)?\z#s', $value) === 1;
        }
        if ($form !== self::Url) {
            return false;
        }
        $path = (string) parse_url(preg_replace('#\#.*\z#s', '', $value), PHP_URL_PATH);
        if (str_ends_with($path, '.git')) {
            return true;
        }
        return preg_match('#\Ahttps://github\.com/[^/?\#]+/[^/?\#]+/?(?:\#.*)?\z#is', $value) === 1;
    }

    /** Why $value is not a source, or null when it has a form. */
    public static function rejection(string $value): ?string
    {
        if ($value === '') {
            return 'is empty';
        }
        if (preg_match(self::LEADING_SPACE, $value) !== 0) {
            return 'starts with whitespace';
        }
        if (preg_match(self::URL, $value) === 1 || preg_match(self::GIT, $value) === 1) {
            return null;
        }
        if (preg_match(self::ABSOLUTE, $value) === 1 || preg_match('#\A[A-Za-z]:[/\\\\]#', $value) === 1) {
            return 'is an absolute path; use a path relative to the file that declares it, or a URL';
        }
        if (preg_match('#\Ahttps?:#i', $value) === 1) {
            return 'is an http(s) URL without a host';
        }
        if (preg_match(self::SCHEME, $value) === 1) {
            return "starts with a URI scheme; only http(s)://, gh:, gl: and git@host: are supported"
                . " (a relative path that starts like one needs './')";
        }
        return null;
    }
}
