<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractUserCommand extends AbstractCommand
{
    /**
     * Resolve the user a command was asked to act on.
     *
     * @param string $api             Omeka API manager
     * @param string $user            User ID or email address
     * @param bool   $ignoreNotFound  Report a missing user instead of failing on it
     *
     * @return UserRepresentation The resolved user (always; absence throws or is reported and aborts)
     *
     * @throws InvalidArgumentException If the user does not exist
     */
    protected function requireUser(string $user, $api, bool $ignoreNotFound = false): UserRepresentation
    {
        $userRepresentation = $this->findUser($user, $api);
        if ($userRepresentation) {
            return $userRepresentation;
        }

        $this->skipMissing(new InvalidArgumentException("User not found: {$user}"), $ignoreNotFound);
    }

    protected function findUser(string $userIdentifier, $api): ?UserRepresentation
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
