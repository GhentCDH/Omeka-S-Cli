<?php

namespace OSC\Commands\Dummy\Generator;

use OSC\Commands\Dummy\Config\ResourceConfig;
use OSC\Commands\Dummy\Resource\ResourceClassResolver;
use OSC\Commands\Dummy\Resource\ResourcePool;

class ResourceGenerator
{
    /** @var GeneratorInterface */

    private GeneratorFactory $generatorFactory;

    public function __construct(private readonly ResourceConfig $config, private readonly ResourcePool $pool, private readonly ResourceClassResolver $classResolver)
    {
        $this->generatorFactory = new GeneratorFactory($this->pool, $this->classResolver);
        $this->createGenerators($this->config);
    }

    public function generate(): array
    {
        // add static properties
        $data = $this->config->getFixedData();

        // run generators
        foreach ($this->generators as $property => $generators) {
            if (is_object($generators)) {
                $ret = $generators->generate()
                    ?? throw new \RuntimeException(
                        "Generator for property '{$property}' did not return a value."
                    );
                $data[$property] = $ret;
            }
            if (is_array($generators)) {
                $data[$property] = $data[$property] ?? [];
                foreach ($generators as $generator) {
                    $ret = $generator->generate()
                        ?? throw new \RuntimeException(
                            "Generator for property '{$property}' did not return a value."
                        );
                    $data[$property][] = $ret;
                }
            }
        }

        return $data;
    }

    /**
     * @param ResourceConfig $config
     */
    private function createGenerators(ResourceConfig $config): void
    {
        $this->generators = [];

        foreach ($config->getGeneratorConfigs() as $property => $propertyConfigs) {
            if (array_is_list($propertyConfigs)) {
                $this->generators[$property] = [];
                foreach ($propertyConfigs as $propertyConfig) {
                    $this->generators[$property][] = $this->generatorFactory->create($property, $propertyConfig);
                }
            } else {
                $this->generators[$property] = $this->generatorFactory->create($property, $propertyConfigs);
            }
        }
    }

}
