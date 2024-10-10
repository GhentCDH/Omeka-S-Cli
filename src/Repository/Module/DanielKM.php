<?php
namespace OSC\Repository\Module;

class DanielKM extends AbstractModuleRepository implements ModuleRepositoryInterface
{
    private const MODULE_API_URL = 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv';

    private ?array $modules = null;

    public function getId(): string
    {
        return 'daniel-km';
    }

    public function getDisplayName(): string
    {
        return 'Daniel-KM (git)';
    }

    protected function getModules():  ?array
    {
        if ( !$this->modules ) {
            $csv = file_get_contents(self::MODULE_API_URL);
            if (!$csv) {
                return null;
            }
            $csv = array_map('str_getcsv', explode(PHP_EOL, $csv));
            $header = array_shift($csv);
            $data = [];
            foreach ($csv as $row) {
                if (count($row) === count($header)) {
                    $data[] = array_combine($header, $row);
                }
            }

            foreach ($data as $row) {
                # skip unreleased modules
                if (empty($row['Last released zip'])) {
                    continue;
                }
                # get module id
                $dirname = $this->extractModuleNameFromUrl($row['Last released zip']);
                $moduleId = strtolower($dirname);
                $version = $this->extractVersionNumberFromUrl($row['Last released zip']);
                $version = $row['Last version'];

                $versions = [ $version => new ModuleVersion(
                        version: $version,
                        created: $row['Last update'],
                        downloadUrl: $row['Last released zip'],
                    )
                ];
                $this->modules[$moduleId] = new ModuleRepresentation(
                    id: $moduleId,
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
        }
        return $this->modules;
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
        $moduleName = $parts[0];

        return $moduleName;
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
        $version = join('-', $parts);

        return $version;
    }
}