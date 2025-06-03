<?php

namespace OSC\Helper;

use DirectoryIterator;
use Exception;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RuntimeException;

class FileUtils {

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

        // iterate through the directory structure
        $iterator = new RecursiveDirectoryIterator($baseFolder, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $potentialPath = $file->getPathname() . DIRECTORY_SEPARATOR . $subpath;
                if (file_exists($potentialPath)) {
                    return realpath($file->getPathname());
                }
            }
        }

        return null;
    }

    static function createTempFolder(string $prefix = ''): string
    {
        $randomPart = bin2hex(random_bytes(4)); // Generates 8 random characters
        $folderName = $prefix . $randomPart;
        $tempFolderPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $folderName;

        if (!mkdir($tempFolderPath, 0700, true) && !is_dir($tempFolderPath)) {
            throw new Exception("Failed to create temporary folder: {$tempFolderPath}");
        }

        return $tempFolderPath;
    }

    static function createTempFile(string $prefix = ''): string
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), $prefix);

        if (!is_file($tempFilePath)) {
            throw new Exception("Failed to create temporary file: {$tempFilePath}");
        }

        return $tempFilePath;
    }

    static function moveFolder(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new InvalidArgumentException("Source folder '{$source}' does not exist or is not a directory.");
        }

        if (!is_dir($destination)) {
            if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new RuntimeException("Failed to create destination folder '{$destination}'.");
            }
        }

        $directoryIterator = new DirectoryIterator($source);
        foreach ($directoryIterator as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $fileInfo->getBasename();

            if ($fileInfo->isDir()) {
                static::moveFolder($sourcePath, $destinationPath);
            } else {
                if (!rename($sourcePath, $destinationPath)) {
                    throw new RuntimeException("Failed to move file '{$sourcePath}' to '{$destinationPath}'.");
                }
            }
        }

        // Remove the source folder after moving its contents
        if (!rmdir($source)) {
            throw new RuntimeException("Failed to remove source folder '{$source}'.");
        }
    }

    public static function removeFolder(string $path): void
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Folder '{$path}' does not exist or is not a directory.");
        }
        $iterator = new DirectoryIterator($path);
        foreach ( $iterator as $fileinfo ) {
            if($fileinfo->isDot()) continue;
            if($fileinfo->isDir()){
                static::removeFolder($fileinfo->getPathname());
                @rmdir($fileinfo->getPathname());
            }
            if($fileinfo->isFile()){
                @unlink($fileinfo->getPathname());
            }
        }
        @rmdir($path);
    }

    public static function createPath(array $parts): string
    {
        return implode(DIRECTORY_SEPARATOR,  $parts);
    }
}