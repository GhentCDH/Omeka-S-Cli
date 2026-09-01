<?php
namespace OSC\Commands\User;

use OSC\Commands\AbstractCommand;

class DeleteCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:delete', 'Delete a user from Omeka S');
        $this->argument('<user>', 'User ID or email address');
        $this->optionIgnoreNotFound('user');
    }

    public function execute(string $user, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();

        $userRepresentation = $this->requireUser($user, $api, $ignoreNotFound);
        if (!$userRepresentation) {
            return;
        }

        $userId = $userRepresentation->id();

        // Delete user
        $this->getOmekaInstance()->elevatePrivileges();
        $api->delete('users', [ 'id' => $userId ]);

        $this->ok("User '{$userRepresentation->email()}' deleted.", true);
    }
}

