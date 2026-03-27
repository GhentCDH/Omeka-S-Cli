<?php

namespace OSC\Commands\Dummy\Config;

class ItemConfig extends ResourceConfig implements ResourceConfigInterface
{
    public static function defaultConfig(): array
    {
        return [
            'dcterms:title' => [
                ['generator' => 'literal', 'mode' => 'lorem', 'minWords' => 2, 'maxWords' => 5],
            ],
            'dcterms:description' => [
                ['generator' => 'literal', 'mode' => 'lorem', 'minWords' => 5, 'maxWords' => 15],
            ],
        ];
    }


    protected function validate(): void
    {
//        $this->validateItemFields();
//
//        $validTypes = ['literal', 'uri', 'resource'];
//
//        foreach ($this->getPropertyConfigs() as $property => $valueConfigs) {
//            foreach ($valueConfigs as $j => $valueConfig) {
//                $type = $valueConfig['generator'] ?? '';
//                if (!in_array($type, $validTypes, true)) {
//                    throw new \InvalidArgumentException(
//                        "Unknown generator '{$type}' in property '{$property}' value[{$j}]. "
//                        . "Supported generators: " . implode(', ', $validTypes) . '.'
//                    );
//                }
//            }
//        }
    }
}
