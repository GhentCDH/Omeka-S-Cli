<?php
namespace OSC\Commands\Config;

use Omeka\Settings\Settings;
use OSC\Commands\AbstractCommand;
use OSC\Commands\OutputFormat;

class ListCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:list', 'List all global settings');
        $this->argument("[search]", "Search for a specific setting by name or value");

    }

    public function defaults(): self {
        parent::defaults();

        return $this;
    }

    public function execute(?string $search): void
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        /** @var Settings $settings */
        $settings = $serviceManager->get('Omeka\Settings');

        $result = json_decode(json_encode((array)$settings), true);
        $result = $result["\0*\0cache"];
        if ($search) {
            $search = strtolower($search);
            $result = array_filter($result, function ($key) use ($search) {
                return strpos(strtolower($key), $search) !== false;
            }, ARRAY_FILTER_USE_KEY);
        }

        $this->beQuiet();
        $this->outputFormatted($result, OutputFormat::JSON);
        $this->io()->eol();
    }
}
