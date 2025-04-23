<?php
namespace OSC\Repository\Theme;

use OSC\Cache;
use OSC\Cache\CacheInterface;
use OSC\Repository\AbstractRepository;


/**
 * @template T of ThemeDetails
 * @template-extends AbstractRepository<ThemeDetails>
 */
class OmekaDotOrg extends AbstractRepository
{
    private const API_ENDPOINT = 'https://omeka.org/add-ons/json/s_theme.json';

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

    /**
     * @return ThemeDetails[]
     */
    public function list():  array
    {
        $cacheKey = $this->getId().'.themes';
        $output = $this->cache->get($cacheKey);

        if (!$output) {
            $output = [];

            // Get the JSON data from the Omeka.org module list
            $json = file_get_contents(self::API_ENDPOINT);
            if (!$json) {
                throw new \Exception("Failed to fetch data from " . self::API_ENDPOINT);
            }
            $data = json_decode($json, true) ?? [];

            // Create the modules array
            foreach ($data as $item) {
                $versions = [];
                foreach ($item['versions'] as $version => $versionInfo) {
                    $versions[$version] = new ThemeVersion(
                        $version,
                        $versionInfo['created'],
                        $versionInfo['download_url'],
                    );
                }

                $latestVersion = $item['latest_version'];
                $link = preg_replace('/\/releases.*/', '', $versions[$latestVersion]->downloadUrl);
                $moduleId = strtolower($item['dirname']);

                $output[$moduleId] = new ThemeDetails(
                    name: $item['dirname'],
                    dirname: $item['dirname'],
                    latestVersion: $latestVersion,
                    versions: $versions,
                    link: $link,
                    owner: $item['owner']
                );
            }
            $this->cache->set($cacheKey, $output);
        }

        return $output;
    }
}