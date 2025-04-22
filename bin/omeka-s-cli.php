#!/usr/bin/env php
<?php
namespace OSC;

use OSC\Cli\Application as CliApplication;
use OSC\Manager\Module\Manager as ModuleRepositoryManager;

require_once __DIR__ . '/../vendor/autoload.php';

// set error reporting
error_reporting(E_ERROR);

// init module repositories
$repositories = [
  new Repository\Module\OmekaDotOrg(),
  new Repository\Module\DanielKM(),
];

$moduleRepository = ModuleRepositoryManager::getInstance();
foreach ($repositories as $repository) {
  $moduleRepository->addRepository($repository);
}

// init application
$app = new CliApplication('Omeka-S-Cli', '0.3.2');
$app->logo("
   ___            _            ___    ___ _ _ 
  / _ \ _ __  ___| |____ _    / __|  / __| (_)
 | (_) | '  \/ -_) / / _` | = \__ \ | (__| | |
  \___/|_|_|_\___|_\_\__,_|   |___/  \___|_|_|
");
$app->handle($_SERVER['argv']);