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
        parent::__construct('resource-template:export', 'Export a resource template to JSON');
        $this->argument('<id>', 'Resource template ID to export');
//        $this->option('--output <file>', 'Output file path (default: template_name.json)');
    }

    public function execute(int $id, ?string $file = null): void
    {
//        try {
            // Get Omeka instance and service manager
            $omekaInstance = $this->getOmekaInstance();
            $serviceManager = $omekaInstance->getServiceManager();

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
            if (!$file) {
                echo $exportContent;
            } else {
                // Write to file
                if (file_put_contents($file, $exportContent) === false) {
                    throw new Exception("Failed to write to file: {$file}");
                }
                $this->ok("Resource template exported successfully to: {$file}");
            }

//        } catch (Exception $e) {
//            throw new Exception("Failed to export resource template: " . $e->getMessage());
//        }
    }
}