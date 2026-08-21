<?php
namespace OSC\Repository\Module;

use OSC\Helper\ResourceFetcher;
use OSC\Repository\AbstractRepository;

/**
 * @template-extends AbstractRepository<ModuleDetails>
 */
class OmekaDotOrg extends AbstractRepository
{
    private const API_ENDPOINT = 'https://omeka.org/add-ons/json/s_module.json';

    public function getId(): string
    {
        return 'omeka.org';
    }

    public function getDisplayName(): string
    {
        return 'Omeka.org';
    }

    /**
     * @return ModuleDetails[]
     */
    protected function entries(): array
    {
        $output = [];

        // Get the JSON data from the Omeka.org module list
        $data = ResourceFetcher::fetchJson(self::API_ENDPOINT) ?? [];

        // validate json structure
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
        }
        if (empty($data)) {
            return $output;
        }

        // Check data structure
        $firstItem = current($data);
        foreach (['dirname', 'latest_version', 'versions', 'owner'] as $key) {
            if (!array_key_exists($key, $firstItem)) {
                throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
            }
        }

        // Create the modules array
        foreach ($data as $module) {
            // an add-on without a published release can not be used
            if (empty($module['latest_version']) || !isset($module['versions'][$module['latest_version']])) {
                continue;
            }

            $versions = [];
            foreach (($module['versions'] ?? []) as $version => $versionData) {
                $versions[$version] = new ModuleVersion(
                    $version,
                    $versionData['created'],
                    $versionData['download_url'],
                    $versionData['omeka_version_constraint'] ?? null,
                );
            }

            $latestVersion = $module['latest_version'];
            $link = preg_replace('/\/releases.*/', '', $versions[$latestVersion]->downloadUrl);
            $moduleId = strtolower($module['dirname']);

            $output[$moduleId] = new ModuleDetails(
                name: $module['dirname'],
                dirname: $module['dirname'],
                latestVersion: $latestVersion,
                versions: $versions,
                link: $link,
                owner: $module['owner']
            );
        }

        return $output;
    }
}