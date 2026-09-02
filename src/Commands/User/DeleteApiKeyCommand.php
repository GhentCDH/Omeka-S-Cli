<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Entity\ApiKey;

class DeleteApiKeyCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:delete-api-key', 'Delete an API key for a user');
        $this->argument('<user>', 'User ID or email address');
        $this->optionIgnoreNotFound('user or API key');
        $this->argument('<label>', 'Label for the API key');
    }

    public function execute(string $user, string $label, ?bool $ignoreNotFound = false): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        // Find user by ID or email
        $userEntity = $this->requireUser($user, $api, $ignoreNotFound)->getEntity();

        // Find API key by label
        $apiKey = $em->getRepository(ApiKey::class)->findOneBy([
            'owner' => $userEntity,
            'label' => $label
        ]);

        if (!$apiKey) {
            $this->skipMissing(
                new InvalidArgumentException(
                    "API key with label '{$label}' not found for user '{$userEntity->getEmail()}'."
                ),
                $ignoreNotFound
            );
        }

        // Delete API key
        $this->getOmekaInstance()->elevatePrivileges();
        $em->remove($apiKey);
        $em->flush();

        $this->ok("API key '{$label}' deleted for user '{$userEntity->getEmail()}'.", true);
    }
}

