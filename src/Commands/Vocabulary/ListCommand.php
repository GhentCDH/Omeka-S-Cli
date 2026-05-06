<?php
namespace OSC\Commands\Vocabulary;

use Omeka\Api\Representation\VocabularyRepresentation;
use OSC\Commands\AbstractCommand;

class ListCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('vocabulary:list', 'List vocabularies');
        $this->optionJson();
    }

    public function execute(?bool $json = false): void
    {
        $format = $this->getOutputFormat('table');

        // Get Omeka instance and API
        $omekaInstance = $this->getOmekaInstance(false);
        $api = $omekaInstance->getApi();

        // Prepare query parameters
        $params = [];

        // Fetch resource templates via API
        $response = $api->search('vocabularies', $params);
        /** @var VocabularyRepresentation[] $vocabularies */
        $vocabularies = $response->getContent();

        if (empty($vocabularies)) {
            if ($format === 'table') {
                $this->warn('No vocabularies found.');
                return;
            }
        }

        // Prepare data for output
        $data = [];
        foreach ($vocabularies as $vocabulary) {
            $data[] = [
                'id' => $vocabulary->id(),
                'label' => $vocabulary->label(),
                'prefix' => $vocabulary->prefix(),
                'namespaceUri' => $vocabulary->namespaceUri(),
                'propertyCount' => $vocabulary->propertyCount(),
                'resourcesClassCount' => $vocabulary->resourceClassCount(),
                'owner' => $vocabulary->owner() ? $vocabulary->owner()->email() : 'system',
            ];
        }

        $this->outputFormatted($data, $format);
    }
}