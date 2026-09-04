<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * The name, e-mail and password of a user
 */
class UserConfig
{
    public function __construct(
        private string $name,
        private string $email,
        private string $password,
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid e-mail address: {$email}");
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
