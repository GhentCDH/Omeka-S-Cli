<?php

use Ahc\Cli\Input\Command;

/** @var Command[] $commands */
$commands = [];
$commands = [...$commands, ...require(__DIR__ . '/Module/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/Theme/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/Config/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/Core/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/User/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/ResourceTemplates/Index.php')];
$commands = [...$commands, ...require(__DIR__ . '/Vocabulary/Index.php')];

return $commands;
