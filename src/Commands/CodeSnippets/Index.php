<?php
/**
 * Commands index for CodeSnippets.
 *
 * PHP version 8.1
 *
 * @category Command
 * @package  OSC\Commands\CodeSnippets
 * @author   Erseco <author@example.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/erseco/Omeka-S-Cli
 */

namespace OSC\Commands\CodeSnippets;

return [
    new ListCommand(),
    new ShowCommand(),
    new ExportCommand(),
    new ImportCommand(),
    new ActivateCommand(),
    new DeactivateCommand(),
    new DeleteCommand(),
];
