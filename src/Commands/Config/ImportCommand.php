<?php

namespace OSC\Commands\Config;

use InvalidArgumentException;
use OSC\Commands\AbstractCommand;
use OSC\Settings\SettingsImport;
use OSC\Settings\SettingsScope;
use OSC\Settings\SettingsSerializer;
use OSC\Settings\Severity;
use OSC\Exceptions\WarningException;
use OSC\Helper\ModuleSettingsMapper;

/**
 * Import settings from JSONC files produced by {@see ExportCommand}.
 *
 * The source may be a single file, a URL, or a directory (every *.jsonc / *.json in it is imported).
 * Parsing and structural validation live in {@see \OSC\Settings\SettingsDocument} /
 * {@see SettingsSerializer}; everything that depends on the live instance lives in
 * {@see SettingsImport}. This command owns only option parsing, source collection and the
 * surrounding transaction.
 */
class ImportCommand extends AbstractCommand
{
    public function __construct()
    {
        parent::__construct('config:import', 'Import instance settings from JSONC files');
        $this->argument('<source>', 'File, URL or directory to import from');
        $this->option('--module', 'Only import settings of this module (or "core")', 'strval');
        $this->option('--scope', 'Which settings to import: setting, site_setting, user_setting or all', 'strval', 'all');
        $this->option('--prune', 'Delete existing keys in scope that the file does not contain', 'boolval', false);
        $this->option('--strict', 'Treat version/target mismatches as errors instead of warnings', 'boolval', false);
        $this->option('--force', 'Import protected keys (e.g. core "version") too', 'boolval', false);
        $this->option('--no-transaction', 'Apply each setting immediately instead of in one revertible transaction', 'boolval', true);
        $this->optionDryRun();
        $this->usage(
            'config:import SOURCE [--module=ID] [--scope=all|setting|site_setting|user_setting] '
            . '[--prune] [--strict] [--force] [--no-transaction] [--dry-run]<eol/>'
            . '<eol/>Examples:<eol/><eol/>'
            . '* Import every file in a directory:<eol/>'
            . 'config:import ./config<eol/>'
            . '<eol/>'
            . '* Import only the Log module settings from a directory:<eol/>'
            . 'config:import ./config --module=Log<eol/>'
            . '<eol/>'
            . '* Preview importing a single file:<eol/>'
            . 'config:import ./config/Log.setting.jsonc --dry-run<eol/>'
        );
    }

    public function execute(
        string $source,
        ?string $module = null,
        ?string $scope = 'all',
        ?bool $prune = false,
        ?bool $strict = false,
        ?bool $force = false,
        // adhocore strips the "no-" prefix: the option is named "transaction", defaults true, and
        // --no-transaction sets it false.
        ?bool $transaction = true
    ): void {
        $dryRun = $this->isDryRun();
        if ($dryRun) {
            $this->reportDryRun('Previewing settings import; no changes will be written.');
        }

        try {
            $settingsScope = SettingsScope::fromOptions($scope, $module);
        } catch (InvalidArgumentException $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }

        $sources = $this->collectSources($source);
        if (!$sources) {
            throw new WarningException("No settings files found at '{$source}'.");
        }

        $omekaInstance = $this->getOmekaInstance();
        $serviceManager = $omekaInstance->getServiceManager();
        $connection = $serviceManager->get('Omeka\Connection');
        $moduleApi = $omekaInstance->getModuleApi();
        $moduleIds = array_map(static fn($m) => $m->getId(), $moduleApi->getModules());

        $import = new SettingsImport(
            $serviceManager,
            new ModuleSettingsMapper($moduleIds),
            $settingsScope,
            $this->getOmekaVersion(),
            (bool) $prune,
            (bool) $strict,
            (bool) $force,
            $dryRun,
        );

        // Apply the whole run atomically: a failure in any file rolls back every earlier change.
        // (--dry-run writes nothing; --no-transaction opts out.) All settings tables are InnoDB and
        // the settings services share this connection, so set() writes participate in the transaction.
        $useTransaction = !$dryRun && $transaction;

        $serializer = new SettingsSerializer();
        $imported = 0;
        if ($useTransaction) {
            $connection->beginTransaction();
        }
        try {
            foreach ($sources as $path) {
                $file = $serializer->read($path);
                $report = $import->import(basename($path), $file);
                foreach ($report->entries() as $entry) {
                    match ($entry->severity) {
                        Severity::Debug => $this->debug($entry->message, true),
                        Severity::Warning => $this->warn($entry->message, true),
                    };
                }
                if ($report->isApplied()) {
                    $imported++;
                }
            }
            if ($useTransaction) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            // On rollback the in-memory settings cache is stale, but the CLI process exits now.
            if ($useTransaction && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }

        $verb = $dryRun ? 'Would import' : 'Imported';
        $this->ok("{$verb} {$imported} of " . count($sources) . ' settings file(s).', true);
    }

    /**
     * Resolve the source into a list of file paths (or the single source for a file/URL).
     *
     * @return string[]
     */
    private function collectSources(string $source): array
    {
        if (is_dir($source)) {
            $files = glob(rtrim($source, DIRECTORY_SEPARATOR) . '/*.{jsonc,json}', GLOB_BRACE) ?: [];
            sort($files);
            return $files;
        }

        return [$source];
    }
}
