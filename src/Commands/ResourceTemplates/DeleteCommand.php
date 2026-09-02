<?php
namespace OSC\Commands\ResourceTemplates;

use InvalidArgumentException;

class DeleteCommand extends AbstractResourceTemplateCommand
{

    public function __construct()
    {
        parent::__construct('resource-template:delete', 'Delete a resource template');
        $this->argument('<identifier>', 'Resource template ID or label');
        $this->option('-f --force', 'Force delete');
        $this->optionIgnoreNotFound('resource template');
    }

    public function execute(string $identifier, ?bool $force, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();

        // Find resource template by ID or label
        $existingResourceTemplate = $this->requireResourceTemplate($identifier, $api, $ignoreNotFound);
        if (!$existingResourceTemplate) {
            return;
        }

        // Check if resource template is in use
        $itemCount = $existingResourceTemplate->itemCount();
        if ($itemCount > 0) {
            if (!$force) {
                throw new InvalidArgumentException("The resource template is currently in use by {$itemCount} item(s). Use --force to delete it anyway.");
            }
        }

        // Delete resource template
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('resource_templates', [ 'id' => $existingResourceTemplate->id() ]);

        $this->ok("Resource template '{$existingResourceTemplate->label()}' deleted.", true);
    }
}

