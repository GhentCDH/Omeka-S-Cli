<?php

namespace OSC\Commands\Dummy\ValueGenerator;

use OSC\Commands\Dummy\Helper\LoremIpsumGenerator;

class LiteralValueGenerator implements ValueGeneratorInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generate(): array
    {
        $mode = $this->config['mode'];

        $value = match ($mode) {
            'fixed' => (string) $this->config['value'],
            'lorem' => LoremIpsumGenerator::generate(
                (int) ($this->config['minWords'] ?? 5),
                (int) ($this->config['maxWords'] ?? 10)
            ),
            'list'  => (string) $this->config['values'][array_rand($this->config['values'])],
            'range' => (string) random_int((int) $this->config['min'], (int) $this->config['max']),
            'date'  => $this->generateDate(),
            default => throw new \InvalidArgumentException("Unknown literal mode: '{$mode}'"),
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
