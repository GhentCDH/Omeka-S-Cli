<?php

namespace OSC\Helper;

class OmekaVersion
{
    /**
     * Reads the Omeka S version from application/Module.php without bootstrapping.
     * Returns null if the file is missing or the constant cannot be found.
     *
     * @throws \RuntimeException When the file exists but cannot be read
     */
    public static function getVersion(string $omekaPath): ?string
    {
        $modulePath = Path::createPath([$omekaPath, 'application', 'Module.php']);
        if (!is_file($modulePath)) {
            return null;
        }

        $content = Path::readFile($modulePath);
        if (preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
