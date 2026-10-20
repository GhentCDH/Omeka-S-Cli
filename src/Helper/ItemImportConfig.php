<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * What to import as one item or item set, in one of two forms:
 * - a JSON-LD `source` holding one resource, with an informational `title` (for messages only, never
 *   applied), optional `sites` (replacing the file's o:site) and `media`;
 * - no source: only a `title` (applied as dcterms:title), `itemSets` (titles), `sites` and `media`.
 *   Other properties belong in a JSON-LD source.
 *
 * The shape of an `x-omeka-s-cli.items` / `.itemSets` blueprint entry and of `item:import --config`.
 * No IO: relative sources are resolved by the caller (see withSources()).
 */
class ItemImportConfig
{
    private const WITH_SOURCE = ['source', 'title', 'sites', 'media'];
    private const WITHOUT_SOURCE = ['title', 'itemSets', 'sites', 'media'];

    /**
     * @param MediaConfig[]  $media
     * @param string[]       $itemSets Item set titles (only without a source)
     * @param string[]|null  $sites    Site slugs or titles replacing the source's o:site; [] = the default
     *                                 site; null = keep the source's o:site
     */
    private function __construct(
        private ?string $source,
        private ?string $title,
        private array $media,
        private ?array $sites = null,
        private array $itemSets = [],
    ) {
    }

    /** @throws InvalidArgumentException */
    public static function fromArray(array $data): ItemImportConfig
    {
        $hasSource = array_key_exists('source', $data);
        if ($hasSource && (!is_string($data['source']) || trim($data['source']) === '')) {
            throw new InvalidArgumentException("An import entry 'source' must be a non-empty string.");
        }
        if (isset($data['title']) && !is_string($data['title'])) {
            throw new InvalidArgumentException("An import entry 'title' must be a string.");
        }
        if (!$hasSource && (!isset($data['title']) || trim($data['title']) === '')) {
            throw new InvalidArgumentException("An import entry needs a 'source' (a JSON-LD file) or a 'title'.");
        }

        if ($hasSource) {
            if (array_key_exists('itemSets', $data)) {
                throw new InvalidArgumentException(
                    "An import entry with a 'source' takes no 'itemSets': the source file sets the item sets."
                );
            }
            $unknown = array_diff(array_keys($data), self::WITH_SOURCE);
            if ($unknown) {
                throw new InvalidArgumentException(
                    "An import entry with a 'source' takes only title, sites and media; not '" . implode("', '", $unknown) . "'."
                );
            }
        } else {
            $unknown = array_diff(array_keys($data), self::WITHOUT_SOURCE);
            if ($unknown) {
                throw new InvalidArgumentException(
                    'Without a source an entry takes only title, itemSets, sites and media; put other properties (\''
                    . implode("', '", $unknown) . "') in a JSON-LD source."
                );
            }
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
        if ($sites !== null && !self::isNameList($sites)) {
            throw new InvalidArgumentException("An import entry 'sites' must be a list of site slugs or titles.");
        }
        $itemSets = $data['itemSets'] ?? [];
        if (!self::isNameList($itemSets)) {
            throw new InvalidArgumentException("An import entry 'itemSets' must be a list of item set titles.");
        }

        return new self(
            $hasSource ? $data['source'] : null,
            $data['title'] ?? null,
            $configs,
            // without a source there is no o:site to keep: no sites means the default site
            $sites ?? ($hasSource ? null : []),
            $itemSets
        );
    }

    /** Whether the entry has a JSON-LD source (else it is a title-only entry). */
    public function hasSource(): bool
    {
        return $this->source !== null;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return string[] Item set titles (a title-only entry; empty with a source) */
    public function itemSets(): array
    {
        return $this->itemSets;
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
     * A copy with $resolve applied to the source (if any) and to the source of every file media; link
     * media (a URL the ingester keeps) are left as they are.
     *
     * @param callable(string): string $resolve
     */
    public function withSources(callable $resolve): ItemImportConfig
    {
        return new self(
            $this->source !== null ? $resolve($this->source) : null,
            $this->title,
            array_map(fn(MediaConfig $m) => $m->isFile() ? $m->withSource($resolve($m->source())) : $m, $this->media),
            $this->sites,
            $this->itemSets
        );
    }

    public function toArray(): array
    {
        $data = $this->source !== null ? ['source' => $this->source] : [];
        if ($this->title !== null) {
            $data['title'] = $this->title;
        }
        if ($this->itemSets) {
            $data['itemSets'] = $this->itemSets;
        }
        if ($this->sites !== null) {
            $data['sites'] = $this->sites;
        }
        if ($this->media) {
            $data['media'] = array_map(fn(MediaConfig $m) => $m->toArray(), $this->media);
        }
        return $data;
    }

    private static function isNameList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && !array_filter($value, fn($name) => !is_string($name) || trim($name) === '');
    }
}
