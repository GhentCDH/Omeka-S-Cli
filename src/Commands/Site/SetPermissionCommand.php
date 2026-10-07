<?php
namespace OSC\Commands\Site;

class SetPermissionCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:set-permission', "Give a user a role on a site (adds the permission or changes its role)");
        $this->argument('<site>', 'Site ID or slug');
        $this->argument('<user>', 'User ID or email address');
        $this->argument('<role>', 'Site role (viewer, editor, admin)');
    }

    public function execute(string $site, string $user, string $role): void
    {
        $this->assertValidSiteRole($role);

        $siteRepresentation = $this->requireSite($site);
        $userRepresentation = $this->requireUser($user, $this->getOmekaInstance()->getApi());

        $email = $userRepresentation->email();
        $slug = $siteRepresentation->slug();

        $current = $this->currentRole($siteRepresentation, $userRepresentation->id());
        if ($current === $role) {
            $this->warn("User '{$email}' already has the {$role} role on site '{$slug}'.", true);
            return;
        }

        $this->siteApi()->setPermission($siteRepresentation, $userRepresentation->id(), $role);

        $this->ok(
            $current === null
                ? "Granted '{$email}' the {$role} role on site '{$slug}'."
                : "Changed the role of '{$email}' on site '{$slug}' from {$current} to {$role}.",
            true
        );
    }
}
