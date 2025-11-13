<?php
namespace OSC\Commands\ResourceTemplates;

use Exception;
use InvalidArgumentException;
use Omeka\Controller\Admin\ResourceTemplateController;
use Laminas\Http\Request;

class ExportCommand extends AbstractResourceTemplateCommand
{

    public function __construct()
    {
        parent::__construct('resource-template:export', 'Export a resource template');
        $this->argument('<identifier>', 'Resource template ID or label');
        $this->argument('[filename]', 'Export to file');
    }

    public function execute(string $identifier, ?string $filename = null): void
    {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();
        $api = $omekaInstance->getApi();
        $serviceManager = $omekaInstance->getServiceManager();

        // Find resource template by ID or label
        $existingResourceTemplate = $this->findResourceTemplate($identifier, $api);
        if (!$existingResourceTemplate) {
            throw new InvalidArgumentException("Resource template '{$identifier}' not found by ID or label.");
        }

        // Get the actual ID for the controller
        $id = $existingResourceTemplate->id();

        // Create controller instance
        $dataTypeManager = $serviceManager->get('Omeka\DataTypeManager');
        $controller = new ResourceTemplateController($dataTypeManager);
        $controller->setPluginManager($serviceManager->get('ControllerPluginManager'));

        // Create a mock request with the ID parameter
        $request = new Request();
        $request->setUri('/admin/resource-templates/'.$id.'/export');
        $routeMatch = $serviceManager->get('Router')->match($request);
        $routeMatch->setParam('id', $id);

        // Set up the controller context
        $controller->getEvent()->setRouteMatch($routeMatch);

        // Call the existing exportAction method
        $response = $controller->exportAction();

        // Get the exported content
        $exportContent = $response->getContent();

        // Extract filename from headers or generate one
        if (!$filename) {
            $this->echo($exportContent, true);
        } else {
            // Write to file
            if (file_put_contents($filename, $exportContent.PHP_EOL) === false) {
                throw new Exception("Failed to write resource template to file '{$filename}'.");
            }
            $this->ok("Successfully exported resource template '{$existingResourceTemplate->label()}' to '{$filename}'.", true);
        }
    }
}