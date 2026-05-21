<?php
namespace OSC\Commands\User;

return [
    new AddCommand(),
    new UpdateCommand(),
    new UpdatePasswordCommand(),
    new ExistsCommand(),
    new CreateApiKeyCommand(),
    new DeleteApiKeyCommand(),
    new ListApiKeysCommand(),
    new ListCommand(),
    new DeleteCommand(),
    new EnableCommand(),
    new DisableCommand(),
];
