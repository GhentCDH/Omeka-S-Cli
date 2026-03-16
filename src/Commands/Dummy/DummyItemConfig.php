<?php

namespace OSC\Commands\Dummy;

class DummyItemConfig
{
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function default(): self
    {
        return new self([
            'properties' => [
                [
                    'property' => 'dcterms:title',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'lorem', 'minWords' => 2, 'maxWords' => 5],
                    ],
                ],
                [
                    'property' => 'dcterms:description',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'lorem', 'minWords' => 5, 'maxWords' => 15],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Config that exercises every value generator type and mode.
     * Useful for manual testing during development.
     */
    public static function allGenerators(): self
    {
        return new self([
            'properties' => [
                // literal:lorem
                [
                    'property' => 'dcterms:title',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'lorem', 'minWords' => 2, 'maxWords' => 5],
                    ],
                ],
                // literal:fixed
                [
                    'property' => 'dcterms:type',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'fixed', 'value' => 'Test Item'],
                    ],
                ],
                // literal:list
                [
                    'property' => 'dcterms:subject',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'list', 'values' => ['Art', 'Science', 'History', 'Technology']],
                    ],
                ],
                // literal:range
                [
                    'property' => 'dcterms:extent',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'range', 'min' => 1, 'max' => 500],
                    ],
                ],
                // literal:date — year only
                [
                    'property' => 'dcterms:created',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'date', 'min' => 1900, 'max' => 2024, 'format' => 'Y'],
                    ],
                ],
                // literal:date — year-month
                [
                    'property' => 'dcterms:modified',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'date', 'min' => 2000, 'max' => 2024, 'format' => 'Y-m'],
                    ],
                ],
                // literal:date — full date
                [
                    'property' => 'dcterms:date',
                    'values'   => [
                        ['type' => 'literal', 'mode' => 'date', 'min' => 2000, 'max' => 2024, 'format' => 'Y-m-d'],
                    ],
                ],
                // uri:random
                [
                    'property' => 'dcterms:relation',
                    'values'   => [
                        ['type' => 'uri', 'mode' => 'random'],
                    ],
                ],
                // uri:list
                [
                    'property' => 'dcterms:source',
                    'values'   => [
                        [
                            'type'   => 'uri',
                            'mode'   => 'list',
                            'values' => [
                                ['id' => 'https://example.com/source-1', 'label' => 'Source One'],
                                ['id' => 'https://example.com/source-2', 'label' => 'Source Two'],
                                ['id' => 'https://example.com/source-3', 'label' => 'Source Three'],
                            ],
                        ],
                    ],
                ],
                // resource:random (requires existing resources in the instance)
                [
                    'property' => 'dcterms:isPartOf',
                    'values'   => [
                        ['type' => 'resource', 'mode' => 'random', 'resourceType' => 'any'],
                    ],
                ],
            ],
        ]);
    }

    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            throw new \Exception("Config file not found or not readable: {$path}");
        }

        $content = file_get_contents($path);
        $config  = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in config file '{$path}': " . json_last_error_msg());
        }

        $instance = new self($config);
        $instance->validate();

        return $instance;
    }

    /**
     * Returns the class config: null/"random" for random, a term string, or an array of term strings.
     */
    public function getClassConfig(): string|array|null
    {
        return $this->config['class'] ?? null;
    }

    /**
     * Returns the array of property config entries.
     * Each entry: { property: string, values: array }
     */
    public function getPropertyConfigs(): array
    {
        return $this->config['properties'] ?? [];
    }

    /**
     * Returns all distinct resource types needed by resource value generators.
     * @return string[] e.g. ['items', 'item-sets', 'any']
     */
    public function getResourceTypesNeeded(): array
    {
        $types = [];
        foreach ($this->getPropertyConfigs() as $propConfig) {
            foreach ($propConfig['values'] ?? [] as $valueConfig) {
                if (($valueConfig['type'] ?? '') === 'resource') {
                    $type         = $valueConfig['resourceType'] ?? 'any';
                    $types[$type] = true;
                }
            }
        }
        return array_keys($types);
    }

    private function validate(): void
    {
        $validTypes          = ['literal', 'uri', 'resource'];
        $validLiteralModes   = ['lorem', 'fixed', 'list', 'range', 'date'];
        $validUriModes       = ['random', 'list'];
        $validResourceModes  = ['random'];

        foreach ($this->getPropertyConfigs() as $i => $propConfig) {
            if (empty($propConfig['property'])) {
                throw new \InvalidArgumentException(
                    "Property config at index {$i} is missing the 'property' field."
                );
            }

            $prop = $propConfig['property'];

            foreach ($propConfig['values'] ?? [] as $j => $valueConfig) {
                $type = $valueConfig['type'] ?? '';
                if (!in_array($type, $validTypes, true)) {
                    throw new \InvalidArgumentException(
                        "Unknown type '{$type}' in property '{$prop}' value[{$j}]. "
                        . "Supported types: " . implode(', ', $validTypes) . '.'
                    );
                }

                $mode = $valueConfig['mode'] ?? '';
                if ($type === 'literal' && !in_array($mode, $validLiteralModes, true)) {
                    throw new \InvalidArgumentException(
                        "Unknown literal mode '{$mode}' in property '{$prop}' value[{$j}]. "
                        . "Supported modes: " . implode(', ', $validLiteralModes) . '.'
                    );
                }
                if ($type === 'uri' && !in_array($mode, $validUriModes, true)) {
                    throw new \InvalidArgumentException(
                        "Unknown uri mode '{$mode}' in property '{$prop}' value[{$j}]. "
                        . "Supported modes: " . implode(', ', $validUriModes) . '.'
                    );
                }
                if ($type === 'resource' && !in_array($mode, $validResourceModes, true)) {
                    throw new \InvalidArgumentException(
                        "Unknown resource mode '{$mode}' in property '{$prop}' value[{$j}]. "
                        . "Supported modes: " . implode(', ', $validResourceModes) . '.'
                    );
                }
            }
        }
    }
}
