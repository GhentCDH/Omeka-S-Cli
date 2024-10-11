<?php
namespace OSC\Repository\Module;

use OSC\Cache;
use OSC\Cache\CacheInterface;

class OmekaDotOrg extends AbstractModuleRepository implements ModuleRepositoryInterface
{
    private const MODULE_API_URL = 'https://omeka.org/add-ons/json/s_module.json';

    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = Cache::getCache();
    }

    public function getId(): string
    {
        return 'omeka.org';
    }

    public function getDisplayName(): string
    {
        return 'Omeka.org';
    }

    protected function getModules():  ?array
    {
        $cacheKey = $this->getId().'.modules';
        $modules = $this->cache->get($cacheKey);

        if (!$modules) {
            $modules = [];

            // Get the JSON data from the Omeka.org module list
            $json = file_get_contents(self::MODULE_API_URL);
            if (!$json) {
                return null;
            }
            $data = json_decode($json, true) ?? [];

            // Create the modules array
            foreach ($data as $module) {
                $versions = [];
                foreach ($module['versions'] as $version => $versionData) {
                    $versions[$version] = new ModuleVersion(
                        $version,
                        $versionData['created'],
                        $versionData['download_url'],
                    );
                }

                $latestVersion = $module['latest_version'];
                $link = preg_replace('/\/releases.*/', '', $versions[$latestVersion]->downloadUrl);
                $moduleId = strtolower($module['dirname']);

                $modules[$moduleId] = new ModuleRepresentation(
                    id: $moduleId,
                    dirname: $module['dirname'],
                    latestVersion: $latestVersion,
                    versions: $versions,
                    link: $link,
                    owner: $module['owner']
                );
            }
            $this->cache->set($cacheKey, $modules);
        }

        return $modules;
    }
}