<?php
namespace OSC\Source;

use InvalidArgumentException;
use OSC\Addon\GitRef;
use OSC\Source\SourceForm;
use OSC\Helper\UrlCredentials;

/**
 * A git repository to clone, at an optional ref: only where a module or theme is expected. What counts
 * as one is the spec's rule (SourceForm::isRepository()): an http(s) URL whose path ends in `.git`,
 * `https://github.com/OWNER/REPO`, `gh:owner/repo` or `git@host:path`, each with an optional `#ref`.
 * GitHub forms are cloned from `https://github.com/OWNER/REPO.git`.
 */
final class GitRepository
{
    private function __construct(
        private readonly string $cloneUrl,
        private readonly ?string $ref,
        private readonly ?string $original,
    ) {
    }

    /** @throws InvalidArgumentException When $written is no git repository, or its ref is invalid */
    public static function parse(string $written): self
    {
        $redactedWritten = UrlCredentials::redact($written);
        if (!SourceForm::isRepository($written)) {
            throw new InvalidArgumentException("'{$redactedWritten}' is not a git repository.");
        }
        [$repository, $ref] = array_pad(explode('#', $written, 2), 2, null);
        if ($ref !== null && !GitRef::isValid($ref)) {
            throw new InvalidArgumentException("'{$redactedWritten}' has an invalid git ref '{$ref}'.");
        }
        if (preg_match('#\A(?:gh:|https://github\.com/)([^/]+)/([^/]+?)/?\z#i', $repository, $m) === 1
            && !str_ends_with($repository, '.git')) {
            foreach ([$m[1], $m[2]] as $segment) {
                if ($segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException("'{$redactedWritten}' is not a GitHub repository.");
                }
            }
            return new self("https://github.com/{$m[1]}/{$m[2]}.git", $ref, $written);
        }
        $cloneUrl = preg_replace_callback('#\Ahttps?(?=://)#i', fn(array $m) => strtolower($m[0]), $repository);
        return new self($cloneUrl, $ref, $written);
    }

    public function cloneUrl(): string
    {
        return $this->cloneUrl;
    }

    public function ref(): ?string
    {
        return $this->ref;
    }

    /**
     * The same git repository at $ref (null or '' for the default branch).
     *
     * @throws InvalidArgumentException When $ref is not a valid git ref (an option, `..`, ...)
     */
    public function withRef(?string $ref): self
    {
        $ref = $ref !== '' ? $ref : null;
        if ($ref !== null && !GitRef::isValid($ref)) {
            throw new InvalidArgumentException(
                "'" . UrlCredentials::redact($this->cloneUrl) . "' has an invalid git ref '{$ref}'."
            );
        }
        // an input that was already the canonical value adds nothing to messages once the ref changes it
        $original = $this->original === $this->value() ? null : $this->original;
        return new self($this->cloneUrl, $ref, $original);
    }

    /** The clone URL plus `#ref`, unredacted; parse(value()) gives the same git repository. */
    public function value(): string
    {
        return $this->cloneUrl . ($this->ref !== null ? "#{$this->ref}" : '');
    }

    /** The input as written. */
    public function original(): ?string
    {
        return $this->original;
    }

    /** For messages, without credentials: "'<original>' (<value>)", or the value alone when they are the same. */
    public function display(): string
    {
        $value = UrlCredentials::redact($this->value());
        if ($this->original === null || $this->original === $this->value()) {
            return $value;
        }
        return "'" . UrlCredentials::redact($this->original) . "' ({$value})";
    }
}
