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
    /**
     * @param MediaConfig[]  $media
     * @param string[]|null  $sites Site slugs or titles replacing the source's o:site; [] = the default
     *                              site; null = keep the source's o:site
     */
    private function __construct(
        private string $source,
        private ?string $title,
        private array $media,
        private ?array $sites = null,
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
        $sites = $data['sites'] ?? null;
        if ($sites !== null && (!is_array($sites) || !array_is_list($sites)
            || array_filter($sites, fn($site) => !is_string($site) || trim($site) === ''))) {
            throw new InvalidArgumentException("An import entry 'sites' must be a list of site slugs or titles.");
        }
        return new self($data['source'], $data['title'] ?? null, $configs, $sites);
    }

    public function source(): string
    {
        return $this->source;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return string[]|null Site slugs or titles; [] = the default site; null = the source's o:site */
    public function sites(): ?array
    {
        return $this->sites;
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
            array_map(fn(MediaConfig $m) => $m->isFile() ? $m->withSource($resolve($m->source())) : $m, $this->media),
            $this->sites
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
        if ($this->sites !== null) {
            $data['sites'] = $this->sites;
        }
        return $data;
    }
}
