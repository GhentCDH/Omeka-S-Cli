<?php
namespace OSC\Commands\User;

use Omeka\Api\Representation\UserRepresentation;

class ListCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:list', 'List all users in Omeka S');
        $this->optionJson();
        $this->optionTable();
    }

    public function execute(): void
    {
        $api = $this->getOmekaInstance()->getApi();
        
        // Get all users
        /** @var UserRepresentation[] $users */
        $users = $api->search('users', [])->getContent();
        
        if (empty($users)) {
            $this->info("No users found.", true);
            return;
        }

        // Prepare user data
        $userDataJson = [];
        $userDataTable = [];

        foreach ($users as $user) {
            $userEntry = [
                'id' => $user->id(),
                'display_name' => $user->name(),
                'email' => $user->email(),
                'is_active' => $user->isActive(),
                'role' => $user->role()
            ];
            $userDataJson[] = $userEntry;
            $userDataTable[] = [
                ...$userEntry,
                'role' => $user->displayRole(),
                'is_active' => $user->isActive() ? 'Yes' : 'No',
            ];
        }

        // Output based on format
        $format = $this->getOutputFormat('table');

        $this->info("Found " . count($userDataJson) . " user(s).", true);

        if ($format === 'json') {
            $this->outputFormatted($userDataJson, 'json');
        } else {
            $this->outputFormatted($userDataTable, 'table');
        }

    }
}
