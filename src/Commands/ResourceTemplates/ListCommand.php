<?php
namespace OSC\Commands\ResourceTemplates;

use Omeka\Api\Representation\ResourceTemplateRepresentation;
use OSC\Commands\AbstractCommand;

class ListCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('resource-template:list', 'List available resource templates');
        $this->optionJson();
        $this->optionCSV();
    }

    public function execute(): void
    {
        $format = $this->getOutputFormat('table');

        // Get Omeka instance and API
        $omekaInstance = $this->getOmekaInstance(false);
        $api = $omekaInstance->getServiceManager()->get('Omeka\ApiManager');

        // Prepare query parameters
        $params = [];

        // Fetch resource templates via API
        $response = $api->search('resource_templates', $params);
        /** @var ResourceTemplateRepresentation[] $resourceTemplates */
        $resourceTemplates = $response->getContent();

        if (empty($resourceTemplates)) {
            $this->warn('No resource templates found.');
            return;
        }

        // Prepare data for output
        $data = [];
        foreach ($resourceTemplates as $template) {
            $data[] = [
                'id' => $template->id(),
                'label' => $template->label(),
                'class' => $template->resourceClass() ? $template->resourceClass()->label() : 'N/A',
                'propertyCount' => count($template->resourceTemplateProperties()),
                'itemCount' => $template->itemCount(),
                'owner' => $template->owner() ? $template->owner()->name() : 'N/A',
            ];
        }

        $this->outputFormatted($data, $format);
    }
}