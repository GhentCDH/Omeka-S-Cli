<?php

namespace OSC\Commands\Dummy\Config;

class ItemSetConfig extends ResourceConfig implements ResourceConfigInterface
{
    public static function defaultConfig(): array
    {
        return [
            'dcterms:title' => [
                ['generator' => 'literal', 'mode' => 'words', 'min' => 2, 'max' => 5],
            ],
            'dcterms:description' => [
                ['generator' => 'literal', 'mode' => 'words', 'min' => 5, 'max' => 15],
            ],
        ];
    }

    protected function validate(): void
    {
        if ($this->config()['o:item_set'] ?? false) {
            throw new \InvalidArgumentException(
                "Item sets cannot have an 'o:item_set' field."
            );
        }
    }
}
