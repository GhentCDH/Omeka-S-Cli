<?php
namespace OSC\Commands\User;

use InvalidArgumentException;
use Omeka\Entity\ApiKey;

class ListApiKeysCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:list-api-keys', 'List API keys for a user');
        $this->argument('<user>', 'User ID or email address');
        $this->optionJson();
        $this->optionTable();
        $this->optionCsv();
    }

    public function execute(string $user): void
    {
        $api = $this->getOmekaInstance()->getApi();
        $em = $this->getOmekaInstance()->getServiceManager()->get('Omeka\EntityManager');

        $userRepresentation = $this->findUser($user, $api);
        if (!$userRepresentation) {
            throw new InvalidArgumentException("User not found: {$user}");
        }

        $userEntity = $userRepresentation->getEntity();

        /** @var ApiKey[] $apiKeys */
        $apiKeys = $em->getRepository(ApiKey::class)->findBy(['owner' => $userEntity]);

        if (empty($apiKeys)) {
            $this->info("No API keys found for user '{$userRepresentation->email()}'.", true);
            return;
        }

        $keyData = [];
        foreach ($apiKeys as $apiKey) {
            $keyData[] = [
                'key_id' => $apiKey->getId(),
                'key_label' => $apiKey->getLabel(),
                'user_id' => $userEntity->getId(),
                'user_email' => $userEntity->getEmail(),
            ];
        }

        $this->info("Found " . count($keyData) . " API key(s) for user '{$userRepresentation->email()}'.", true);

        $format = $this->getOutputFormat('table');
        $this->outputFormatted($keyData, $format);
    }
}