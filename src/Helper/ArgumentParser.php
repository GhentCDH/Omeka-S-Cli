<?php

namespace OSC\Helper;

use OSC\Helper\Types\ArgumentType;

class ArgumentParser {
    public static function getArgumentType($string): ArgumentType {
        if (preg_match('/^https?:\/\/.+\.zip$/', $string)) {
            return ArgumentType::ZipUrl;
        }

        if (preg_match('/^(https:\/\/|git@).+\.git(#[a-zA-Z0-9_.-]+)?$/', $string)) {
            return ArgumentType::GitRepo;
        }

        if (preg_match('/^[a-zA-Z0-9_-]+(:[a-zA-Z0-9_.-]+)?$/', $string)) {
            return ArgumentType::IdVersion;
        }

        throw new \InvalidArgumentException("Could not determine argument type: '$string'");
    }
}