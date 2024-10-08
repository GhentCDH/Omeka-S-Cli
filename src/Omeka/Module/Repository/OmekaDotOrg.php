<?php
namespace OSC\Omeka\Module\Repository;

use OSC\Omeka\Module\ModuleRepresentation;
use OSC\Omeka\Module\ModuleVersion;

class OmekaDotOrg extends AbstractRepository implements RepositoryInterface
{
    private const MODULE_API_URL = 'https://omeka.org/add-ons/json/s_module.json';

    private ?array $modules = null;

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
        if ( !$this->modules ) {
            $data = json_decode(file_get_contents(self::MODULE_API_URL), true) ?? [];
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

                $this->modules[$module['id']] = new ModuleRepresentation(
                    id: $moduleId,
                    dirname: $module['dirname'],
                    latestVersion: $latestVersion,
                    versions: $versions,
                    link: $link,
                    owner: $module['owner']
                );
            }
        }
        return $this->modules;
    }
}