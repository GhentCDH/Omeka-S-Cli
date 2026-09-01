<?php
namespace OSC\Commands\User;


class DisableCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:disable', 'Disable (deactivate) a user');
        $this->argument('<user>', 'User ID or email address');
        $this->optionIgnoreNotFound('user');
    }

    public function execute(string $user, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        $userRepresentation = $this->requireUser($user, $api, $ignoreNotFound);
        if (!$userRepresentation) {
            return;
        }

        // Check if user is already inactive
        if (!$userRepresentation->isActive()) {
            $this->warn("User '{$userRepresentation->email()}' is already disabled.", true);
            return;
        }

        // Disable user
        $this->getOmekaInstance()->elevatePrivileges();

        $userEntity = $userRepresentation->getEntity();
        $userEntity->setIsActive(false);
        $em->persist($userEntity);
        $em->flush();

        $this->ok("User '{$userRepresentation->email()}' disabled.", true);
    }
}
