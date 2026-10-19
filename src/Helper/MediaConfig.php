<?php
namespace OSC\Helper;

use InvalidArgumentException;
use OSC\Resource\PortableJsonLd;

/**
 * One media to attach to an item: the Omeka media ingester, what it ingests and anything else the
 * ingester or the media takes. The shape of a blueprint `media` entry, of `media:add --data` and of
 * `item:import --media`.
 *
 * Three kinds of keys:
 * - mapped: `ingester`, `source` (under the key the ingester reads), `title`, `altText`, `public`;
 * - property terms (`prefix:local`) in the simple notation: a string, a list of strings, or JSON-LD
 *   values;
 * - anything else (`start`, `store_original`, a module ingester's own key, an explicit `o:source`,
 *   ...) is passed to the ingester unchanged, since what an ingester needs can't be predicted. A raw
 *   key wins over a mapped one.
 *
 * No IO: a relative file source must already be resolved by the caller. From the CLI the `upload`
 * ingester takes a local path or URL (see \OSC\Resource\LocalFileIngester).
 */
class MediaConfig
{
    /** The key each core ingester reads its source from; any other ingester reads o:source. */
    private const SOURCE_KEYS = ['upload' => 'ingest_source', 'url' => 'ingest_url', 'html' => 'html'];

    /** @param array<string,mixed> $extra Property terms and raw ingester keys, as given */
    private function __construct(
        private string $ingester,
        private ?string $source,
        private ?string $title,
        private ?string $altText,
        private ?bool $public,
        private array $extra,
    ) {
    }

    /** @throws InvalidArgumentException */
    public static function fromArray(array $data): MediaConfig
    {
        if (!isset($data['ingester']) || !is_string($data['ingester']) || trim($data['ingester']) === '') {
            throw new InvalidArgumentException("A media needs a non-empty 'ingester'.");
        }
        $ingester = trim($data['ingester']);
        $source = $data['source'] ?? null;
        if ($source !== null && (!is_string($source) || trim($source) === '')) {
            throw new InvalidArgumentException("A media 'source' must be a non-empty string.");
        }
        if ($ingester === 'upload' && $source === null) {
            throw new InvalidArgumentException("An upload media needs a 'source'.");
        }
        foreach (['title', 'altText'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new InvalidArgumentException("A media '{$key}' must be a string.");
            }
        }
        if (isset($data['public']) && !is_bool($data['public'])) {
            throw new InvalidArgumentException("A media 'public' must be true or false.");
        }

        $extra = array_diff_key($data, array_flip(['ingester', 'source', 'title', 'altText', 'public']));
        foreach ($extra as $key => $value) {
            if (PortableJsonLd::splitTerm((string) $key) !== null && self::values($value) === null) {
                throw new InvalidArgumentException(
                    "A media '{$key}' must be a string, a list of strings or JSON-LD values."
                );
            }
        }
        return new self($ingester, $source, $data['title'] ?? null, $data['altText'] ?? null, $data['public'] ?? null, $extra);
    }

    /**
     * An `item:import --media` value as a media config array:
     * - `ingester=source[;key=value...]`, where `\;` is a literal `;` and `public` takes true/false;
     * - or a JSON object (a value starting with `{`).
     *
     * @throws InvalidArgumentException
     */
    public static function parseSpec(string $spec): array
    {
        if (str_starts_with(ltrim($spec), '{')) {
            $data = json_decode($spec, true);
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException("--media expects a JSON object, got \"{$spec}\".");
            }
            return $data;
        }

        $data = [];
        foreach (preg_split('/(?<!\\\\);/', $spec) as $i => $pair) {
            $pair = str_replace('\;', ';', $pair);
            if (!str_contains($pair, '=') || str_starts_with($pair, '=')) {
                throw new InvalidArgumentException("--media expects ingester=source[;key=value...], got \"{$spec}\".");
            }
            [$key, $value] = explode('=', $pair, 2);
            if ($i === 0) {
                $data['ingester'] = $key;
                if ($value !== '') {
                    $data['source'] = $value;
                }
            } elseif ($key === 'public') {
                $data['public'] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    ?? throw new InvalidArgumentException("--media public= takes true or false, got \"{$value}\".");
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    public function ingester(): string
    {
        return $this->ingester;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function withSource(string $source): MediaConfig
    {
        return new self($this->ingester, $source, $this->title, $this->altText, $this->public, $this->extra);
    }

    /** Whether the source is a file to ingest (as opposed to a link the ingester keeps). */
    public function isFile(): bool
    {
        return $this->ingester === 'upload' && $this->source !== null;
    }

    /** The `o:media` entry (or standalone media payload) for the Omeka API. */
    public function toApiPayload(): array
    {
        $payload = ['o:ingester' => $this->ingester, 'o:is_public' => $this->public ?? true];
        if ($this->source !== null) {
            $payload[self::SOURCE_KEYS[$this->ingester] ?? 'o:source'] = $this->source;
        }
        if ($this->title !== null) {
            $payload['dcterms:title'] = self::values($this->title);
        }
        if ($this->altText !== null) {
            $payload['o:alt_text'] = $this->altText;
        }
        // raw keys last: they win over the mapped ones
        foreach ($this->extra as $key => $value) {
            $payload[$key] = PortableJsonLd::splitTerm((string) $key) !== null ? self::values($value) : $value;
        }
        return $payload;
    }

    public function toArray(): array
    {
        return array_filter(
            ['ingester' => $this->ingester, 'source' => $this->source, 'title' => $this->title, 'altText' => $this->altText, 'public' => $this->public],
            fn($value) => $value !== null
        ) + $this->extra;
    }

    /**
     * Property values in the simple notation as API values: a string is one literal, a list holds
     * strings and/or JSON-LD value objects. Null when the value has another shape.
     */
    private static function values(mixed $value): ?array
    {
        $list = is_array($value) && array_is_list($value) ? $value : [$value];
        if ($list === []) {
            return null;
        }
        $values = [];
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $values[] = ['type' => 'literal', 'property_id' => 'auto', '@value' => $entry];
            } elseif (is_array($entry) && PortableJsonLd::isValueList([$entry])) {
                $values[] = $entry + ['property_id' => 'auto'];
            } else {
                return null;
            }
        }
        return $values;
    }
}
