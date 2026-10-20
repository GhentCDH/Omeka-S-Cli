<?php
namespace OSC\Commands\Media;

use OSC\Commands\AbstractCommand;
use OSC\Resource\MediaIngesters;

class IngestersCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('media:ingesters', 'List the media ingesters (the ways media can be added)');
        $this->optionJson();
        $this->optionCSV();
        $this->usage(
            'media:ingesters [options]<eol/>'
            . '<eol/>From the CLI, the upload ingester takes a local path or URL.<eol/>'
        );
    }

    public function execute(): void
    {
        $format = $this->getOutputFormat('table');
        $instance = $this->getOmekaInstance();
        $this->outputFormatted(MediaIngesters::registered($instance->getServiceManager()), $format);
    }
}
