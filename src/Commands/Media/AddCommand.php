<?php
namespace OSC\Commands\Media;

use Exception;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\ValidationException;
use OSC\Commands\AbstractCommand;
use OSC\Helper\ErrorStoreFormatter;
use OSC\Helper\MediaConfig;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use OSC\Resource\MediaIngesters;

class AddCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('media:add', 'Add a media to an item');
        $this->argument('<item>', 'ID of the item');
        $this->option('--ingester', 'Media ingester, e.g. upload, url, html, iiif, youtube (see media:ingesters)');
        $this->option('--source', 'What to ingest: a local path or URL (upload), a URL (url, iiif, oembed, youtube) or HTML (html)');
        $this->option('--title', 'Title of the media');
        $this->option('--alt-text', 'Alternative text of the media');
        $this->optionJson();
        $this->usage(
            'media:add ITEM --ingester INGESTER --source SOURCE [options]<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . '* Add a local image:<eol/>'
            . 'media:add 12 --ingester upload --source ./mona-lisa.jpg --alt-text "Portrait of a woman"<eol/>'
            . '<eol/>'
            . '* Add a IIIF image:<eol/>'
            . 'media:add 12 --ingester iiif --source https://example.org/iiif/ml/info.json<eol/>'
        );
    }

    public function execute(
        string $item,
        ?string $ingester = null,
        ?string $source = null,
        ?string $title = null,
        ?string $altText = null,
        ?bool $json = false
    ): void {
        $media = MediaConfig::fromArray(array_filter(
            ['ingester' => $ingester, 'source' => $source, 'title' => $title, 'altText' => $altText],
            fn($value) => $value !== null
        ));
        if ($media->isFile() && !ResourceFetcher::isUrl($media->source())) {
            $media = $media->withSource(Path::toAbsolutePath($media->source(), $this->getCwd()));
        }

        $instance = $this->getOmekaInstance();
        $api = $instance->getApi();
        try {
            $api->read('items', (int) $item);
        } catch (NotFoundException) {
            throw new Exception("Item {$item} not found.");
        }
        MediaIngesters::useLocalFiles($instance->getServiceManager());
        try {
            $created = $api->create('media', ['o:item' => ['o:id' => (int) $item]] + $media->toApiPayload())->getContent();
        } catch (ValidationException $e) {
            throw new Exception("Could not add the media to item {$item}:\n" . ErrorStoreFormatter::format($e->getErrorStore()->getErrors()));
        }

        if ($json) {
            $this->outputFormatted(['id' => $created->id(), 'item' => (int) $item, 'ingester' => $media->ingester()], 'json');
            return;
        }
        $this->ok("Media {$created->id()} added to item {$item}.", true);
    }
}
