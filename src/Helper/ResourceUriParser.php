<?php

namespace OSC\Helper;

use OSC\Helper\Types\ResourceUri;
use OSC\Helper\Types\ResourceUriType;

class ResourceUriParser {
    public static function parse($string): ResourceUri
    {
        // zip release url
        if (preg_match('/^https?:\/\/.+\.zip$/', $string, $matches)) {
            return new ResourceUri(ResourceUriType::ZipUrl, $matches[0], null);
        }

        // git repo url
        if (preg_match('/^((https:\/\/|git@).+\.git)(#([a-zA-Z0-9_.-]+))?$/', $string, $matches)) {
            return new ResourceUri(ResourceUriType::GitRepo, $matches[1], $matches[4] ?? null);
        }

        // id:version
        if (preg_match('/^([a-zA-Z0-9_-]+)(:([a-zA-Z0-9_.-]+))?$/', $string, $matches)) {
            return new ResourceUri(ResourceUriType::IdVersion, $matches[1], $matches[3] ?? null);
        }

        // gh:user/repo or gh:user/repo#branch
        if (preg_match('/^gh:(([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+))(#([a-zA-Z0-9_.-]+))?$/', $string, $matches)) {
            return new ResourceUri(ResourceUriType::GitHubRepo, $matches[1], $matches[5] ?? null);
        }

        throw new \InvalidArgumentException("Could not determine argument type: '$string'");
    }
}