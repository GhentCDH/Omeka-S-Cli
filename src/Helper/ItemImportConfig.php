<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * What to import as one item or item set: a JSON-LD source holding one resource, an informational
 * title (for messages only, never applied to the resource) and the media to attach. The shape of an
 * `x-omeka-s-cli.items` blueprint entry and of `item:import --config`.
 *
 * No IO: relative sources are resolved by the caller (see withSources()).
 */
class ItemImportConfig
{
    /** @param MediaConfig[] $media */
    private function __construct(
        private string $source,
        private ?string $title,
        private array $media,
    ) {
    }

    /** @throws InvalidArgumentException */
    public static function fromArray(array $data): ItemImportConfig
    {
        if (!isset($data['source']) || !is_string($data['source']) || trim($data['source']) === '') {
            throw new InvalidArgumentException("An import entry needs a non-empty 'source'.");
        }
        if (isset($data['title']) && !is_string($data['title'])) {
            throw new InvalidArgumentException("An import entry 'title' must be a string.");
        }
        $media = $data['media'] ?? [];
        if (!is_array($media) || !array_is_list($media)) {
            throw new InvalidArgumentException("An import entry 'media' must be a list.");
        }
        $configs = [];
        foreach ($media as $i => $entry) {
            try {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('A media must be an object.');
                }
                $configs[] = MediaConfig::fromArray($entry);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("media[{$i}]: " . $e->getMessage(), 0, $e);
            }
        }
        return new self($data['source'], $data['title'] ?? null, $configs);
    }

    public function source(): string
    {
        return $this->source;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return MediaConfig[] */
    public function media(): array
    {
        return $this->media;
    }

    /**
     * A copy with $resolve applied to the source and to the source of every file media; link media
     * (a URL the ingester keeps) are left as they are.
     *
     * @param callable(string): string $resolve
     */
    public function withSources(callable $resolve): ItemImportConfig
    {
        return new self(
            $resolve($this->source),
            $this->title,
            array_map(fn(MediaConfig $m) => $m->isFile() ? $m->withSource($resolve($m->source())) : $m, $this->media)
        );
    }

    public function toArray(): array
    {
        $data = ['source' => $this->source];
        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->media) {
            $data['media'] = array_map(fn(MediaConfig $m) => $m->toArray(), $this->media);
        }
        return $data;
    }
}
