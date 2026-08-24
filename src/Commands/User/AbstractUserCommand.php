<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractUserCommand extends AbstractCommand
{
    public function optionIgnoreNotFound(): static
    {
        $this->option(
            '--ignore-not-found',
            'Do nothing if the user does not exist (default: throw an error)',
            'boolval',
            false
        );

        return $this;
    }

    /**
     * Resolve the user a command was asked to act on.
     *
     * @param string $api             Omeka API manager
     * @param string $user            User ID or email address
     * @param bool   $ignoreNotFound  Report a missing user instead of failing on it
     *
     * @return UserRepresentation|null Null only when the user is absent and may be ignored
     *
     * @throws InvalidArgumentException If the user does not exist
     */
    protected function requireUser(string $user, $api, bool $ignoreNotFound = false): ?UserRepresentation
    {
        $userRepresentation = $this->findUser($user, $api);
        if ($userRepresentation) {
            return $userRepresentation;
        }

        if ($ignoreNotFound) {
            $this->warn("User not found: {$user}. Nothing to do.", true);
            return null;
        }

        throw new InvalidArgumentException("User not found: {$user}");
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
