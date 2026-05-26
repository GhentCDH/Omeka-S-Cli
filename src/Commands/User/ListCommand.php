<?php
namespace OSC\Commands\User;

use Omeka\Api\Representation\UserRepresentation;

class ListCommand extends AbstractUserCommand
{
    public function __construct()
    {
        parent::__construct('user:list', 'List all users');
        $this->optionJson();
        $this->optionCSV();
        $this->optionTable();
    }

    public function execute(): void
    {
        $api = $this->getOmekaInstance()->getApi();

        $format = $this->getOutputFormat('table');

        /** @var UserRepresentation[] $users */
        $users = $api->search('users', [])->getContent();

        if (empty($users)) {
            $this->warn("No users found.", true);
            $this->outputFormatted([], $format);
            return;
        }

        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'id'           => $user->id(),
                'display_name' => $user->name(),
                'email'        => $user->email(),
                'is_active'    => $user->isActive(),
                'role'         => $user->role(),
            ];
        }

        $this->info("Found " . count($data) . " user(s).", true);
        $this->outputFormatted($data, $format);
    }
}
