<?php

namespace OSC\Commands\Vocabulary;

use OSC\Manager\Result;
use OSC\Repository\Vocabulary\VocabularyItem;


trait FormattersTrait
{
    /**
     * @param Result<VocabularyItem>[] $results
     * @param bool|null $extended
     * @return array
     */
    private function formatVocabularyResults(array $results, ?bool $extended = false): array {
        $ret = [];
        foreach ($results as $result) {
            $item = $result->getItem();
            $formatted = [
                'id' =>  $item->getId(),
                'label' => $item->getName(),
                'namespaceUri' => $item->getNamespaceUri(),
                'prefix' => $item->getPrefix(),
                'url' => $item->getUrl(),
                'format' => $item->getFormat(),
            ];

            if ($extended) {
                $formatted['comment'] = $item->getComment() ?? "";
                $formatted['repositoryId'] = $result->getRepository()->getId();
            }

            $ret[] = $formatted;
        }
        return $ret;
    }
}