<?php
namespace OSC\Source;

use InvalidArgumentException;
use OSC\Helper\UrlCredentials;

/**
 * A file that names other files (a blueprint, an `$import`ed file, a command's config file): where it
 * is, and the root its relative sources must stay inside (a local directory, or a URL prefix ending
 * in `/`). It knows nothing about the file's content: which fields are sources is up to whoever reads
 * it (SourceFields, ItemImportConfig, VocabularyConfig, MediaConfig).
 */
final class Document
{
    public function __construct(public readonly Location $source, public readonly string $root)
    {
    }

    /**
     * A file given by the user: a local file's root is its folder (its real path), or $rootOverride
     * (--root), which must be a directory; a remote file's root is its folder URL, and cannot be widened.
     *
     * @throws InvalidArgumentException When --root is not a directory, or is given for a remote file
     */
    public static function open(Location $file, ?string $rootOverride): self
    {
        if ($file->isRemote()) {
            if ($rootOverride !== null) {
                $redactedFile = UrlCredentials::redact($file->original() ?? $file->value());
                throw new InvalidArgumentException(
                    "--root only applies to a local file; '{$redactedFile}' is remote, so its root is its own folder."
                );
            }
            return new self($file, $file->folder());
        }
        // an absolute top-level path makes every local reference below it absolute too
        $path = realpath($file->value()) ?: $file->value();
        $local = LocalPath::fromAbsolute($path, $file->original());
        if ($rootOverride !== null) {
            $root = realpath($rootOverride);
            if ($root === false || !is_dir($root)) {
                throw new InvalidArgumentException("Root '{$rootOverride}' is not a directory.");
            }
            return new self($local, $root);
        }
        return new self($local, $local->folder());
    }

    /**
     * A source written in this file, resolved by the spec's rules (see DocumentSourceResolver::file()).
     *
     * @throws SourceException
     */
    public function resolve(string $raw): Location
    {
        return DocumentSourceResolver::file($raw, $this);
    }

    public function isRemote(): bool
    {
        return $this->source->isRemote();
    }
}
