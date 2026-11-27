<?php
namespace OSC\Commands\Resource;

use InvalidArgumentException;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use OSC\Omeka\OmekaInstance;
use OSC\Resource\ResourceType;

/**
 * Delete items or item sets, by ID or by the filters of the list command. Everything given is checked
 * before Omeka S is bootstrapped, and every target is looked up before any is deleted. Omeka S deletes an
 * item's media (and their files) with it; deleting an item set keeps its items.
 */
class DeleteCommand extends AbstractResourceCommand
{
    public function __construct(ResourceType $type)
    {
        parent::__construct($type, 'delete', "Delete {$type->label()}s");
        $this->argument('[ids...]', "IDs of the {$type->label()}s to delete");
        $this->optionFilters();
        $this->optionIgnoreNotFound($type->label());
        $this->optionDryRun();
        $this->option('--force', 'Delete everything the filters match', 'boolval', false);
        $this->optionJson();
        $prefix = $type->commandPrefix();
        $this->usage(
            "{$prefix}:delete [IDS...] [options]<eol/>"
            . '<eol/>Examples:<eol/><eol/>'
            . "* Delete two {$type->label()}s by ID:<eol/>"
            . "{$prefix}:delete 12 13<eol/>"
            . '<eol/>'
            . "* List the {$type->label()}s a filter would delete:<eol/>"
            . "{$prefix}:delete --property dcterms:identifier=obj-1 --dry-run<eol/>"
            . '<eol/>'
            . "* Delete them:<eol/>"
            . "{$prefix}:delete --property dcterms:identifier=obj-1 --force<eol/>"
        );
    }

    public function execute(?array $ids = null, ?bool $ignoreNotFound = false, ?bool $dryRun = false, ?bool $force = false): void
    {
        $ids = $ids ?? [];
        $this->assertSelection($ids, (bool) $dryRun, (bool) $force);

        $instance = $this->getOmekaInstance();
        $targets = $ids !== [] ? $this->readTargets($instance, $ids, (bool) $ignoreNotFound) : $this->searchTargets($instance);

        $api = $instance->getApi();
        $rows = [];
        foreach ($targets as $resource) {
            $title = (string) $resource->displayTitle();
            if ($dryRun) {
                $this->note("Would delete {$this->type->label()} {$resource->id()} '{$title}'.", true);
            } else {
                $api->delete($this->type->value, $resource->id());
                $this->ok(ucfirst($this->type->label()) . " {$resource->id()} '{$title}' deleted.", true);
            }
            $rows[] = ['id' => $resource->id(), 'title' => $title];
        }
        if ($this->getOutputFormat() === 'json') {
            $this->outputFormatted($rows, 'json');
        }
    }

    /**
     * IDs or filters, never both or neither; an ID is exact (a positive integer, never cast); filters need
     * --force or --dry-run.
     *
     * @param string[] $ids
     * @throws InvalidArgumentException
     */
    private function assertSelection(array $ids, bool $dryRun, bool $force): void
    {
        $label = $this->type->label();
        $hasFilters = $this->hasFilters();
        if ($ids === [] && !$hasFilters) {
            throw new InvalidArgumentException(
                "Give the IDs of the {$label}s to delete, or filters that select them (as for {$this->type->commandPrefix()}:list)."
            );
        }
        if ($ids !== [] && $hasFilters) {
            throw new InvalidArgumentException('Give either IDs or filters, not both.');
        }
        foreach ($ids as $id) {
            if (!ctype_digit((string) $id) || (int) $id === 0) {
                throw new InvalidArgumentException("'{$id}' is not an {$label} ID.");
            }
        }
        if ($hasFilters && !$force && !$dryRun) {
            throw new InvalidArgumentException("Deleting {$label}s by filters needs --force; use --dry-run first to list them.");
        }
    }

    /**
     * Every ID looked up before any is deleted: a missing one is an error, or, with --ignore-not-found,
     * a warning.
     *
     * @param string[] $ids
     * @return AbstractResourceEntityRepresentation[] In ID order
     */
    private function readTargets(OmekaInstance $instance, array $ids, bool $ignoreNotFound): array
    {
        $api = $instance->getApi();
        $targets = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                $targets[$id] = $api->read($this->type->value, $id)->getContent();
            } catch (NotFoundException) {
                $notFound = ucfirst($this->type->label()) . " {$id} not found.";
                if (!$ignoreNotFound) {
                    throw new InvalidArgumentException($notFound);
                }
                $this->warn(rtrim($notFound, '.') . '. Skipped.', true);
            }
        }
        ksort($targets);
        return array_values($targets);
    }

    /** @return AbstractResourceEntityRepresentation[] Everything the filters match, in ID order */
    private function searchTargets(OmekaInstance $instance): array
    {
        $targets = $instance->getApi()->search($this->type->value, $this->query($instance))->getContent();
        if ($targets === []) {
            $this->warn("No {$this->plural()} match the filters. Nothing to do.", true);
        }
        return $targets;
    }
}
