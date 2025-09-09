<?php
namespace OSC\Commands\Core;

use Exception;
use Omeka\Mvc\Status;
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
        parent::__construct('core:version', 'Get the downloaded Omeka S version');
        $this->option('-i --installed', 'Get the installed Omeka S version.', 'boolval', false);
        $this->optionJson();
    }

    public function execute(?bool $installed): void
    {
        $serviceManager = $this->getOmekaInstance(false)->getServiceManager();

        /** @var Status $status */
        $status = $serviceManager->get('Omeka\Status');
        if ($installed) {
            if (!$status->isInstalled()) {
                throw new Exception('Omeka S is not installed.');
            }
            $version = $status->getInstalledVersion();
        } else {
            $version = $status->getVersion();
        }

        if ($this->values()['json'] ?? false) {
            $this->outputFormatted(['version' => $version], 'json');
        } else {
            echo $version;
            $this->io()->eol();
        }
    }
}
