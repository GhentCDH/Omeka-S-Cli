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
    ],

    'expose-global-constants' => true,
    'expose-global-classes'   => true,
    'expose-global-functions' => true,
];
