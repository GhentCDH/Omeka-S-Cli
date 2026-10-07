<?php
namespace OSC\Commands\Site;

class ListPermissionsCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:list-permissions', 'List the user permissions of a site');
        $this->argument('<site>', 'Site ID or slug');
        $this->optionJson();
        $this->optionCSV();
        $this->optionTable();
    }

    public function execute(string $site): void
    {
        $format = $this->getOutputFormat('table');

        $siteRepresentation = $this->requireSite($site);
        $permissions = $this->siteApi()->getPermissions($siteRepresentation);

        if (empty($permissions)) {
            $this->warn("Site '{$siteRepresentation->slug()}' has no user permissions.", true);
            $this->outputFormatted([], $format);
            return;
        }

        $data = [];
        foreach ($permissions as $permission) {
            $data[] = [
                'user_id'      => $permission['user']->id(),
                'email'        => $permission['user']->email(),
                'display_name' => $permission['user']->name(),
                'role'         => $permission['role'],
            ];
        }

        $this->info("Found " . count($data) . " permission(s).", true);
        $this->outputFormatted($data, $format);
    }
}
