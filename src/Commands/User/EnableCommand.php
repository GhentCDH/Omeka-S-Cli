<?php
namespace OSC\Commands\User;

use InvalidArgumentException;

class EnableCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:enable', 'Enable (activate) a user in Omeka S');
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

        $this->ok("User '{$userRepresentation->email()}' has been enabled.", true);
    }
}
