<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * One media to attach to an item: the Omeka media ingester and its source, plus an optional title and
 * alt text. The shape of a blueprint `media` entry and of `item:import --media`.
 *
 * No IO: a relative file source must already be resolved by the caller. From the CLI the `upload`
 * ingester takes a local path or URL (see \OSC\Resource\LocalFileIngester).
 */
class MediaConfig
{
    private function __construct(
        private string $ingester,
        private string $source,
        private ?string $title,
        private ?string $altText,
    ) {
    }

    /** @throws InvalidArgumentException */
    public static function fromArray(array $data): MediaConfig
    {
        foreach (['ingester', 'source'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
                throw new InvalidArgumentException("A media needs a non-empty '{$key}'.");
            }
        }
        foreach (['title', 'altText'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new InvalidArgumentException("A media '{$key}' must be a string.");
            }
        }
        return new self(trim($data['ingester']), $data['source'], $data['title'] ?? null, $data['altText'] ?? null);
    }

    public function ingester(): string
    {
        return $this->ingester;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function withSource(string $source): MediaConfig
    {
        return new self($this->ingester, $source, $this->title, $this->altText);
    }

    /** Whether the source is a file to ingest (as opposed to a link the ingester keeps). */
    public function isFile(): bool
    {
        return $this->ingester === 'upload';
    }

    /** The `o:media` entry (or standalone media payload) for the Omeka API. */
    public function toApiPayload(): array
    {
        $key = match ($this->ingester) {
            'upload' => 'ingest_source',
            'url' => 'ingest_url',
            'html' => 'html',
            default => 'o:source',
        };
        $payload = ['o:ingester' => $this->ingester, 'o:is_public' => true, $key => $this->source];
        if ($this->title !== null) {
            $payload['dcterms:title'] = [['type' => 'literal', 'property_id' => 'auto', '@value' => $this->title]];
        }
        if ($this->altText !== null) {
            $payload['o:alt_text'] = $this->altText;
        }
        return $payload;
    }

    public function toArray(): array
    {
        return array_filter(
            ['ingester' => $this->ingester, 'source' => $this->source, 'title' => $this->title, 'altText' => $this->altText],
            fn($value) => $value !== null
        );
    }
}
