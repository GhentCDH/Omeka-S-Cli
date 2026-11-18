<?php
namespace OSC\Commands\Config;

use Omeka\Settings\Settings;
use OSC\Commands\AbstractCommand;

class ListCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:list', 'List all global settings');
        $this->argument("[search]", "Only show the settings whose name contains this text (ignoring case)");

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
                return str_contains(strtolower($key), $search);
            }, ARRAY_FILTER_USE_KEY);
        }

        $this->beQuiet();
        $this->outputFormatted($result);
        $this->io()->eol();
    }
}
