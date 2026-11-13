<?php

namespace OSC\Helper;

use DirectoryIterator;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class Path {

    static function findSubpath(string $baseFolder, string $subpath): ?string
    {
        if (!is_dir($baseFolder)) {
            throw new InvalidArgumentException("The base folder '{$baseFolder}' is not a valid directory.");
        }

        // check if the subpath exists directly in the base folder
        $potentialPath = realpath($baseFolder) . DIRECTORY_SEPARATOR . $subpath;
        if (file_exists($potentialPath)) {
            return realpath($baseFolder);
        }

        // iterate through the directory structure recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseFolder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                $potentialPath = $file->getPathname() . DIRECTORY_SEPARATOR . $subpath;
                if (file_exists($potentialPath)) {
                    return realpath($file->getPathname());
                }
            }
        }

        return null;
    }

    /**
     * Whether the path is a directory with no entries (ignoring . and ..). A non-existent path
     * counts as empty.
     */
    public static function isEmptyDir(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Run a filesystem call and turn its failure into one exception that keeps PHP's reason.
     *
     * The call runs under a temporary error handler that collects the PHP warning instead of letting
     * it print. This works whatever error_reporting/display_errors are, and when another handler (a
     * module's) would swallow the warning so that error_get_last() stays empty. The previous handler
     * is always restored.
     *
     * @param callable $operation The call; a result === false is a failure
     * @param string   $failure   What failed, e.g. "Could not write 'x'" (a trailing '.' is dropped)
     *
     * @return mixed The operation's result
     *
     * @throws RuntimeException "<failure>: <reason>." or "<failure>." when PHP gave no reason
     */
    public static function checked(callable $operation, string $failure): mixed
    {
        $error = null;
        set_error_handler(function (int $no, string $message) use (&$error): bool {
            // the first message is the root cause (a recursive mkdir can emit more than one)
            $error ??= $message;
            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            // trimmed: a parse_ini_file() syntax error ends in a newline
            $reason = $error === null ? '' : trim(preg_replace(['/^\w+\(.*?\): /s', '/^Failed to open stream: /i'], '', $error));
            throw new RuntimeException(rtrim($failure, '.') . ($reason !== '' ? ": {$reason}" : '') . '.');
        }
        return $result;
    }

    /** @throws RuntimeException "Could not write '<path>': <reason>." (or $failure) */
    public static function writeFile(string $path, string $contents, ?string $failure = null): void
    {
        self::checked(fn() => file_put_contents($path, $contents), $failure ?? "Could not write '{$path}'");
    }

    /** @throws RuntimeException "Could not read '<path>': <reason>." (or $failure) */
    public static function readFile(string $path, ?string $failure = null): string
    {
        return self::checked(fn() => file_get_contents($path), $failure ?? "Could not read '{$path}'");
    }

    /**
     * Read and parse an ini file. A file that cannot be read fails as in readFile(); a syntax error
     * keeps PHP's message, without the path it repeats ("syntax error, unexpected ... on line 3").
     * An empty file is [].
     *
     * @param string|null $failure Replaces both default failures below
     *
     * @throws RuntimeException "Could not read '<path>': <reason>." or "Could not parse '<path>': <reason>." (or $failure)
     */
    public static function readIniFile(string $path, bool $sections = false, ?string $failure = null): array
    {
        self::readFile($path, $failure);
        try {
            return self::checked(fn() => parse_ini_file($path, $sections), $failure ?? "Could not parse '{$path}'");
        } catch (RuntimeException $e) {
            throw new RuntimeException(str_replace(" in {$path} on line ", ' on line ', $e->getMessage()), 0, $e);
        }
    }

    /**
     * Create a directory and its parents (mkdir -p). An existing directory is fine.
     *
     * @throws RuntimeException "Could not create directory '<dir>': <reason>." (or $failure)
     */
    public static function ensureDir(string $dir, int $mode = 0777, ?string $failure = null): void
    {
        // is_dir() after a failed mkdir() also accepts a directory another process created meanwhile;
        // a file at $dir still fails, with the reason "File exists"
        self::checked(fn() => mkdir($dir, $mode, true) || is_dir($dir), $failure ?? "Could not create directory '{$dir}'");
    }

    /**
     * Move a file. rename() first (PHP's rename() already copies across filesystems itself). When
     * rename() is refused, the file is copied instead: overwriting an existing file only needs that
     * file to be writable, where rename() needs its directory to be (self-update replacing a PHAR in a
     * directory the user can not write to). The copy gets the source's mode, as rename() would keep
     * it. A source that can not be removed afterwards is left behind: the move itself succeeded.
     *
     * @throws RuntimeException "Could not move '<from>' to '<to>': <reason>." (or $failure), with the copy's reason
     */
    public static function move(string $from, string $to, ?string $failure = null): void
    {
        $failure ??= "Could not move '{$from}' to '{$to}'";
        try {
            self::checked(fn() => rename($from, $to), $failure);
        } catch (RuntimeException) {
            self::checked(fn() => copy($from, $to), $failure);
            $mode = @fileperms($from);
            if ($mode !== false) {
                @chmod($to, $mode & 0777);
            }
            @unlink($from);
        }
    }

    /** @throws RuntimeException "Could not copy '<from>' to '<to>': <reason>." (or $failure) */
    public static function copyFile(string $from, string $to, ?string $failure = null): void
    {
        self::checked(fn() => copy($from, $to), $failure ?? "Could not copy '{$from}' to '{$to}'");
    }

    static function createTempFolder(string $prefix = ''): string
    {
        $randomPart = bin2hex(random_bytes(4)); // Generates 8 random characters
        $folderName = $prefix . $randomPart;
        $tempFolderPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $folderName;

        self::ensureDir($tempFolderPath, 0700, "Failed to create temporary folder '{$tempFolderPath}'");

        return $tempFolderPath;
    }

    static function createTempFile(string $prefix = ''): string
    {
        return self::checked(
            fn() => tempnam(sys_get_temp_dir(), $prefix),
            'Could not create a temporary file in ' . sys_get_temp_dir()
        );
    }

    static function moveFolder(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new InvalidArgumentException("Source folder '{$source}' does not exist or is not a directory.");
        }

        self::ensureDir($destination, 0755, "Failed to create destination folder '{$destination}'");

        $directoryIterator = new DirectoryIterator($source);
        foreach ($directoryIterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $fileInfo->getBasename();

            if ($fileInfo->isDir() && !$fileInfo->isLink()) {
                static::moveFolder($sourcePath, $destinationPath);
            } else {
                self::move($sourcePath, $destinationPath, "Failed to move file '{$sourcePath}' to '{$destinationPath}'");
            }
        }

        // Remove the source folder after moving its contents
        self::checked(fn() => rmdir($source), "Failed to remove source folder '{$source}'");
    }

    public static function removeFolder(string $path): void
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Folder '{$path}' does not exist or is not a directory.");
        }
        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }
            if ($fileinfo->isDir() && !$fileinfo->isLink()) {
                static::removeFolder($fileinfo->getPathname());
            } else {
                $file = $fileinfo->getPathname();
                self::checked(fn() => unlink($file), "Failed to delete '{$file}'");
            }
        }
        self::checked(fn() => rmdir($path), "Failed to remove folder '{$path}'");
    }

    public static function copyFolder(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new InvalidArgumentException("Source folder '{$source}' does not exist or is not a directory.");
        }

        self::ensureDir($destination, 0755, "Failed to create destination folder '{$destination}'");

        $directoryIterator = new DirectoryIterator($source);
        foreach ($directoryIterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $fileInfo->getBasename();

            if ($fileInfo->isDir() && !$fileInfo->isLink()) {
                static::copyFolder($sourcePath, $destinationPath);
            } else {
                self::copyFile($sourcePath, $destinationPath, "Failed to copy file '{$sourcePath}' to '{$destinationPath}'");
            }
        }
    }

    public static function toAbsolutePath(string $path, string $cwd): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }
        return $cwd . DIRECTORY_SEPARATOR . $path;
    }

    public static function createPath(array $parts): string
    {
        return implode(DIRECTORY_SEPARATOR,  $parts);
    }

    public static function createSafeName(string $name): string
    {
        // Replace invalid characters with underscores
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        // Trim and ensure the name is not empty
        return $safeName ?: 'default_name';
    }

    /** Whether $name is a single, plain folder name: letters, digits, `_`, `-` and `.` (not `.` or `..`). */
    public static function isSafeFolderName(string $name): bool
    {
        return $name !== '.' && $name !== '..' && preg_match('/^[A-Za-z0-9_.-]+$/', $name) === 1;
    }

}