<?php
namespace OSC\Commands\Core;

return [
    new VersionCommand(),
    new StatusCommand(),
    new DownloadCommand(),
    new UpdateCommand(),
    new InstallCommand(),
    new MigrateCommand(),
];
