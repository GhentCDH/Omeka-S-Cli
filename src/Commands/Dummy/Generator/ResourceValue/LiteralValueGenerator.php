<?php

namespace OSC\Commands\Dummy\Generator\ResourceValue;

use OSC\Commands\Dummy\Generator\Helper\LoremIpsumGenerator;

class LiteralValueGenerator implements ResourceValueGeneratorInterface
{
    private const VALID_MODES = ['lorem', 'values', 'range', 'date'];

    public function __construct(private array $config)
    {
        $mode = $config['mode'] ?? 'lorem';
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException(
                "Unknown literal mode '{$mode}'. Supported modes: " . implode(', ', self::VALID_MODES) . '.'
            );
        }
        // mode=values requires non-empty 'values' array
        if ($mode === 'values' && (empty($config['values']) || !is_array($config['values']))) {
            throw new \InvalidArgumentException(
                "Literal mode 'values' requires a non-empty 'values' array."
            );
        }
        // mode=range requires 'min' and 'max' integers
        if ($mode === 'range') {
            if (!isset($config['min']) || !is_int($config['min'])) {
                throw new \InvalidArgumentException(
                    "Literal mode 'range' requires an integer 'min'."
                );
            }
            if (!isset($config['max']) || !is_int($config['max'])) {
                throw new \InvalidArgumentException(
                    "Literal mode 'range' requires an integer 'max'."
                );
            }
            if ($config['min'] > $config['max']) {
                throw new \InvalidArgumentException(
                    "Literal mode 'range' requires 'min' to be less than or equal to 'max'."
                );
            }
        }
        // mode=date requires 'min' and 'max' years (integers)
        if ($mode === 'date') {
            if (!isset($config['min']) || !is_int($config['min'])) {
                throw new \InvalidArgumentException(
                    "Literal mode 'date' requires an integer 'min' year."
                );
            }
            if (!isset($config['max']) || !is_int($config['max'])) {
                throw new \InvalidArgumentException(
                    "Literal mode 'date' requires an integer 'max' year."
                );
            }
            if ($config['min'] > $config['max']) {
                throw new \InvalidArgumentException(
                    "Literal mode 'date' requires 'min' year to be less than or equal to 'max' year."
                );
            }
        }

        $this->config = $config;
    }

    public function getId(): string
    {
        return "literal";
    }
    public function generate(): array
    {
        $mode = $this->config['mode'];

        $value = match ($mode) {
            'values' => (string) $this->config['values'][array_rand($this->config['values'])],
            'lorem'  => LoremIpsumGenerator::generate(
                (int) ($this->config['minWords'] ?? 5),
                (int) ($this->config['maxWords'] ?? 10)
            ),
            'range'  => (string) random_int((int) $this->config['min'], (int) $this->config['max']),
            'date'   => $this->generateDate(),
            default  => throw new \InvalidArgumentException("Unknown literal mode: '{$mode}'"),
        };

        return [
            'type'        => 'literal',
            'property_id' => 'auto',
            'is_public'   => true,
            '@value'      => $value,
        ];
    }

    private function generateDate(): string
    {
        $year   = random_int((int) ($this->config['min'] ?? 1900), (int) ($this->config['max'] ?? (int) date('Y')));
        $format = $this->config['format'] ?? 'Y';

        return match ($format) {
            'Y-m-d' => sprintf('%04d-%02d-%02d', $year, random_int(1, 12), random_int(1, 28)),
            'Y-m'   => sprintf('%04d-%02d', $year, random_int(1, 12)),
            default => (string) $year,
        };
    }
}
