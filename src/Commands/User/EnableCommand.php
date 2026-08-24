<?php
namespace OSC\Commands\User;


class EnableCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:enable', 'Enable (activate) a user');
        $this->argument('<user>', 'User ID or email address');
        $this->optionIgnoreNotFound();
    }

    public function execute(string $user, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        $userRepresentation = $this->requireUser($user, $api, $ignoreNotFound);
        if (!$userRepresentation) {
            return;
        }

        // Check if user is already active
        if ($userRepresentation->isActive()) {
            $this->warn("User '{$userRepresentation->email()}' is already enabled.", true);
            return;
        }

        // Enable user
        $this->getOmekaInstance()->elevatePrivileges();

        $userEntity = $userRepresentation->getEntity();
        $userEntity->setIsActive(true);
        $em->persist($userEntity);
        $em->flush();

        $this->ok("User '{$userRepresentation->email()}' enabled.", true);
    }
}
