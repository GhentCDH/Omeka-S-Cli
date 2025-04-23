<?php
namespace OSC\Repository\Module;

use OSC\Cache;
use OSC\Cache\CacheInterface;
use OSC\Repository\AbstractRepository;

/**
 * @template T of ModuleDetails
 * @template-extends AbstractRepository<ModuleDetails>
 */
class DanielKM extends AbstractRepository
{
    private const API_ENDPOINT = 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv';

    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = Cache::getCache();
    }

    public function getId(): string
    {
        return 'daniel-km';
    }

    public function getDisplayName(): string
    {
        return 'Daniel-KM (git)';
    }

    /**
     * @return ModuleDetails[]
     */
    public function list(): array
    {
        $cacheKey = $this->getId().'.modules';
        $modules = $this->cache->get($cacheKey);

        if ( !$modules ) {
            $modules = [];

            // Get the CSV data from the Daniel-KM module list
            $csv = file_get_contents(self::API_ENDPOINT);
            if (!$csv) {
                throw new \HttpRequestException("Failed to fetch data from " . self::API_ENDPOINT);
            }
            $csv = array_map('str_getcsv', explode(PHP_EOL, $csv));
            $header = array_shift($csv);
            $data = [];
            foreach ($csv as $row) {
                if (count($row) === count($header)) {
                    $data[] = array_combine($header, $row);
                }
            }

            // Create the modules array
            foreach ($data as $row) {
                # skip unreleased modules
                if (empty($row['Last released zip'])) {
                    continue;
                }
                # skip if directory name is missing
                if (empty($row['Directory name'])) {
                    continue;
                }
                $dirname = $row['Directory name'];
                $moduleId = strtolower($row['Directory name']);
                $version = $this->extractVersionNumberFromUrl($row['Last released zip']);
                $version = $row['Last version'];

                $versions = [ $version => new ModuleVersion(
                        version: $version,
                        created: $row['Last update'],
                        downloadUrl: $row['Last released zip'],
                    )
                ];
                $modules[$moduleId] = new ModuleDetails(
                    name: $row['Name'],
                    dirname: $dirname,
                    latestVersion: $version,
                    versions: $versions,
                    description: $this->emptyToNull($row['Description']),
                    link: $this->emptyToNull($row['Url']),
                    owner: $this->emptyToNull($row['Author']),
                    tags: $this->emptyToNull($row['Tags']),
                    dependencies: explode(',', $row['Dependencies']), // todo: trim
                );
            }
            $this->cache->set($cacheKey, $modules);
        }

        return $modules;
    }

    protected function emptyToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
    protected function extractModuleNameFromUrl(string $url): string
    {
        // Extract the last part of the URL
        $filename = basename($url);

        // Remove the extension
        $filenameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename);

        // explode('-',$filename) to get the module name
        $parts = explode('-', $filenameWithoutExtension);
        return $parts[0];
    }

    protected function extractVersionNumberFromUrl(string $url): string
    {
        // Extract the last part of the URL
        $filename = basename($url);

        // Remove the extension
        $filenameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename);

        // explode('-',$filename) to get the module version
        $parts = explode('-', $filenameWithoutExtension);
        $parts = array_splice($parts, 1);
        return join('-', $parts);
    }
}