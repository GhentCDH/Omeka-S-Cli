<?php
namespace OSC\Commands\User;

use ErrorException;
use InvalidArgumentException;
use Omeka\Entity\ApiKey;
use Omeka\Entity\User;

class CreateApiKeyCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:createApiKey', 'Create an API key for a user in Omeka S');
        $this->argument('<user>', 'User ID or email address');
        $this->argument('<label>', 'Label for the API key');
        $this->optionJson();
        $this->optionTable();
    }

    public function execute(string $user, string $label): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        // Find user by ID or email
        $userEntity = $this->findUser($user, $api)?->getEntity();
        if (!$userEntity) {
            throw new InvalidArgumentException("User not found: {$user}");
        }

        // Validate label
        if (empty(trim($label))) {
            throw new InvalidArgumentException("Label cannot be empty");
        }

        // Check if API key with same label already exists for this user
        $existingKey = $em->getRepository(ApiKey::class)->findOneBy([
            'owner' => $userEntity,
            'label' => $label
        ]);

        if ($existingKey) {
            throw new InvalidArgumentException("API key with label '{$label}' already exists for user '{$userEntity->getEmail()}'.");
        }

        // Create API key
        $this->getOmekaInstance()->elevatePrivileges();

        $apiKey = new ApiKey();
        $apiKey->setId();
        $apiKey->setOwner($userEntity);
        $apiKey->setLabel($label);
        $keyCredential = $apiKey->setCredential();
        $em->persist($apiKey);
        $em->flush();

        // Prepare output data
        $keyData = [
            'key_id' => $apiKey->getId(),
            'key_credential' => $keyCredential,
            'key_label' => $apiKey->getLabel(),
            'user_id' => $userEntity->getId(),
            'user_email' => $userEntity->getEmail(),
            'user_name' => $userEntity->getName(),
        ];

        // Output based on format
        $format = $this->getOutputFormat('table');
        $this->outputFormatted($format == "table" ? [ $keyData ] : $keyData, $format);

        $this->ok("API key '{$label}' successfully created for user '{$userEntity->getEmail()}'.", true);
    }
}
