<?php
namespace OSC\Commands\User;

use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;

abstract class AbstractUserCommand extends AbstractCommand
{
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
