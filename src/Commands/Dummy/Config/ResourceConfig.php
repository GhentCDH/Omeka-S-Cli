<?php
namespace OSC\Commands\Dummy\Config;

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

    public static function fromFile(string $path): static
    {
        if (!is_readable($path)) {
            throw new \Exception("Config file not found or not readable: {$path}");
        }

        $content = file_get_contents($path);
        $config  = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in config file '{$path}': " . json_last_error_msg());
        }

        $instance = new static($config);

        return $instance;
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