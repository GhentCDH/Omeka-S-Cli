<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractUserCommand extends AbstractCommand
{
    /**
     * Resolve the user a command was asked to act on.
     *
     * @param string     $user           User ID or email address
     * @param ApiManager $api            Omeka API instance
     * @param bool       $ignoreNotFound Report a missing user instead of failing on it
     *
     * @return UserRepresentation The resolved user (always; absence throws or is reported and aborts)
     *
     * @throws InvalidArgumentException If the user does not exist
     */
    protected function requireUser(string $user, ApiManager $api, bool $ignoreNotFound = false): UserRepresentation
    {
        $userRepresentation = $this->findUser($user, $api);
        if ($userRepresentation) {
            return $userRepresentation;
        }

        $this->skipMissing(new InvalidArgumentException("User not found: {$user}"), $ignoreNotFound);
    }

    /**
     * Reject a role the Omeka ACL does not know. Modules can register their own roles (e.g. Guest
     * adds 'guest'), so the valid set depends on the active modules.
     *
     * @throws InvalidArgumentException If the role is unknown
     */
    protected function assertValidRole(string $role): void
    {
        $acl = $this->getOmekaInstance()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->hasRole($role)) {
            throw new InvalidArgumentException("Invalid role: {$role}. Valid roles are: " . implode(', ', $acl->getRoles()));
        }
    }

    protected function findUser(string $userIdentifier, ApiManager $api): ?UserRepresentation
    {
        // Try to find user by ID or email
        if (is_numeric($userIdentifier)) {
            try {
                $result = $api->read('users', (int)$userIdentifier);
                return $result->getContent();
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (filter_var($userIdentifier, FILTER_VALIDATE_EMAIL)) {
            $search = $api->search('users', ['email' => $userIdentifier]);
            return $search->getTotalResults() > 0 ? $search->getContent()[0] : null;
        }

        return null;
    }
}
