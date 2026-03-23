<?php

namespace OSC\Commands\Dummy\Resource;

class ResourceClassResolver
{
    private $api;
    private array $termToId = [];
    private bool $allFetched = false;

    public function __construct($api)
    {
        $this->api = $api;
    }

    public function resolve(?array $terms): array
    {
        return $terms === null ? $this->allIds() : $this->resolveTerms($terms);
    }

    private function allIds(): array
    {
        if (!$this->allFetched) {
            $classes = $this->api->search('resource_classes', [])->getContent();
            foreach ($classes as $class) {
                $this->termToId[$class->term()] = $class->id();
            }
            $this->allFetched = true;
        }
        return array_values($this->termToId);
    }

    private function resolveTerms(array $terms): array
    {
        $ids = [];
        foreach ($terms as $term) {
            if (!isset($this->termToId[$term])) {
                $response = $this->api->search('resource_classes', ['term' => $term]);
                if ($response->getTotalResults() === 0) {
                    throw new \InvalidArgumentException(
                        "Resource class not found: '{$term}'. "
                        . "Check the term spelling and ensure the vocabulary is imported."
                    );
                }
                $this->termToId[$term] = $response->getContent()[0]->id();
            }
            $ids[] = $this->termToId[$term];
        }
        return $ids;
    }
}
