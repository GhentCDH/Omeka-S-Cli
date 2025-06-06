<?php
namespace OSC\Commands\Core;

use Exception;
use Omeka\Settings\Settings;
use OSC\Commands\Module\AbstractModuleCommand;
use OSC\Commands\Module\FormattersTrait;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\FileUtils;

class VersionCommand extends AbstractModuleCommand
{
    use FormattersTrait;

    public function __construct()
    {
        parent::__construct('core:version', 'Get the current Omeka S version');
    }

    public function execute(?string $versionNumber): void
    {
        $serviceManager = $this->getOmekaInstance()->getServiceManager();
        $settings = $serviceManager->get('Omeka\Settings');
        $currentVersion = $settings->get('version');

        echo $currentVersion;
        $this->io()->eol();
    }
}
