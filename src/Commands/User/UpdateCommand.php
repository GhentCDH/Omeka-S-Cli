<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Api\Representation\UserRepresentation;

class UpdateCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:update', 'Update user details');
        $this->argument('<user>', 'User ID or email address');
        $this->option('--name', 'New display name');
        $this->option('--email', 'New email address');
        $this->option('--role', 'New role (global_admin, site_admin, editor, reviewer, author, researcher)');
        $this->option('--activate', 'Activate the user', 'boolval', false);
        $this->option('--deactivate', 'Deactivate the user', 'boolval', false);
        $this->optionIgnoreNotFound();
        $this->optionJson();
    }

    public function execute(
        string $user,
        ?string $name = null,
        ?string $email = null,
        ?string $role = null,
        ?bool $activate = false,
        ?bool $deactivate = false,
        ?bool $json = false,
        ?bool $ignoreNotFound = false
    ): void {
        if ($activate && $deactivate) {
            throw new InvalidArgumentException("Cannot use --activate and --deactivate together.");
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }

        if ($role !== null) {
            $validRoles = ['global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher'];
            if (!in_array($role, $validRoles, true)) {
                throw new InvalidArgumentException("Invalid role: {$role}. Valid roles are: " . implode(', ', $validRoles));
            }
        }

        $api = $this->getOmekaInstance()->getApi();

        $userRepresentation = $this->requireUser($user, $api, $ignoreNotFound);
        if (!$userRepresentation) {
            return;
        }

        $updateData = [
            'o:name' => $name ?? $userRepresentation->name(),
            'o:email' => $email ?? $userRepresentation->email(),
            'o:role' => $role ?? $userRepresentation->role(),
            'o:is_active' => $activate ? true : ($deactivate ? false : $userRepresentation->isActive()),
        ];

        $this->getOmekaInstance()->elevatePrivileges();
        $response = $api->update('users', $userRepresentation->id(), $updateData);

        /** @var UserRepresentation $updated */
        $updated = $response->getContent();

        if ($json) {
            $this->outputFormatted([
                'id' => $updated->id(),
                'display_name' => $updated->name(),
                'email' => $updated->email(),
                'is_active' => $updated->isActive(),
                'role' => $updated->role(),
            ], 'json');
        }

        $this->ok("User '{$updated->email()}' updated.", true);
    }
}
