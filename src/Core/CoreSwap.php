<?php
namespace OSC\Core;

use Closure;
use OSC\Helper\Path;
use RuntimeException;
use Throwable;

/**
 * Replace the core files of an Omeka S installation with a new release, so that a failure leaves the
 * previous core in place.
 *
 * 1. stage(): the release is copied into a staging directory on the same filesystem as the Omeka S
 *    root. A failure here changes nothing in the installation.
 * 2. swap(): each top-level entry of the release replaces the live one with two renames (live -> old/,
 *    staged -> live). A rename only needs write access to the directory, so a file the CLI user can
 *    not write (a root-owned .htaccess) does not stop it. Each rename is journaled; on a failure the
 *    journal is undone in reverse.
 * 3. cleanup(): the staging directory, with the replaced files, is removed, unless a rollback left
 *    files in it.
 *
 * The staging directory must not be reachable by the web server. Its protection does not depend on
 * which user the web server runs as:
 * - an unguessable name (128 random bits): nobody can request a URL they cannot guess;
 * - a .htaccess that denies every request and turns off directory listings (Apache);
 * - mode 0700, against other users;
 * - a short life: it exists only while the update runs.
 * It is created in a directory the caller names (outside every web root, ideally), else next to the
 * Omeka S root when that directory is writable and on the same filesystem, else inside the root.
 * Limit: under Apache, a .htaccess with its own Require rules inside the stage (a customised one in
 * old/) overrides the deny rule for its subtree; the name and the mode still protect it.
 *
 * No Omeka S bootstrap: this only touches the filesystem.
 */
final class CoreSwap
{
    /** Entries of the Omeka S root that hold the installation's own data: never replaced. */
    public const KEEP = ['config', 'files', 'modules', 'themes', 'logs'];

    private const PREFIX = '.omeka-s-cli-update-';

    private string $root;

    /** @var Closure(string, string): void */
    private Closure $rename;

    private ?string $stage = null;

    private bool $insideRoot = false;

    /** @var array<int, array{0: string, 1: string}> the renames done by swap(), as [from, to] */
    private array $journal = [];

    /** A rollback left files in the stage: it must not be removed. */
    private bool $keepStage = false;

    private bool $cleaned = false;

    private bool $shutdownRegistered = false;

    /**
     * @param string                              $omekaPath The Omeka S root
     * @param Closure(string, string): void|null $rename    Renames a path, throws on failure (tests inject a failing one)
     */
    public function __construct(string $omekaPath, ?Closure $rename = null)
    {
        $this->root = rtrim(realpath($omekaPath) ?: $omekaPath, '/');
        $this->rename = $rename ?? function (string $from, string $to): void {
            Path::checked(fn() => rename($from, $to), "Could not move '{$from}' to '{$to}'");
        };
    }

    /**
     * Refuse a directory to stage in that does not exist, is not writable, or is on another
     * filesystem than the Omeka S root (the staged files are moved into place with renames).
     *
     * @throws RuntimeException Naming the directory and the reason
     */
    public static function assertStageDir(string $dir, string $omekaPath): void
    {
        $refuse = fn(string $why) => new RuntimeException("Cannot stage the new core files in '{$dir}': {$why}.");
        if (!is_dir($dir)) {
            throw $refuse('it is not a directory');
        }
        if (!is_writable($dir)) {
            throw $refuse('it is not writable');
        }
        $root = realpath($omekaPath) ?: $omekaPath;
        if (!self::sameDevice($dir, $root)) {
            throw $refuse("it is not on the same filesystem as '{$root}' (the files are moved into place with renames)");
        }
    }

