<?php
namespace OSC\Commands\Site;

use InvalidArgumentException;

class DeletePermissionCommand extends AbstractSiteCommand
{
    public function __construct()
    {
        parent::__construct('site:delete-permission', "Remove a user's permission on a site");
        $this->argument('<site>', 'Site ID or slug');
        $this->argument('<user>', 'User ID or email address');
        $this->optionIgnoreNotFound('site, user or permission');
    }

    public function execute(string $site, string $user, ?bool $ignoreNotFound = false): void
    {
        $siteRepresentation = $this->requireSite($site, $ignoreNotFound);
        $userRepresentation = $this->requireUser($user, $this->getOmekaInstance()->getApi(), $ignoreNotFound);

        $email = $userRepresentation->email();
        $slug = $siteRepresentation->slug();

        if ($this->currentRole($siteRepresentation, $userRepresentation->id()) === null) {
            $this->skipMissing(
                new InvalidArgumentException("User '{$email}' has no permission on site '{$slug}'"),
                $ignoreNotFound
            );
        }

        $this->siteApi()->removePermission($siteRepresentation, $userRepresentation->id());

        $this->ok("Removed the permission of '{$email}' on site '{$slug}'.", true);
    }
}
