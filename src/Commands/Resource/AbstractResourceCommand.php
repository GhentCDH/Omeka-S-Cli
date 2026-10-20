<?php
namespace OSC\Commands\Resource;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use OSC\Commands\AbstractCommand;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Omeka\OmekaInstance;
use OSC\Resource\OmekaCatalog;
use OSC\Resource\QueryBuilder;
use OSC\Resource\ResourceType;

/**
 * Base of the item and item set commands: each command class is registered once per ResourceType
 * (item:list and item-set:list are the same class), so names, messages and API resources come from
 * the type.
 */
abstract class AbstractResourceCommand extends AbstractCommand
{
    /** Rows shown by list and search when --limit is not given. */
    protected const DEFAULT_LIMIT = 50;

    private ?OmekaCatalog $catalog = null;

    public function __construct(protected ResourceType $type, string $action, string $description)
    {
        parent::__construct("{$type->commandPrefix()}:{$action}", $description);
    }

    /** The search filters shared by list, search and export. */
    protected function optionFilters(): void
    {
        $this->option('--class', 'Resource class term, e.g. dctype:StillImage');
        $this->option('--template', 'Resource template label');
        if ($this->type === ResourceType::Item) {
            $this->option('--item-set', 'Item set ID');
        }
        $this->option('--site', 'Site slug or title');
        $this->option('-p --property [filter...]', 'Exact property value as term=value, e.g. dcterms:creator=Leonardo (repeatable)');
        $this->option('--public', 'Only public resources', 'boolval', false);
        $this->option('--private', 'Only private resources', 'boolval', false);
        $this->option('--query', 'Extra Omeka API query string, e.g. "sort_by=title&sort_order=desc"');
    }

    /**
     * The Omeka search query for the filter options given to this command.
     *
     * @param array $extra More filters (text, limit, page)
     */
    protected function query(OmekaInstance $instance, array $extra = []): array
    {
        $values = $this->values();
        $filters = [
            'class' => $values['class'] ?? null,
            'template' => $values['template'] ?? null,
            'itemSet' => $values['itemSet'] ?? null,
            'site' => $values['site'] ?? null,
            'property' => array_values(array_filter((array) ($values['property'] ?? []), 'is_string')),
            'public' => $values['public'] ?? false,
            'private' => $values['private'] ?? false,
            'query' => $values['query'] ?? null,
        ] + $extra;
        $query = (new QueryBuilder($this->catalog($instance)))->build($this->type, $filters);
        return array_merge(['sort_by' => 'id', 'sort_order' => 'asc'], $query);
    }

    /** List the resources matching the filter options (and a fulltext search text). */
    protected function listResources(?string $text = null): void
    {
        $format = $this->getOutputFormat('table');
        $values = $this->values();
        $instance = $this->getOmekaInstance();
        $query = $this->query($instance, [
            'text' => $text,
            'limit' => $values['limit'] ?? self::DEFAULT_LIMIT,
            'page' => $values['page'] ?? null,
        ]);

        $response = $instance->getApi()->search($this->type->value, $query);
        $resources = $response->getContent();
        if (!$resources) {
            $this->warn("No {$this->plural()} found.", true);
            $this->outputFormatted([], $format);
            return;
        }
        $total = $response->getTotalResults();
        if ($total > count($resources)) {
            $this->info(sprintf('Showing %d of %d %s; use --limit and --page for more.', count($resources), $total, $this->plural()), true);
        }
        $this->outputFormatted(array_map(fn($r) => $this->row($r), $resources), $format);
    }

    /** One row of list output. */
    protected function row(AbstractResourceEntityRepresentation $resource): array
    {
        $row = [
            'id' => $resource->id(),
            'title' => (string) $resource->displayTitle(),
            'class' => $resource->resourceClass()?->term() ?? '',
            'template' => $resource->resourceTemplate()?->label() ?? '',
        ];
        if ($resource instanceof ItemRepresentation) {
            $row['itemSets'] = count($resource->itemSets());
            $row['media'] = count($resource->media());
        } elseif ($resource instanceof ItemSetRepresentation) {
            $row['items'] = $resource->itemCount();
            $row['open'] = $resource->isOpen();
        }
        $row['public'] = $resource->isPublic();
        return $row;
    }

    protected function catalog(OmekaInstance $instance): OmekaCatalog
    {
        return $this->catalog ??= new OmekaCatalog($instance->getServiceManager());
    }

    /** "items" / "item sets". */
    protected function plural(): string
    {
        return $this->type->label() . 's';
    }

    /**
     * A source given on the command line or in a config file, made absolute: a relative path resolves
     * against $base (a config file) or else the working directory; URLs and gh:/gl: references are
     * normalized and kept.
     */
    protected function resolveSource(string $source, ?string $base = null): string
    {
        $resolved = ReferenceResolver::withDefaults()->resolve($source, $base);
        return ResourceFetcher::isUrl($resolved) ? $resolved : Path::toAbsolutePath($resolved, $this->getCwd());
    }
}