    /**
     * Copy every top-level entry of $releasePath except KEEP into a new staging directory, created in
     * $stageDir when given (see assertStageDir()), else next to the Omeka S root when possible, else
     * inside it.
     *
     * @throws RuntimeException When the stage cannot be created or filled; the stage is then removed
     */
    public function stage(string $releasePath, ?string $stageDir = null): void
    {
        if ($stageDir !== null) {
            self::assertStageDir($stageDir, $this->root);
            $parent = rtrim(realpath($stageDir) ?: $stageDir, '/');
            $this->insideRoot = $parent === $this->root || str_starts_with($parent, $this->root . '/');
        } else {
            $parent = dirname($this->root);
            $this->insideRoot = !(is_writable($parent) && self::sameDevice($parent, $this->root));
            if ($this->insideRoot) {
                $parent = $this->root;
            }
        }
        $stage = $parent . '/' . self::PREFIX . bin2hex(random_bytes(16));

        // not recursive: the directory must be new
        Path::checked(fn() => mkdir($stage, 0700), "Could not create the staging directory '{$stage}'");
        $this->stage = $stage;
        $this->cleaned = false;
        if (!$this->shutdownRegistered) {
            // a fatal error after this point must not leave the stage behind
            register_shutdown_function(function (): void {
                $this->cleanup();
            });
            $this->shutdownRegistered = true;
        }

        try {
            // first, before anything is copied in; also next to the root, which can be in the web root
            Path::writeFile("{$stage}/.htaccess", "Options -Indexes\nRequire all denied\n");
            Path::checked(fn() => mkdir("{$stage}/new", 0700), "Could not create '{$stage}/new'");
            Path::checked(fn() => mkdir("{$stage}/old", 0700), "Could not create '{$stage}/old'");

            foreach (self::entries($releasePath) as $entry) {
                if (in_array($entry, self::KEEP, true)) {
                    continue;
                }
                $source = "{$releasePath}/{$entry}";
                if (is_dir($source) && !is_link($source)) {
                    Path::copyFolder($source, "{$stage}/new/{$entry}");
                } else {
                    Path::copyFile($source, "{$stage}/new/{$entry}");
                }
            }
        } catch (Throwable $e) {
            $this->stage = null;
            try {
                Path::removeFolder($stage);
            } catch (Throwable) {
                throw new RuntimeException(
                    rtrim($e->getMessage(), '.') . ". The staging directory '{$stage}' could not be removed; delete it by hand.",
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /** The staging directory. */
    public function stagePath(): string
    {
        if ($this->stage === null) {
            throw new RuntimeException('Nothing was staged.');
        }
        return $this->stage;
    }

    /** Whether the stage is inside the Omeka S root. */
    public function isStagedInsideRoot(): bool
    {
        return $this->insideRoot;
    }

    /**
     * Swap the staged entries into the Omeka S root. On a failure the swap is rolled back.
     *
     * @throws CoreSwapException When an entry could not be replaced; restored() tells whether the rollback was complete
     */
    public function swap(): void
    {
        $stage = $this->stagePath();
        $this->journal = [];
        try {
            foreach (self::entries("{$stage}/new") as $entry) {
                $live = "{$this->root}/{$entry}";
                if (file_exists($live) || is_link($live)) {
                    $this->step($live, "{$stage}/old/{$entry}");
                }
                $this->step("{$stage}/new/{$entry}", $live);
            }
        } catch (Throwable $e) {
            $reason = rtrim($e->getMessage(), '.') . '.';
            $failed = $this->rollback();
            if ($failed === []) {
                throw new CoreSwapException("Could not replace the core files: {$reason} The previous core was restored.", true, $e);
            }
            $this->keepStage = true;
            $list = implode(', ', array_map(fn($entry, $why) => "{$entry} ({$why})", array_keys($failed), $failed));
            throw new CoreSwapException(
                "Could not replace the core files: {$reason} Could not restore: {$list}. The previous files are in '{$stage}/old'.",
                false,
                $e
            );
        }
        $this->journal = [];
    }

    /**
     * After swap(): when the release replaced a .htaccess that differed from it, save the previous one
     * next to it as .htaccess.bak-<date-time> (Apache denies .ht* files by default).
     *
     * @return string|null The saved copy, or null when the .htaccess was not changed
     */
    public function saveChangedHtaccess(): ?string
    {
        $old = $this->stagePath() . '/old/.htaccess';
        $live = "{$this->root}/.htaccess";
        if (!is_file($old) || !is_file($live)) {
            return null;
        }
        try {
            $hash = fn(string $file) => Path::checked(fn() => md5_file($file), "Could not read '{$file}'");
            if ($hash($old) === $hash($live)) {
                return null;
            }
            $saved = "{$live}.bak-" . date('Ymd-His');
            Path::copyFile($old, $saved, "Could not save it as '{$saved}'");
            return $saved;
        } catch (Throwable $e) {
            throw $this->keepPreviousFiles('Could not save the previous .htaccess', $e);
        }
    }

    /** After swap(): move the replaced files to $backupDir instead of deleting them with the stage. */
    public function keepBackup(string $backupDir): void
    {
        try {
            Path::moveFolder($this->stagePath() . '/old', $backupDir);
        } catch (Throwable $e) {
            throw $this->keepPreviousFiles("Could not move the previous core to '{$backupDir}'", $e);
        }
    }

    /**
     * A step after a successful swap failed: the stage keeps the previous files (the only copy of a
     * customised .htaccess, or what --backup was to keep) instead of being removed.
     */
    private function keepPreviousFiles(string $what, Throwable $e): RuntimeException
    {
        $this->keepStage = true;
        $reason = rtrim($e->getMessage(), '.');
        return new RuntimeException("{$what}: {$reason}. The previous files are kept in '{$this->stage}/old'.", 0, $e);
    }

    /**
     * Remove the staging directory, unless a rollback left files in it. Safe to call more than once.
     *
     * @return string|null A warning for the user (the stage was kept or could not be removed), or null
     */
    public function cleanup(): ?string
    {
        if ($this->cleaned || $this->stage === null) {
            return null;
        }
        if ($this->journal !== []) {
            // called while swap() runs (the shutdown function of a dying process): old/ holds the only
            // copy of the entries already replaced
            return "The update was interrupted: the staging directory '{$this->stage}' holds files of the previous core.";
        }
        $this->cleaned = true;
        if ($this->keepStage) {
            return "The staging directory '{$this->stage}' was kept: it holds files of the previous core.";
        }
        if (!is_dir($this->stage)) {
            return null;
        }
        try {
            // the deny rule goes last: a stage that can not be removed completely keeps it
            foreach (['new', 'old'] as $dir) {
                if (is_dir("{$this->stage}/{$dir}")) {
                    Path::removeFolder("{$this->stage}/{$dir}");
                }
            }
            $stage = $this->stage;
            if (is_file("{$stage}/.htaccess")) {
                Path::checked(fn() => unlink("{$stage}/.htaccess"), "Failed to delete '{$stage}/.htaccess'");
            }
            Path::checked(fn() => rmdir($stage), "Failed to remove folder '{$stage}'");
        } catch (Throwable $e) {
            return "Could not remove the staging directory '{$this->stage}': {$e->getMessage()}";
        }
        return null;
    }

    private function step(string $from, string $to): void
    {
        ($this->rename)($from, $to);
        $this->journal[] = [$from, $to];
    }

    /**
     * Undo the journal in reverse, continuing past errors.
     *
     * @return array<string, string> entry => reason, for the entries that could not be restored
     */
    private function rollback(): array
    {
        $failed = [];
        foreach (array_reverse($this->journal) as [$from, $to]) {
            $entry = basename($from);
            try {
                ($this->rename)($to, $from);
                if (str_starts_with($to, "{$this->stage}/old/")) {
                    // the previous version is back in place (for a file, over a new one that could not be
                    // moved out of the way), so the entry is restored
                    unset($failed[$entry]);
                }
            } catch (Throwable $e) {
                $failed[$entry] ??= rtrim($e->getMessage(), '.');
            }
        }
        $this->journal = [];
        return $failed;
    }

    /** @return string[] The entries of $dir, sorted, without . and .. */
    private static function entries(string $dir): array
    {
        $entries = array_values(array_diff(
            Path::checked(fn() => scandir($dir), "Could not read the directory '{$dir}'"),
            ['.', '..']
        ));
        sort($entries, SORT_STRING);
        return $entries;
    }

    private static function sameDevice(string $a, string $b): bool
    {
        $statA = @stat($a);
        $statB = @stat($b);
        return $statA !== false && $statB !== false && $statA['dev'] === $statB['dev'];
    }
}
