<?php
namespace OSC\Repository\Theme;

use OSC\Helper\ResourceFetcher;
use OSC\Repository\AbstractRepository;


/**
 * @template T of ThemeDetails
 * @template-extends AbstractRepository<ThemeDetails>
 */
class OmekaDotOrg extends AbstractRepository
{
    private const API_ENDPOINT = 'https://omeka.org/add-ons/json/s_theme.json';

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
    public function entries():  array
    {
        $output = [];

        // Get the JSON data from the Omeka.org theme list
        $data = ResourceFetcher::fetchJson(self::API_ENDPOINT) ?? [];

        // Check data structure
        $firstItem = current($data);
        foreach (['dirname', 'latest_version', 'versions', 'owner'] as $key) {
            if (!array_key_exists($key, $firstItem)) {
                throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
            }
        }


        // Create the modules array
        foreach ($data as $item) {
            // an add-on without a published release can not be used
            if (empty($item['latest_version']) || !isset($item['versions'][$item['latest_version']])) {
                continue;
            }

            $versions = [];
            foreach ($item['versions'] as $version => $versionInfo) {
                $versions[$version] = new ThemeVersion(
                    $version,
                    $versionInfo['created'],
                    $versionInfo['download_url'],
                    $versionInfo['omeka_version_constraint'] ?? null
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

        return $output;
    }
}