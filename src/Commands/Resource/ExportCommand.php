<?php
namespace OSC\Commands\Resource;

use Exception;
use Omeka\Api\Exception\NotFoundException;
use OSC\Helper\Path;
use OSC\Helper\Slug;
use OSC\Resource\Exporter;
use OSC\Resource\ResourceType;

class ExportCommand extends AbstractResourceCommand
{
    public function __construct(ResourceType $type)
    {
        parent::__construct($type, 'export', "Export {$type->label()}s as portable JSON-LD");
        $this->argument('[ids...]', "IDs of the {$type->label()}s to export (default: all that match the filters)");
        $this->optionFilters();
        $this->option('-o --output', 'File to write one exported resource to (default: standard output)');
        $this->option('--output-dir', 'Directory to write one file per resource to');
        $this->option(
            '--reference-by',
            'Property that identifies records in references and in the o-ref:id of each resource (default: dcterms:title)'
        );
        $prefix = $type->commandPrefix();
        $this->usage(
            "{$prefix}:export [IDS...] [options]<eol/>"
            . '<eol/>Examples:<eol/><eol/>'
            . "* Print one {$type->label()}:<eol/>"
            . "{$prefix}:export 12<eol/>"
            . '<eol/>'
            . "* Export all {$type->label()}s of a class into a directory, referenced by identifier:<eol/>"
            . "{$prefix}:export --class dctype:StillImage --output-dir ./content --reference-by dcterms:identifier<eol/>"
        );
    }

    public function execute(?array $ids = null, ?string $output = null, ?string $outputDir = null, ?string $referenceBy = null): void
    {
        if ($output !== null && $outputDir !== null) {
            throw new Exception('Use either --output or --output-dir.');
        }
        $instance = $this->getOmekaInstance();
        $api = $instance->getApi();

        $resources = [];
        if ($ids) {
            foreach ($ids as $id) {
                try {
                    $resources[] = $api->read($this->type->value, (int) $id)->getContent();
                } catch (NotFoundException) {
                    throw new Exception(ucfirst($this->type->label()) . " {$id} not found.");
                }
            }
        } else {
            $resources = $api->search($this->type->value, $this->query($instance))->getContent();
        }
        if (!$resources) {
            $this->warn("No {$this->plural()} to export.", true);
            return;
        }
        if (count($resources) > 1 && $outputDir === null) {
            throw new Exception("Several {$this->plural()} match; use --output-dir.");
        }

        $exporter = new Exporter($this->catalog($instance), $referenceBy ?? 'dcterms:title');
        $dir = $outputDir === null ? null : Path::toAbsolutePath($outputDir, $this->getCwd());
        if ($dir !== null && !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception("Could not create {$dir}.");
        }

        foreach ($resources as $resource) {
            $result = $exporter->export(json_decode(json_encode($resource), true), $this->type);
            foreach ($result->warnings() as $warning) {
                $this->warn($warning, true);
            }
            $json = json_encode($result->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            if ($dir !== null) {
                $slug = Slug::text((string) $resource->displayTitle());
                file_put_contents("{$dir}/{$resource->id()}" . ($slug === '' ? '' : "-{$slug}") . '.jsonld', $json);
            } elseif ($output !== null) {
                file_put_contents(Path::toAbsolutePath($output, $this->getCwd()), $json);
                $this->ok(ucfirst($this->type->label()) . " {$resource->id()} exported to {$output}.", true);
            } else {
                $this->io()->writer()->raw($json);
            }
        }
        if ($dir !== null) {
            $this->ok(sprintf('Exported %d %s to %s.', count($resources), count($resources) === 1 ? $this->type->label() : $this->plural(), $outputDir), true);
        }
    }
}
