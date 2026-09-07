<?php

namespace OSC\Settings;

/**
 * The severity of a {@see LogEntry} in a {@see SettingsImportReport}.
 *
 * Kept deliberately small: the import applier only ever reports non-fatal detail (fatal cases throw,
 * so the run aborts). The command maps each severity onto its own output helpers.
 */
enum Severity: string
{
    case Debug = 'debug';
    case Warning = 'warning';
}
