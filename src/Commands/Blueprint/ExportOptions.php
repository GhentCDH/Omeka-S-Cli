<?php
namespace OSC\Commands\Blueprint;

use InvalidArgumentException;

/**
 * What a blueprint export covers beyond its default parts (modules, themes, vocabularies, sites).
 *
 * A pure policy object for {@see BlueprintExporter}, built from blueprint:export's --include option.
 * Each optional part is a constant listed in OPTIONAL_PARTS; adding one is a new constant plus an
 * includes() check in the exporter.
 */
class ExportOptions
{
    /** The sites' user permissions, and a `users` entry for every user they name (it must be declared). */
    public const SITE_PERMISSIONS = 'site-permissions';

    /** The optional parts --include accepts. */
    public const OPTIONAL_PARTS = [self::SITE_PERMISSIONS];

    /**
     * @param string[] $included Optional parts to export
     */
    public function __construct(private array $included = [])
    {
    }

    /**
     * Build from the --include option: a comma-separated list of optional parts (case-insensitive).
     *
     * @throws InvalidArgumentException When a part is unknown
     */
    public static function fromInclude(?string $include): self
    {
        $parts = array_filter(array_map(
            static fn(string $part) => strtolower(trim($part)),
            explode(',', (string) $include)
        ), static fn(string $part) => $part !== '');

        foreach ($parts as $part) {
            if (!in_array($part, self::OPTIONAL_PARTS, true)) {
                $valid = implode(', ', self::OPTIONAL_PARTS);
                throw new InvalidArgumentException("Invalid --include '{$part}'. Use one or more of: {$valid}.");
            }
        }

        return new self(array_values(array_unique($parts)));
    }

    public function includes(string $part): bool
    {
        return in_array($part, $this->included, true);
    }

    /** @return string[] The optional parts to export */
    public function included(): array
    {
        return $this->included;
    }
}
