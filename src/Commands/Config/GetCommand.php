<?php
namespace OSC\Commands\Config;

use Omeka\Settings\Settings;
use OSC\Commands\AbstractCommand;
use OSC\Commands\OutputFormat;
use OSC\Exceptions\NotFoundException;

class GetCommand extends AbstractCommand
{

    public function __construct()
    {
        parent::__construct('config:get', 'Get global setting');
        $this->argument('<id>', 'Setting id to get');
    }

    public function defaults(): self {
        parent::defaults();

        return $this;
    }

    public function execute(string $id): void
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        /** @var Settings $settings */
        $settings = $serviceManager->get('Omeka\Settings');
        $value = $settings->get($id);
        if (is_null($value)) {
            throw new NotFoundException('Setting not found: ' . $id);
        }

        echo json_encode($value);
        $this->io()->eol();
    }
}
