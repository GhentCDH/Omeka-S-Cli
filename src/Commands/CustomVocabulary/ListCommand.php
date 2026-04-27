<?php
namespace OSC\Commands\CustomVocabulary;

use Exception;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use OSC\Commands\AbstractCommand;

class ListCommand extends AbstractCustomVocabularyCommand
{
    public function __construct()
    {
        parent::__construct('custom-vocabulary:list', 'List available custom vocabularies');
        $this->optionJson();
        $this->optionCSV();
    }

    public function execute(): void
    {
        $format = $this->getOutputFormat('table');

        // Get Omeka instance and API
        $omekaInstance = $this->getOmekaInstance(false);
        $api = $omekaInstance->getServiceManager()->get('Omeka\ApiManager');

        // Check if CustomVocab module is installed
        if (!$omekaInstance->getModuleApi()->isActive('CustomVocab')) {
            throw new Exception("Custom Vocab module is not installed or active. Install/enable it before using custom-vocabulary:* commands.");
        }

        // Prepare query parameters
        $params = [];

        // Fetch resource templates via API
        $response = $api->search('custom_vocabs', $params);
//        /** @var ResourceTemplateRepresentation[] $vocabularies */
        $vocabularies = $response->getContent();

        if (empty($vocabularies)) {
            $this->warn('No custom vocabularies found.');
            return;
        }

        // Prepare data for output
        $data = [];
        foreach ($vocabularies as $vocabulary) {
            $data[] = [
                'id' => $vocabulary->id(),
                'label' => $vocabulary->label(),
                'lang' => $vocabulary->lang(),
                'type' => $vocabulary->type(),
                'owner' => $vocabulary->owner() ? $vocabulary->owner()->name() : 'N/A',
            ];
        }

        $this->outputFormatted($data, $format);
    }
}