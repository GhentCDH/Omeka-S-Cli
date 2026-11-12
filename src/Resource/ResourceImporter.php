<?php
namespace OSC\Resource;

use Closure;
use InvalidArgumentException;
use LogicException;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Manager as ApiManager;
use OSC\Helper\Config\MediaConfig;
use OSC\Helper\ErrorStoreFormatter;
use OSC\Helper\ResourceFetcher;
use Throwable;

/**
 * Import one resource (and, for an item, its media): resolve a context given as a URL, build the
 * payload, skip the resource when it already exists, then create it and its media in one API request,
 * so a rejected media file leaves no item behind.
 *
 * Shared by item:import, item-set:import and the blueprint deployer (through those commands).
 */
class ResourceImporter
{
    private Closure $fetchJson;

    /**
     * @param ApiManager|null $api        Null only where nothing is created (dry run, existing resources)
     * @param Closure|null    $fetchJson  Fetches a context URL (default ResourceFetcher::fetchJson)
     * @param Closure|null    $checkMedia Checks the media before anything is created: receives the
     *                                    MediaConfig list, returns problems (see MediaIngesters::check())
     */
    public function __construct(
        private ?ApiManager $api,
        private Catalog $catalog,
        ?Closure $fetchJson = null,
        private ?Closure $checkMedia = null,
    ) {
        $this->fetchJson = $fetchJson ?? ResourceFetcher::fetchJson(...);
    }

    /**
     * @param MediaConfig[] $media
     * @throws ResourceImportException When the resource has problems, or Omeka refuses it
     * @throws InvalidArgumentException When media are given for an item set
     */
    public function import(
        array $document,
        ResourceType $type,
        string $label,
        array $media = [],
        ?string $identifyBy = null,
        bool $dryRun = false,
        ?array $sites = null,
    ): ImportResult {
        if ($media && $type !== ResourceType::Item) {
            throw new InvalidArgumentException('Media can only be added to items.');
        }
        if (isset($document['@context']) && is_string($document['@context'])) {
            $document['@context'] = $this->fetchContext($document['@context']);
        }

        if ($type === ResourceType::Item) {
            $document = $this->withSites($document, $sites);
        }
        try {
            $built = (new PayloadBuilder($this->catalog))->build($document, $type, $label, $identifyBy);
        } catch (ResourceImportException $e) {
            // a record that already exists is left as it is, even when its file no longer applies
            // cleanly (e.g. a reference that now matches two records): only a new one is an error
            $existing = $this->existing($type, $e->identity(), $e->title(), true);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        $existing = $this->existing($type, $built->identity(), $built->title());
        if ($existing !== null) {
            return $existing;
        }
        if ($media && $this->checkMedia !== null) {
            // Omeka stores a media's file while it builds the item, before it knows the item will
            // validate: a file it then rejects would leave the files stored before it behind
            $problems = ($this->checkMedia)($media);
            if ($problems) {
                throw new ResourceImportException($label, $problems);
            }
        }

        if ($dryRun) {
            return ImportResult::wouldCreate($built->title(), $built->warnings());
        }
        if ($this->api === null) {
            throw new LogicException('ResourceImporter needs the API to create a resource.');
        }

        $payload = $built->payload();
        if ($media) {
            $payload['o:media'] = array_map(fn(MediaConfig $m) => $m->toApiPayload(), $media);
        }
        try {
            $resource = $this->api->create($type->value, $payload)->getContent();
        } catch (ValidationException $e) {
            throw new ResourceImportException($label, explode("\n", ErrorStoreFormatter::format($e->getErrorStore()->getErrors())));
        } catch (Throwable $e) {
            throw new ResourceImportException($label, [$e->getMessage()]);
        }
        return ImportResult::created((int) $resource->id(), $built->title(), $built->warnings());
    }

    /**
     * The result for a resource that already exists (one match: exists, several: ambiguous), or null.
     *
     * @param bool $knownTermOnly Only look up by a property the instance has (when the build failed,
     *                            the identity term may be one it does not know)
     */
    private function existing(ResourceType $type, ?Identity $identity, ?string $title, bool $knownTermOnly = false): ?ImportResult
    {
        if ($identity === null || ($knownTermOnly && $identity->term !== null && !$this->catalog->hasProperty($identity->term))) {
            return null;
        }
        $ids = $identity->term === null
            ? $this->catalog->findByTitle($type->value, $identity->value)
            : $this->catalog->findByValue($type->value, $identity->term, $identity->value);
        return match (true) {
            count($ids) === 1 => ImportResult::exists((int) $ids[0], $title),
            count($ids) > 1 => ImportResult::ambiguous(count($ids), $title),
            default => null,
        };
    }

    /**
     * $document with its o:site replaced by $sites (slugs or titles, resolved by PayloadBuilder; none
     * resolving → the default site). An empty list means the default site; without one, o:site is
     * removed, so Omeka assigns the item to the sites that take new items. Null keeps the document's.
     *
     * @param string[]|null $sites
     */
    public function withSites(array $document, ?array $sites): array
    {
        if ($sites === null) {
            return $document;
        }
        if ($sites !== []) {
            $document['o:site'] = array_map(fn(string $site) => ['o:slug' => $site], array_values($sites));
            return $document;
        }
        $default = $this->catalog->defaultSiteId();
        if ($default === null) {
            unset($document['o:site']);
        } else {
            $document['o:site'] = [['o:id' => $default]];
        }
        return $document;
    }

    /** The prefix map of a context URL; empty (the target's prefixes apply) when it cannot be read. */
    private function fetchContext(string $url): array
    {
        try {
            $data = ($this->fetchJson)($url);
        } catch (Throwable) {
            return [];
        }
        return PortableJsonLd::contextMap(is_array($data) ? ($data['@context'] ?? null) : null);
    }
}
