<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Entity\User;

class UpdatePasswordCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:update-password', 'Update the password of a user');
        $this->argument('<user>', 'User ID or email address');
        $this->argument('<password>', 'New password for the user');
    }

    public function execute(string $user, string $password): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        $userRepresentation = $this->findUser($user, $api);
        if (!$userRepresentation) {
            throw new InvalidArgumentException("User not found: {$user}");
        }

        if (empty(trim($password))) {
            throw new InvalidArgumentException("Password cannot be empty.");
        }

        $this->getOmekaInstance()->elevatePrivileges();

        /** @var User $userEntity */
        $userEntity = $userRepresentation->getEntity();
        $userEntity->setPassword($password);
        $em->flush();

        $this->ok("Password for user '{$userRepresentation->email()}' updated.", true);
    }
}
