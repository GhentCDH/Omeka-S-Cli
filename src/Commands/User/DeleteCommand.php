<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use OSC\Commands\AbstractCommand;

class DeleteCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:delete', 'Delete a user from Omeka S');
        $this->argument('<user>', 'User ID or email address');
    }

    public function execute(string $user): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        // Try to find user by ID or email
        $userRepresentation = $this->findUser($user, $api);
        if (!$userRepresentation) {
            throw new InvalidArgumentException("User not found: {$user}");
        }

        $userId = $userRepresentation->id();

        // Delete user
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('users', [ 'id' => $userId ]);

        $this->ok("User '{$userRepresentation->email()}' deleted successfully.", true);
    }
}

