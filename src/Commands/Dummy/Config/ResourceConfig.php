<?php
namespace OSC\Commands\Dummy\Config;

use OSC\Helper\ResourceFetcher;

abstract class ResourceConfig implements ResourceConfigInterface, \IteratorAggregate {

    private array $generators;
    private array $fixed;

    protected function __construct(protected array $config)
    {
        $this->validate();
        $this->parseConfig();
    }

    public static function fromDefaultConfig(): static
    {
        $config = static::defaultConfig();

        $instance = new static($config);

        return $instance;
    }

    public static function fromSource(string $source): static
    {
        $config = ResourceFetcher::fetchJson($source);
        return new static($config);
    }

    public function getGeneratorConfigs(): array
    {
        return $this->generators;
    }

    public function getFixedData(): array
    {
        return $this->fixed;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->config);
    }

    public final function config(): array
    {
        return $this->config;
    }

    protected abstract function validate();

    /**
     * @param ResourceConfig $config
     */
    private function parseConfig(): void
    {
        $this->generators = [];
        $this->fixed = [];

        foreach ($this->config as $property => $propertyConfigs) {
            if (!is_array($propertyConfigs)) {
                $this->fixed[$property] = $propertyConfigs;
                continue;
            }

            if (array_is_list($propertyConfigs)) {
                $this->generators[$property] = [];
                foreach ($propertyConfigs as $propertyConfig) {
                    if ($this->isGeneratorConfig($propertyConfig)) {
                        $this->generators[$property][] = $propertyConfig;
                    } else {
                        $this->fixed[$property] = $propertyConfig;
                    }
                }
            } else {
                if ( $this->isGeneratorConfig($propertyConfigs) ) {
                    $this->generators[$property] = $propertyConfigs;
                } else {
                    $this->fixed[$property] = $propertyConfigs;
                }
            }
        }
    }

    private function isGeneratorConfig(mixed $config): bool
    {
        return is_array($config) && count($config) && isset($config['generator']);
    }

}