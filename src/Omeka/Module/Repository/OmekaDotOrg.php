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
                    $versions[] = new ModuleVersion(
                        $version,
                        $versionData['created'],
                        $versionData['download_url'],
                    );
                }
                $link = preg_replace('/\/releases.*/', '', $versions[0]->downloadUrl);
                $this->modules[$module['id']] = new ModuleRepresentation(
                    id: $module['dirname'],
                    latestVersion: $module['latest_version'],
                    versions: $versions,
                    link: $link,
                    owner: $module['owner']
                );
            }
        }
        return $this->modules;
    }
}