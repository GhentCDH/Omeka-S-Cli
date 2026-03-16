<?php

namespace OSC\Commands\Dummy\ValueGenerator;

use OSC\Commands\Dummy\Helper\LoremIpsumGenerator;

class UriValueGenerator implements ValueGeneratorInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function generate(): array
    {
        $mode = $this->config['mode'];

        if ($mode === 'list') {
            $item = $this->config['values'][array_rand($this->config['values'])];
            return [
                'type'        => 'uri',
                'property_id' => 'auto',
                'is_public'   => true,
                '@id'         => $item['id'],
                'o:label'     => $item['label'] ?? null,
            ];
        }

        // random
        $label = LoremIpsumGenerator::generate(1, 3);
        $slug  = strtolower(str_replace(' ', '-', $label));

        return [
            'type'        => 'uri',
            'property_id' => 'auto',
            'is_public'   => true,
            '@id'         => "https://example.com/{$slug}",
            'o:label'     => $label,
        ];
    }
}
