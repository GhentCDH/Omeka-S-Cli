<?php
namespace OSC\Blueprint\Bundle;

use Exception;
use OSC\Helper\Path;

/**
 * The output directory of a bundle. The bundle is built in a temporary sibling directory and moved
 * into place only when complete, so a failed run never leaves a half-written bundle, nor touches the
 * bundle that was there.
 */
final class BundleOutput
{
    private function __construct(private string $target, private string $partial)
    {
    }

    /**
     * Check that $target can receive a bundle (absent or empty, or $force to replace it on commit) and
     * create the temporary sibling directory to build it in.
     *
     * @throws Exception
     */
    public static function prepare(string $target, bool $force): self
    {
        $target = rtrim($target, '/');
        if (file_exists($target) && !is_dir($target)) {
            throw new Exception("Output '{$target}' exists and is not a directory.");
        }
        if (!$force && !Path::isEmptyDir($target)) {
            throw new Exception("Output directory '{$target}' is not empty; use --force to replace it.");
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new Exception("Could not create '{$parent}'.");
        }
        $partial = $target . '.partial-' . bin2hex(random_bytes(4));
        if (!mkdir($partial, 0777)) {
            throw new Exception("Could not create '{$partial}'.");
        }
        return new self($target, $partial);
    }

    /** The directory to build the bundle in. */
    public function path(): string
    {
        return $this->partial;
    }

    /** Move the finished bundle into place, replacing what was there (prepare() checked that this is allowed). */
    public function commit(): void
    {
        if (is_dir($this->target)) {
            Path::removeFolder($this->target);
        }
        if (!rename($this->partial, $this->target)) {
            throw new Exception("Could not move the bundle to '{$this->target}'.");
        }
    }

    /** Remove the partial bundle (after a failure). */
    public function discard(): void
    {
        if (is_dir($this->partial)) {
            Path::removeFolder($this->partial);
        }
    }
}
