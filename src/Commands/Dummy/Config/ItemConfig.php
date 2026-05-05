<?php

namespace OSC\Commands\Dummy\Config;

class ItemConfig extends ResourceConfig implements ResourceConfigInterface
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
    }
}
