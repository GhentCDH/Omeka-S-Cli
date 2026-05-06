<?php
namespace OSC\Commands\CustomVocabulary;

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
        $api = $this->getOmekaInstance(false)->getApi();

        // Get vocabularies
        $vocabularies = $api->search('custom_vocabs')->getContent();
        if (empty($vocabularies)) {
            if ($format === 'table') {
                $this->info('No custom vocabularies found.', true);
                return;
            }
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