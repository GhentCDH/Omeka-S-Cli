<?php
namespace OSC\Repository\Module;

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
        $json = file_get_contents(self::API_ENDPOINT);
        if (!$json) {
            throw new \HttpRequestException("Failed to fetch data from " . self::API_ENDPOINT);
        }
        $data = json_decode($json, true) ?? [];

        // validate json structure
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
        }
        if (empty($data)) {
            return $output;
        }

        $firstItem = current($data);
        if (!isset($firstItem['dirname'], $firstItem['latest_version'], $firstItem['versions'], $firstItem['owner'])) {
            throw new \UnexpectedValueException("Invalid data structure from " . self::API_ENDPOINT);
        }

        // Create the modules array
        foreach ($data as $module) {
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