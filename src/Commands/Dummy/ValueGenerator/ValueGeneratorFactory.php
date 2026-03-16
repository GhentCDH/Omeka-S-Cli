<?php

namespace OSC\Commands\Dummy\ValueGenerator;

class ValueGeneratorFactory
{
    /**
     * @param array $config     The value config entry from the JSON config file
     * @param array $resourcePool Pre-fetched resource IDs (required for type=resource)
     */
    public static function create(array $config, array $resourcePool = []): ValueGeneratorInterface
    {
        $type = $config['type'] ?? '';

        return match ($type) {
            'literal'  => new LiteralValueGenerator($config),
            'uri'      => new UriValueGenerator($config),
            'resource' => new ResourceValueGenerator($resourcePool),
            default    => throw new \InvalidArgumentException(
                "Unknown value type: '{$type}'. Supported types: literal, uri, resource."
            ),
        };
    }
}
