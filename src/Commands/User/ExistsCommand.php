<?php
namespace OSC\Commands\User;

use ErrorException;
use InvalidArgumentException;
use Omeka\Api\Representation\UserRepresentation;
use OSC\Commands\AbstractCommand;
use Omeka\Entity\User;
use OSC\Exceptions\WarningException;

class ExistsCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('user:exists', 'Check if a user exists');
        $this->argument('<email>', 'Email address of the user');
    }

    public function execute(string $email): int
    {
        $api = $this->getOmekaInstance()->getApi();

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$email}");
        }

        // Check if user exists
        $userExists = $api->search('users', ['email' => $email])->getTotalResults() > 0;
        if ($userExists) {
            $this->info("User with email '{$email}' exists.", true);
            return 0;
        }

        $this->info("User with email '{$email}' not found.", true);
        return 1;
    }
}