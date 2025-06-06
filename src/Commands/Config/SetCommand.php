<?php
namespace OSC\Commands\Config;

use Omeka\Settings\Settings;
use OSC\Commands\AbstractCommand;

class SetCommand extends AbstractCommand
{

    public function __construct()
    {
        parent::__construct('config:set', 'Set global setting');
        $this->argument('<id>', 'Setting id');
        $this->argument('<value>', 'Setting value');

    }

    public function defaults(): self {
        parent::defaults();

        return $this;
    }

    public function execute(string $id, string $value): void
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        /** @var Settings $settings */
        $settings = $serviceManager->get('Omeka\Settings');

        $value = $this->convertStringToType($value);
        $settings->set($id, $value);
    }

    protected function convertStringToType(string $input)
    {
        // Attempt to decode JSON-like strings
        $decoded = json_decode($input, true);

        // If decoding fails, return the original string
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Handle boolean strings explicitly
        $lowerInput = strtolower($input);
        if ($lowerInput === 'true') {
            return true;
        } elseif ($lowerInput === 'false') {
            return false;
        }

        // Return the original string if no conversion is possible
        return $input;
    }
}
