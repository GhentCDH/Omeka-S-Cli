<?php

/**
 * Generate the command reference fragments for the documentation site.
 *
 * The reference (synopsis, arguments, options, defaults) is read from the command definitions, so it
 * can never drift from the code. Each command gets a markdown fragment that the hand-written pages in
 * docs/ embed with a snippet include (`--8<-- "build/docs/commands/module-download.md"`); the options
 * every command shares are written once, to build/docs/global-options.md.
 *
 * The script also checks coverage: every registered command must be included by some page in docs/,
 * so a new command without documentation fails the docs build.
 *
 * Usage:
 *   php scripts/generate-command-reference.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ahc\Cli\Input\Argument;
use Ahc\Cli\Input\Command;
use Ahc\Cli\Input\Option;
use Ahc\Cli\Input\Parameter;

const OSC_DOCS_DIR = __DIR__ . '/../docs';
const OSC_REFERENCE_DIR = __DIR__ . '/../build/docs';

/**
 * The fragment path of a command, relative to the repository root (as used in the snippet includes).
 */
function osc_reference_path(string $commandName): string
{
    return 'build/docs/commands/' . str_replace(':', '-', $commandName) . '.md';
}

/**
 * Whether an option is a flag (takes no value).
 *
 * adhocore/cli lets any option without a `<value>` / `[value]` placeholder take an optional value, so
 * the commands mark their flags: a boolean default, the 'boolval' filter, a `--no-`/`--with-` name, or
 * (like -v) an event handler instead of a filter.
 */
function osc_is_flag(Command $command, Option $option): bool
{
    if (str_contains($option->raw(), '<') || str_contains($option->raw(), '[')) {
        return false;
    }
    if ($option->bool() || is_bool($option->default())) {
        return true;
    }
    $filter = (new ReflectionProperty(Parameter::class, 'filter'))->getValue($option);
    if ($filter === 'boolval') {
        return true;
    }
    $events = (new ReflectionProperty(Command::class, '_events'))->getValue($command);

    return $filter === null && isset($events[$option->attributeName()]);
}

function osc_option_label(Command $command, Option $option): string
{
    $names = array_unique([$option->short(), $option->long()]);
    $label = implode(', ', array_map(fn ($name) => "`{$name}`", $names));
    if (!osc_is_flag($command, $option)) {
        $placeholder = preg_match('/([<\[][^>\]]+[>\]])/', $option->raw(), $match) ? $match[1] : '<value>';
        $label = rtrim($label, '`') . ' ' . $placeholder . '`';
    }

    return $label;
}

function osc_argument_label(Argument $argument): string
{
    $name = $argument->name() . ($argument->variadic() ? '...' : '');

    return $argument->required() ? "<{$name}>" : "[{$name}]";
}

function osc_default(Parameter $parameter): string
{
    $default = $parameter->default();
    // a --no-x flag defaults to true: that is how adhocore/cli models it, not something to show
    if (($parameter instanceof Option && $parameter->bool()) || $default === null || $default === '' || $default === [] || $default === false) {
        return '';
    }

    return '`' . (is_scalar($default) ? var_export($default, true) : json_encode($default)) . '`';
}

/** Escape a description for use in a markdown table cell. */
function osc_cell(string $text): string
{
    return str_replace(['|', "\n"], ['\|', ' '], $text);
}

/**
 * @param Option[] $options
 */
function osc_options_table(Command $command, array $options): string
{
    $rows = ["| Option | Description | Default |", "| --- | --- | --- |"];
    foreach ($options as $option) {
        $rows[] = sprintf(
            '| %s | %s | %s |',
            osc_option_label($command, $option),
            osc_cell($option->desc()),
            osc_default($option)
        );
    }

    return implode("\n", $rows) . "\n";
}

/**
 * @param string[] $globalOptionNames
 */
function osc_command_fragment(Command $command, array $globalOptionNames): string
{
    $arguments = $command->allArguments();
    // the global options are documented once; options for internal use are not documented
    $options = array_filter(
        $command->allOptions(),
        fn (Option $option) => !in_array($option->name(), $globalOptionNames, true)
            && !str_starts_with($option->desc(), 'Internal:')
    );

    $synopsis = implode(' ', array_filter([
        'omeka-s-cli',
        $command->name(),
        $options ? '[options]' : '',
        ...array_map('osc_argument_label', $arguments),
    ]));

    $out = "### `{$command->name()}`\n\n";
    $out .= ucfirst($command->desc()) . "\n\n";
    $out .= "```text\n{$synopsis}\n```\n\n";

    if ($arguments) {
        $out .= "| Argument | Description | Default |\n| --- | --- | --- |\n";
        foreach ($arguments as $argument) {
            $out .= sprintf('| `%s` | %s | %s |', osc_argument_label($argument), osc_cell($argument->desc()), osc_default($argument)) . "\n";
        }
        $out .= "\n";
    }

    if ($options) {
        $out .= osc_options_table($command, $options);
    }

    return $out;
}

/** @var Command[] $commands */
$commands = require __DIR__ . '/../src/Commands/Index.php';

// the global options are the ones every command has
$globalOptions = null;
foreach ($commands as $command) {
    $globalOptions = $globalOptions === null
        ? $command->allOptions()
        : array_intersect_key($globalOptions, $command->allOptions());
}
$globalOptionNames = array_map(fn (Option $option) => $option->name(), $globalOptions);

if (!is_dir(OSC_REFERENCE_DIR . '/commands') && !mkdir(OSC_REFERENCE_DIR . '/commands', 0775, true)) {
    fwrite(STDERR, "Could not create " . OSC_REFERENCE_DIR . "/commands\n");
    exit(1);
}
/** Write a file, or stop: a stale fragment must not end up in the site. */
function osc_write(string $file, string $content): void
{
    if (@file_put_contents($file, $content) === false) {
        fwrite(STDERR, "Could not write {$file}\n");
        exit(1);
    }
}

foreach (glob(OSC_REFERENCE_DIR . '/commands/*.md') as $stale) {
    if (!@unlink($stale)) {
        fwrite(STDERR, "Could not remove {$stale}\n");
        exit(1);
    }
}

osc_write(OSC_REFERENCE_DIR . '/global-options.md', osc_options_table(reset($commands), $globalOptions));
foreach ($commands as $command) {
    osc_write(__DIR__ . '/../' . osc_reference_path($command->name()), osc_command_fragment($command, $globalOptionNames));
}

// coverage: every command must be included by a page
$pages = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(OSC_DOCS_DIR, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'md') {
        $pages .= file_get_contents($file->getPathname());
    }
}
$missing = array_filter(
    $commands,
    fn (Command $command) => !str_contains($pages, '"' . osc_reference_path($command->name()) . '"')
);

printf("Generated the reference for %d commands in build/docs/.\n", count($commands));
if ($missing) {
    fwrite(STDERR, "These commands are not documented (no page includes their reference):\n");
    foreach ($missing as $command) {
        fwrite(STDERR, sprintf("  %s -> --8<-- \"%s\"\n", $command->name(), osc_reference_path($command->name())));
    }
    exit(1);
}
