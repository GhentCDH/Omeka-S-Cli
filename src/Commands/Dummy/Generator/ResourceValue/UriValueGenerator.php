<?php

namespace OSC\Commands\Dummy\Generator\ResourceValue;

class UriValueGenerator implements ResourceValueGeneratorInterface
{
    public const ID = 'uri';

    private ?\Faker\Generator $faker = null;

    public function __construct(private array $config)
    {
        if (isset($config['values']) && (empty($config['values']) || !is_array($config['values']))) {
            throw new \InvalidArgumentException(
                "Uri generator 'values' must be a non-empty array."
            );
        }
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function generate(): array
    {
        if (isset($this->config['values'])) {
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
        $label = implode(' ', $this->getFaker()->words(random_int(1, 3)));
        $slug  = strtolower(str_replace(' ', '-', $label));

        return [
            'type'        => 'uri',
            'property_id' => 'auto',
            'is_public'   => true,
            '@id'         => "https://example.com/{$slug}",
            'o:label'     => $label,
        ];
    }

    private function getFaker(): \Faker\Generator
    {
        if ($this->faker === null) {
            $this->faker = \Faker\Factory::create();
        }
        return $this->faker;
    }
}
