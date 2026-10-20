<?php
namespace OSC\Resource;

use InvalidArgumentException;

/**
 * Turn the list/search command options into an Omeka API search query. Core search parameters take
 * ids, so class terms, template labels and site slugs are resolved here.
 */
final class QueryBuilder
{
    public function __construct(private Catalog $catalog)
    {
    }

    /**
     * @param array $filters class, template, itemSet, site, property (term=value list), public, private,
     *                       limit, page, query (raw query string, applied last), text (fulltext search)
     * @throws InvalidArgumentException
     */
    public function build(ResourceType $type, array $filters): array
    {
        $query = [];
        if (self::given($filters, 'text')) {
            $query['fulltext_search'] = (string) $filters['text'];
        }
        if (self::given($filters, 'class')) {
            $query['resource_class_id'] = $this->catalog->resourceClassId($filters['class'])
                ?? throw new InvalidArgumentException("Unknown resource class \"{$filters['class']}\".");
        }
        if (self::given($filters, 'template')) {
            $query['resource_template_id'] = $this->catalog->resourceTemplateId($filters['template'])
                ?? throw new InvalidArgumentException("Unknown resource template \"{$filters['template']}\".");
        }
        if ($type === ResourceType::Item && self::given($filters, 'itemSet')) {
            $query['item_set_id'] = (int) $filters['itemSet'];
        }
        if (self::given($filters, 'site')) {
            $query['site_id'] = $this->catalog->siteId($filters['site'])
                ?? throw new InvalidArgumentException("Unknown site \"{$filters['site']}\".");
        }
        foreach ($filters['property'] ?? [] as $filter) {
            if (!str_contains((string) $filter, '=')) {
                throw new InvalidArgumentException("--property expects term=value, got \"{$filter}\".");
            }
            [$term, $value] = explode('=', (string) $filter, 2);
            $query['property'][] = ['joiner' => 'and', 'property' => trim($term), 'type' => 'eq', 'text' => $value];
        }
        if (!empty($filters['public']) && !empty($filters['private'])) {
            throw new InvalidArgumentException('Use either --public or --private.');
        }
        if (!empty($filters['public'])) {
            $query['is_public'] = 1;
        } elseif (!empty($filters['private'])) {
            $query['is_public'] = 0;
        }
        if (self::given($filters, 'limit')) {
            $query['per_page'] = (int) $filters['limit'];
            $query['page'] = self::given($filters, 'page') ? (int) $filters['page'] : 1;
        }
        if (self::given($filters, 'query')) {
            parse_str((string) $filters['query'], $raw);
            $query = array_merge($query, $raw);
        }
        return $query;
    }

    private static function given(array $filters, string $key): bool
    {
        return isset($filters[$key]) && $filters[$key] !== '';
    }
}
