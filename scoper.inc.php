<?php

declare(strict_types=1);

return [
    'prefix' => '_OmekaSCli',

    // Namespaces that come from Omeka S at runtime — must NOT be prefixed.
    // Our own code (OSC\) also stays unprefixed.
    'exclude-namespaces' => [
        'OSC',
        'Omeka',
        'Laminas',
        'CustomVocab',
        'Common'
    ],

    'expose-global-constants' => true,
    'expose-global-classes'   => true,
    'expose-global-functions' => true,

    // FakerPHP builds provider class names via string concatenation in Factory::findProviderClassname().
    // PhpScoper can't rewrite those string literals automatically, so we patch them manually.
    'patchers' => [
        static function (string $filePath, string $prefix, string $content): string {
            if (!str_contains($filePath, 'fakerphp/faker/src/Faker/Factory.php')) {
                return $content;
            }

            return str_replace(
                "'Faker\\\\'",
                "'{$prefix}\\\\Faker\\\\'",
                $content
            );
        },
    ],
];
