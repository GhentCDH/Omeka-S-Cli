<?php

namespace OSC\Commands\Dummy\Generator\ResourceValue;

class LiteralValueGenerator implements ResourceValueGeneratorInterface
{
    private const VALID_MODES = [
        // built-in
        'values', 'range', 'date',
        // text
        'words', 'sentences', 'paragraphs', 'text', 'realText',
        // person
        'title', 'name', 'firstName', 'lastName',
        // address
        'longitude', 'latitude', 'city', 'country', 'state', 'streetAddress', 'postcode', 'address',
        // date/time
        'time', 'year', 'century',
        // internet
        'email', 'url', 'slug',
        // misc
        'uuid', 'md5', 'languageCode',
        // version
        'semver',
    ];

    private const PERSON_MODES = ['title', 'name', 'firstName', 'lastName'];

    private ?\Faker\Generator $faker = null;

    public function __construct(private array $config)
    {
        $mode = $config['mode'] ?? 'words';
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
        // person modes: optional gender must be 'male' or 'female'
        if (in_array($mode, self::PERSON_MODES, true) && isset($config['gender'])) {
            if (!in_array($config['gender'], ['male', 'female'], true)) {
                throw new \InvalidArgumentException(
                    "Literal mode '{$mode}' gender must be 'male' or 'female'."
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
        $mode = $this->config['mode'] ?? 'words';

        $value = match ($mode) {
            // built-in
            'values' => (string) $this->config['values'][array_rand($this->config['values'])],
            'range'  => (string) random_int((int) $this->config['min'], (int) $this->config['max']),
            'date'   => $this->generateDate(),
            // text
            'words'     => implode(' ', $this->getFaker()->words(
                random_int((int) ($this->config['min'] ?? 3), (int) ($this->config['max'] ?? 5))
            )),
            'sentences' => implode(' ', $this->getFaker()->sentences(
                random_int((int) ($this->config['min'] ?? 2), (int) ($this->config['max'] ?? 4))
            )),
            'paragraphs' => implode("\n", $this->getFaker()->paragraphs(
                random_int((int) ($this->config['min'] ?? 1), (int) ($this->config['max'] ?? 3))
            )),
            'text'     => $this->getFaker()->text((int) ($this->config['maxNbChars'] ?? 200)),
            'realText' => $this->getFaker()->realText((int) ($this->config['maxNbChars'] ?? 200)),
            // person
            'title'     => $this->getFaker()->title($this->config['gender'] ?? null),
            'name'      => $this->getFaker()->name($this->config['gender'] ?? null),
            'firstName' => $this->getFaker()->firstName($this->config['gender'] ?? null),
            'lastName'  => $this->getFaker()->lastName($this->config['gender'] ?? null),
            // address
            'longitude'     => (string) $this->getFaker()->longitude(),
            'latitude'      => (string) $this->getFaker()->latitude(),
            'city'          => $this->getFaker()->city(),
            'country'       => $this->getFaker()->country(),
            'state'         => $this->getFaker()->state(),
            'streetAddress' => $this->getFaker()->streetAddress(),
            'postcode'      => $this->getFaker()->postcode(),
            'address'       => $this->getFaker()->address(),
            // date/time
            'time'    => $this->getFaker()->time($this->config['format'] ?? 'H:i:s'),
            'year'    => (string) random_int(
                (int) ($this->config['min'] ?? 1900),
                (int) ($this->config['max'] ?? (int) date('Y'))
            ),
            'century' => $this->getFaker()->century(),
            // internet
            'email' => $this->getFaker()->safeEmail(),
            'url'   => $this->getFaker()->url(),
            'slug'  => $this->getFaker()->slug(),
            // misc
            'uuid'         => $this->getFaker()->uuid(),
            'md5'          => $this->getFaker()->md5(),
            'languageCode' => $this->getFaker()->languageCode(),
            // version
            'semver' => $this->getFaker()->semver(),
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

    private function getFaker(): \Faker\Generator
    {
        if ($this->faker === null) {
            $this->faker = \Faker\Factory::create($this->config['locale'] ?? 'en_US');
        }
        return $this->faker;
    }
}
