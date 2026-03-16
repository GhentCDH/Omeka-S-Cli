<?php

namespace OSC\Commands\Dummy;

use Doctrine\ORM\Mapping\Entity;
use Exception;
use Omeka\Entity\Resource;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Dummy\ValueGenerator\ValueGeneratorFactory;

class CreateItems extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('dummy:create-items', 'Add dummy items');
        $this->argument('<total>', 'Number of items to create', 'intval', 1);
        $this->option('--max-sets', 'Set the number of item sets an item can below to');
        $this->option('--config', 'Path to JSON config file for item generation');
        $this->option('--all-generators', 'Use a built-in config that exercises every value generator type and mode', 'boolval', false );
    }

    public function execute(int $total, ?string $config = null, bool $allGenerators = false): void
    {
        $api        = $this->getOmekaInstance()->getApi();
        $total      = max(1, $total);
        $itemConfig = match (true) {
            $allGenerators   => DummyItemConfig::allGenerators(),
            $config !== null => DummyItemConfig::fromFile($config),
            default          => DummyItemConfig::default(),
        };

        $this->executeWithConfig($api, $total, $itemConfig);
    }

    private function executeWithConfig($api, int $total, DummyItemConfig $itemConfig): void
    {
        // Pre-generation pass: resolve class IDs (fails hard on unknown term)
        $this->debug("Resolving class IDs ...");
        $classIds = $this->resolveClassIds($api, $itemConfig->getClassConfig());
        $this->io()->eol();

        // Pre-generation pass: fetch resource pools needed by resource generators
        $this->debug("Prefetching Resource IDs ...");
        $resourcePools = $this->prefetchResources($api, $itemConfig);
        $this->io()->eol();

        // Build value generators per property
        $propertyGenerators = [];
        foreach ($itemConfig->getPropertyConfigs() as $propConfig) {
            $property                      = $propConfig['property'];
            $propertyGenerators[$property] = [];

            foreach ($propConfig['values'] as $valueConfig) {
                if (($valueConfig['type'] ?? '') === 'resource') {
                    $resourceType = $valueConfig['resourceType'] ?? 'any';
                    $pool         = $resourcePools[$resourceType] ?? [];
                    if (empty($pool)) {
                        throw new Exception(
                            "No resources found for type '{$resourceType}' required by property '{$property}'."
                        );
                    }
                    $propertyGenerators[$property][] = ValueGeneratorFactory::create($valueConfig, $pool);
                } else {
                    $propertyGenerators[$property][] = ValueGeneratorFactory::create($valueConfig);
                }
            }
        }

        $this->info('Start item creation ...', true);
        $i = 1;
        while ($i <= $total) {
            $classId = empty($classIds) ? null : $classIds[array_rand($classIds)];
            $this->createItemFromGenerators($api, $classId, $propertyGenerators);
            $i++;
            if ($i % 100 === 0) {
                $this->info("Created $i items ...", true);
            }
        }
        $this->ok("Successfully created {$total} items.", true);
    }

    /**
     * Resolve a class config value to an array of numeric class IDs.
     * Fails with a descriptive error if any class term cannot be found.
     *
     * @param string|array|null $classConfig
     * @return int[]
     */
    private function resolveClassIds($api, string|array|null $classConfig): array
    {
        if ($classConfig === null || $classConfig === 'random') {
            $classes = $api->search('resource_classes', [])->getContent();
            return array_map(fn($c) => $c->id(), $classes);
        }

        $terms = is_array($classConfig) ? $classConfig : [$classConfig];
        $ids   = [];

        foreach ($terms as $term) {
            $response = $api->search('resource_classes', ['term' => $term]);
            if ($response->getTotalResults() === 0) {
                throw new Exception(
                    "Resource class not found: '{$term}'. "
                    . "Check the term spelling and ensure the vocabulary is imported."
                );
            }
            $ids[] = $response->getContent()[0]->id();
        }

        return $ids;
    }

    /**
     * Fetch all resource ID pools required by the config's resource value generators.
     */
    private function prefetchResources($api, DummyItemConfig $config): array
    {
        $pools = [];
        foreach ($config->getResourceTypesNeeded() as $type) {
            switch ($type) {
                case 'items':
                    $pools['items'] = $this->fetchResourceIds($api, 'items');
                    break;
                case 'item-sets':
                    $pools['item-sets'] = $this->fetchResourceIds($api, 'item_sets');
                    break;
                default: // 'any'
                    $pools['any'] = array_merge(
                        $this->fetchResourceIds($api, 'items'),
                        $this->fetchResourceIds($api, 'item_sets')
                    );
                    break;
            }
        }
        return $pools;
    }

    private function fetchResourceIds($api, string $resource): array
    {
        $response = $api->search($resource, ['limit' => 1000], ['responseContent' => 'resource']);
        if ($response->getTotalResults() === 0) {
            return [];
        }
        /** @var Resource[] $content */
        $content = $response->getContent();
        return array_map(fn($r) => $r->getId(), $content);
    }

    private function createItemFromGenerators($api, ?int $classId, array $propertyGenerators): void
    {
        $data = [];

        if ($classId !== null) {
            $data['o:resource_class'] = ['o:id' => $classId];
        }

        foreach ($propertyGenerators as $property => $generators) {
            $data[$property] = [];
            foreach ($generators as $generator) {
                $data[$property][] = $generator->generate();
            }
        }

        $options = [
            'responseContent' => 'resource',
            'initialize'      => false,
            'finalize'        => false,
        ];

        $api->create('items', $data, [], $options);
    }

}
