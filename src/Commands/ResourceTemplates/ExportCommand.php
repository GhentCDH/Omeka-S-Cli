<?php
namespace OSC\Commands\ResourceTemplates;

use Exception;
use OSC\Commands\AbstractCommand;
use Omeka\Controller\Admin\ResourceTemplateController;
use Laminas\Http\Request;
use Laminas\Stdlib\Parameters;

class ExportCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('resource-template:export', 'Export a resource template');
        $this->argument('<id>', 'Resource template ID to export');
        $this->argument('[filename]', 'Export to file');
    }

    public function execute(int $id, ?string $filename = null): void
    {
        // Get Omeka instance and service manager
        $omekaInstance = $this->getOmekaInstance();
        $api = $omekaInstance->getApi();
        $serviceManager = $omekaInstance->getServiceManager();

        // Check if the resource template already exists.
        try {
            $resourceTemplate = $api->read('resource_templates', $id)->getContent();
        } catch (Exception $e) {
            $resourceTemplate = null;
        }
        if (!$resourceTemplate) {
            throw new Exception("Resource Template with ID {$id} does not exist.");
        }

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
                throw new Exception("Failed to write to file: {$filename}");
            }
            $this->ok("Succesfully exported Resource Template  to: {$filename}", true);
        }
    }
}