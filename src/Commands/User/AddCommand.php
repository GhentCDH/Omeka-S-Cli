<?php
namespace OSC\Commands\User;

use ErrorException;
use InvalidArgumentException;
use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;
use Omeka\Entity\User;

class AddCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('user:add', 'Add a new user');
        $this->argument('<email>', 'Email address of the user');
        $this->argument('<name>', 'Display name of the user');
        $this->argument('<role>', 'Role of the user (global_admin, site_admin, editor, reviewer, author, researcher)');
        $this->argument('[password]', 'Password for the user (optional)');
        $this->option('--inactive -i', 'Set the user inactive (default: active)');
        $this->optionJson();
    }

    public function execute(string $email, string $name, string $role, ?string $password = null, ?bool $isInactive = false, ?bool $json = false): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }

        // Validate role
        $validRoles = ['global_admin', 'site_admin', 'editor', 'reviewer', 'author', 'researcher'];
        if (!in_array($role, $validRoles, true)) {
            throw new InvalidArgumentException("Invalid role: {$role}. Valid roles are: " . implode(', ', $validRoles));
        }

        // Check if user exists
        $userExists = $api->search('users', ['email' => $email])->getTotalResults() > 0;
        if ($userExists) {
            throw new InvalidArgumentException("User with email '{$email}' already exists.");
        }

        // Prepare user data
        $userData = [
            'o:email' => $email,
            'o:name' => $name,
            'o:role' => $role,
            'o:is_active' => !$isInactive,
        ];

        // Remove null values
        $userData = array_filter($userData, fn($value) => $value !== null);

        // Create user
        $this->getOmekaInstance()->elevatePrivileges();
        $response = $api->create('users', $userData);
        if (!$response) {
            throw new ErrorException("Failed to create user '{$email}'.");
        }
        /** @var UserRepresentation $userRepresentation */
        $userRepresentation = $response->getContent();

        // Set password if provided
        if ($password !== null) {
            $user = $response->getContent();
            /** @var User $userEntity */
            $userEntity = $user->getEntity();
            $userEntity->setPassword($password);
            $em->flush();
        }

        if ($json) {
            $userEntry = [
                'id' => $userRepresentation->id(),
                'display_name' => $userRepresentation->name(),
                'email' => $userRepresentation->email(),
                'is_active' => $userRepresentation->isActive(),
                'role' => $userRepresentation->role()
            ];
            $this->outputFormatted($userEntry, 'json');
        }

        $this->ok("User '{$email}' successfully created with role '{$role}'.", true);
    }
}