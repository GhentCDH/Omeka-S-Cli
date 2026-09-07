<?php

namespace OSC\Settings;

/**
 * The outcome of importing one {@see SettingsDocument}: whether it was applied, plus the log of what
 * happened. Returning this instead of writing to the console lets {@see SettingsImport} stay
 * decoupled from the CLI — the command renders the entries through its own verbosity-aware helpers.
 */
class SettingsImportReport
{
    /** @var LogEntry[] */
    private array $entries = [];

    private bool $applied = false;

    public function add(Severity $severity, string $message): void
    {
        $this->entries[] = new LogEntry($severity, $message);
    }

    public function markApplied(): void
    {
        $this->applied = true;
    }

    /** Whether the file was applied (as opposed to skipped). */
    public function isApplied(): bool
    {
        return $this->applied;
    }

    /** @return LogEntry[] */
    public function entries(): array
    {
        return $this->entries;
    }
}
