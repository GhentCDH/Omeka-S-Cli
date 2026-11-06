<?php
namespace OSC\Resource;

use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use OSC\Omeka\SiteApi;
use Throwable;

/**
 * The Catalog of a live Omeka S instance, through its internal API. Lookups are memoized for the life
 * of the object (one command run). Run with an elevated identity: private resources are invisible to an
 * anonymous one.
 */
class OmekaCatalog implements Catalog
{
    /** @var array<string,mixed> */
    private array $memo = [];

    public function __construct(private ServiceManager $services)
    {
    }

    public function namespaceForPrefix(string $prefix): ?string
    {
        return $this->memo("ns:{$prefix}", function () use ($prefix) {
            $vocabulary = $this->first('vocabularies', ['prefix' => $prefix]);
            return $vocabulary?->namespaceUri();
        });
    }

    public function prefixForNamespace(string $namespaceUri): ?string
    {
        return $this->memo("prefix:{$namespaceUri}", function () use ($namespaceUri) {
            $vocabulary = $this->first('vocabularies', ['namespace_uri' => $namespaceUri]);
            return $vocabulary?->prefix();
        });
    }

    public function hasProperty(string $term): bool
    {
        return $this->memo("property:{$term}", fn() => $this->first('properties', ['term' => $term]) !== null);
    }

    public function hasDataType(string $name): bool
    {
        return $this->memo("datatype:{$name}", fn() => $this->services->get('Omeka\DataTypeManager')->has($name));
    }

    public function resourceClassId(string $term): ?int
    {
        return $this->memo("class:{$term}", fn() => $this->first('resource_classes', ['term' => $term])?->id());
    }

    public function resourceClassTerm(int $id): ?string
    {
        return $this->memo("class#{$id}", fn() => $this->read('resource_classes', $id)?->term());
    }

    public function resourceTemplateId(string $label): ?int
    {
        return $this->memo("template:{$label}", fn() => $this->first('resource_templates', ['label' => $label])?->id());
    }

    public function resourceTemplateLabel(int $id): ?string
    {
        return $this->memo("template#{$id}", fn() => $this->read('resource_templates', $id)?->label());
    }

    public function siteId(string $slugOrTitle): ?int
    {
        return $this->memo("site:{$slugOrTitle}", function () use ($slugOrTitle) {
            $sites = new SiteApi($this->services);
            $site = $sites->findSiteBySlug($slugOrTitle) ?? $sites->findSiteByTitle($slugOrTitle);
            if ($site === null) {
                // the Playground matches slugs and titles case-insensitively
                foreach ($sites->getSites() as $candidate) {
                    if (strcasecmp($candidate->slug(), $slugOrTitle) === 0 || strcasecmp($candidate->title(), $slugOrTitle) === 0) {
                        return $candidate->id();
                    }
                }
            }
            return $site?->id();
        });
    }

    public function siteSlug(int $id): ?string
    {
        return $this->memo("site#{$id}", fn() => $this->read('sites', $id)?->slug());
    }

    public function defaultSiteId(): ?int
    {
        return $this->memo('default-site', function () {
            $id = $this->services->get('Omeka\Settings')->get('default_site');
            return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        });
    }

    public function customVocabId(string $label): ?int
    {
        foreach ($this->customVocabs() as $id => $vocabLabel) {
            if ($vocabLabel === $label) {
                return $id;
            }
        }
        return null;
    }

    public function customVocabLabel(int $id): ?string
    {
        return $this->customVocabs()[$id] ?? null;
    }

    public function findByValue(string $resourceName, string $term, string $value): array
    {
        $query = ['property' => [['joiner' => 'and', 'property' => $term, 'type' => 'eq', 'text' => $value]]];
        $ids = $this->api()->search($resourceName, $query, ['returnScalar' => 'id'])->getContent();
        return array_map('intval', array_values($ids));
    }

    public function findByTitle(string $resourceName, string $title): array
    {
        $entity = match ($resourceName) {
            'items' => 'Omeka\Entity\Item',
            'item_sets' => 'Omeka\Entity\ItemSet',
            'media' => 'Omeka\Entity\Media',
        };
        // untyped: the entity manager is a Doctrine object, which the PHAR prefixes (see AGENTS.md)
        $entityManager = $this->services->get('Omeka\EntityManager');
        $ids = $entityManager
            ->createQuery("SELECT r.id FROM {$entity} r WHERE r.title = :title")
            ->setParameter('title', $title)
            ->getSingleColumnResult();
        return array_map('intval', $ids);
    }

    public function refValue(string $resourceName, int $id, string $term): ?string
    {
        $resource = $this->read($resourceName, $id);
        $value = $resource?->value($term);
        if ($value === null) {
            return null;
        }
        $text = $value->value() ?? $value->uri();
        return $text === null || $text === '' ? null : (string) $text;
    }

    /** @return array<int,string> id => label; empty when the CustomVocab module is not active */
    private function customVocabs(): array
    {
        return $this->memo('customvocabs', function () {
            try {
                $vocabs = $this->api()->search('custom_vocabs')->getContent();
            } catch (Throwable) {
                return [];
            }
            $labels = [];
            foreach ($vocabs as $vocab) {
                $labels[$vocab->id()] = $vocab->label();
            }
            return $labels;
        });
    }

    private function first(string $resource, array $query): mixed
    {
        return $this->api()->search($resource, $query + ['limit' => 1])->getContent()[0] ?? null;
    }

    private function read(string $resource, int $id): mixed
    {
        try {
            return $this->api()->read($resource, $id)->getContent();
        } catch (NotFoundException) {
            return null;
        }
    }

    private function api(): ApiManager
    {
        return $this->services->get('Omeka\ApiManager');
    }

    private function memo(string $key, callable $compute): mixed
    {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }
        return $this->memo[$key];
    }
}
