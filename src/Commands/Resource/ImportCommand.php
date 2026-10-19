<?php
namespace OSC\Commands\Resource;

use Exception;
use InvalidArgumentException;
use OSC\Exceptions\WarningException;
use OSC\Helper\ItemImportConfig;
use OSC\Helper\MediaConfig;
use OSC\Helper\ResourceFetcher;
use OSC\Resource\ImportResult;
use OSC\Resource\MediaIngesters;
use OSC\Resource\ResourceImporter;
use OSC\Resource\ResourceType;

class ImportCommand extends AbstractResourceCommand
{
    public function __construct(ResourceType $type)
    {
        $label = $type->label();
        parent::__construct($type, 'import', "Import one {$label} from a JSON-LD file or URL");
        $this->argument('[source]', "JSON-LD file or URL holding one {$label}");
        $this->option('--config', "JSON file or URL describing the import: {\"source\", \"title\"" . ($type === ResourceType::Item ? ', "media"' : '') . '}');
        if ($type === ResourceType::Item) {
            $this->option(
                '-m --media [spec...]',
                'Media to attach, as ingester=source[;key=value...] (e.g. upload=./photo.jpg;title=Front or '
                . 'youtube=https://youtu.be/ID;start=30) or a JSON media object (repeatable)'
            );
            $this->option(
                '-s --site [slug...]',
                "Site (slug or title) to assign the item to, replacing the file's o:site; no match → the default site (repeatable)"
            );
        }
        $this->option('--identify-by', "Property whose value identifies an existing {$label} (default: the file's o-ref:id, else the title)");
        $this->optionDryRun();
        $this->optionJson();
        $prefix = $type->commandPrefix();
        $this->usage(
            "{$prefix}:import SOURCE [options]<eol/>"
            . "{$prefix}:import --config CONFIG [options]<eol/>"
            . '<eol/>An existing ' . $label . ' (matched by o-ref:id, else by title) is skipped.<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . "* Import an exported {$label}:<eol/>"
            . "{$prefix}:import ./content/12-mona-lisa.jsonld<eol/>"
            . ($type === ResourceType::Item
                ? '<eol/>* Import an item into the site "collections" only:<eol/>'
                    . "{$prefix}:import mona-lisa.jsonld --site collections<eol/>"
                    . '<eol/>* Import an item with a local image and a IIIF image:<eol/>'
                    . "{$prefix}:import mona-lisa.jsonld --media upload=./mona-lisa.jpg --media iiif=https://example.org/iiif/ml/info.json<eol/>"
                    . '<eol/>* Import an item with a titled YouTube video that starts at 30 s:<eol/>'
                    . "{$prefix}:import talk.jsonld --media 'youtube=https://youtu.be/ID;start=30;title=The talk'<eol/>"
                : '')
            . '<eol/>* Check what would happen:<eol/>'
            . "{$prefix}:import mona-lisa.jsonld --dry-run<eol/>"
        );
    }

    public function execute(
        ?string $source = null,
        ?string $config = null,
        ?array $media = null,
        ?array $site = null,
        ?string $identifyBy = null,
        ?bool $dryRun = false,
        ?bool $json = false
    ): void {
        if (($source === null) === ($config === null)) {
            throw new InvalidArgumentException('Give a source or --config.');
        }
        if ($config !== null) {
            $configPath = $this->resolveSource($config);
            $data = ResourceFetcher::fetchJson($configPath);
            if (!is_array($data)) {
                throw new InvalidArgumentException("Invalid configuration file: {$config}");
            }
            if ($site) {
                throw new InvalidArgumentException("Give the sites in the configuration file ('sites') or with --site, not both.");
            }
            $entry = ItemImportConfig::fromArray($data)->withSources(fn(string $s) => $this->resolveSource($s, $configPath));
        } else {
            $sites = array_values(array_filter((array) $site, 'is_string'));
            $entry = ItemImportConfig::fromArray(array_filter([
                'source' => $source,
                'media' => array_map([$this, 'mediaSpec'], array_values(array_filter((array) $media, 'is_string'))),
                'sites' => $sites ?: null,
            ], fn($value) => $value !== null))->withSources(fn(string $s) => $this->resolveSource($s));
        }

        $document = ResourceFetcher::fetchJson($entry->source());
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException("{$entry->source()} must hold one JSON-LD resource (an object).");
        }

        $instance = $this->getOmekaInstance();
        if ($entry->media()) {
            MediaIngesters::useLocalFiles($instance->getServiceManager());
        }
        $label = basename($entry->source()) . ($entry->title() !== null ? " (\"{$entry->title()}\")" : '');
        $services = $instance->getServiceManager();
        $importer = new ResourceImporter(
            $instance->getApi(),
            $this->catalog($instance),
            null,
            fn(array $media) => MediaIngesters::check($services, $media)
        );
        $result = $importer->import($document, $this->type, $label, $entry->media(), $identifyBy, (bool) $dryRun, $entry->sites());

        if ($json) {
            $this->outputFormatted($result->toArray(), 'json');
            return;
        }
        foreach ($result->warnings() as $warning) {
            $this->warn($warning, true);
        }
        $name = ucfirst($this->type->label()) . " '" . ($result->title() ?? $entry->title() ?? basename($entry->source())) . "'";
        match ($result->status()) {
            ImportResult::CREATED => $this->ok("{$name} created (id {$result->id()}).", true),
            ImportResult::WOULD_CREATE => $this->reportDryRun("would create {$name}"),
            ImportResult::EXISTS => throw new WarningException("{$name} already exists (id {$result->id()}); skipped."),
            ImportResult::AMBIGUOUS => throw new WarningException("{$name} matches {$result->count()} existing {$this->plural()}; skipped."),
            default => throw new Exception("Unexpected import result '{$result->status()}'."),
        };
    }

    /** A --media value (see MediaConfig::parseSpec()) as a media config array. */
    private function mediaSpec(string $spec): array
    {
        $data = MediaConfig::parseSpec($spec);
        MediaConfig::fromArray($data); // validate early
        return $data;
    }
}
