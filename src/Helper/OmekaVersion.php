<?php

namespace OSC\Helper;

class OmekaVersion
{
    /**
     * Reads the Omeka S version from application/Module.php without bootstrapping.
     * Returns null if the file is missing or the constant cannot be found.
     */
    public static function getVersion(string $omekaPath): ?string
    {
        $modulePath = FileUtils::createPath([$omekaPath, 'application', 'Module.php']);
        if (!is_file($modulePath)) {
            return null;
        }

        $content = file_get_contents($modulePath);
        if (preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", $content, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
