<?php
namespace OSC\Commands\User;

use InvalidArgumentException;

class DisableCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:disable', 'Disable (deactivate) a user');
        $this->argument('<user>', 'User ID or email address');
    }

    public function execute(string $user): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        // Find user by ID or email
        $userRepresentation = $this->findUser($user, $api, $em);
        if (!$userRepresentation) {
            throw new InvalidArgumentException("User not found: {$user}");
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

        $this->ok("User '{$userRepresentation->email()}' has been disabled.", true);
    }
}
