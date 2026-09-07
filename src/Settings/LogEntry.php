<?php

namespace OSC\Settings;

/**
 * One line in a {@see SettingsImportReport}: a severity and a human-readable message. The caller
 * decides how (and whether) to render it, so the applier stays free of any output concern.
 */
final class LogEntry
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
    ) {
    }
}
