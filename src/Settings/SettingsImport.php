<?php

namespace OSC\Settings;

use Exception;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Manager as ApiManager;
use OSC\Helper\ModuleSettingsMapper;
use OSC\Omeka\ModuleApi;

/**
 * Apply one {@see SettingsDocument} to a live instance — the import counterpart of
 * {@see SettingsExport}, and the analogue of the blueprint applier.
 *
 * Everything that depends on the live instance lives here: choosing the settings service, resolving a
 * scoped file's target (site slug / user email) to a local id, checking module and version, and
 * applying (or pruning) the keys. The command owns only option parsing, source collection and the
 * surrounding transaction; it hands each document to {@see self::import()}.
 *
 * The class is decoupled from the CLI: instead of writing to the console it returns a
 * {@see SettingsImportReport} of {@see LogEntry} items, which the command renders through its own
 * verbosity-aware helpers. Only non-fatal detail goes into the report; a strict mismatch throws so
 * the whole run aborts (and the command rolls back). Everything Omeka comes from the service manager.
 *
 * By default only the keys present in a file are written (merge/upsert); with prune enabled it also
 * removes keys in scope that the file no longer contains.
 */
class SettingsImport
{
    /** Core keys that must not be overwritten casually — the installed version drives migrations. */
    private const PROTECTED_CORE_KEYS = ['version'];

    /**
     * The Omeka DBAL connection. Left untyped on purpose: the PHAR build prefixes the Doctrine
     * namespace (Doctrine is not in the scoper's exclude list, unlike OSC/Omeka/Laminas), so a
     * `Doctrine\DBAL\Connection` type hint would be rewritten to `_OmekaSCli\Doctrine\DBAL\Connection`
     * and reject the unprefixed instance Omeka returns at runtime. The commands treat it the same way.
     *
     * @var \Doctrine\DBAL\Connection
     */
    private $connection;
    private ApiManager $api;
    private ModuleApi $moduleApi;

    public function __construct(
        private ServiceManager $serviceManager,
        private ModuleSettingsMapper $mapper,
        private SettingsScope $scope,
        private string $omekaVersion,
        private bool $prune = false,
        private bool $strict = false,
        private bool $force = false,
        private bool $dryRun = false,
    ) {
        $this->connection = $serviceManager->get('Omeka\Connection');
        $this->api = $serviceManager->get('Omeka\ApiManager');
        // A fresh wrapper (not ModuleApi::getInstance) so unit tests are not affected by its static cache.
        $this->moduleApi = new ModuleApi($serviceManager);
    }

    /**
     * Import a single document, returning a report of what happened. The report's applied flag is
     * true when the file was applied, false when it was skipped.
     */
    public function import(string $label, SettingsDocument $file): SettingsImportReport
    {
        $report = new SettingsImportReport();
        $type = $file->type();

        // Scope gate: skip whole files whose type or module the import does not cover.
        if (!$this->scope->includesType($type) || !$this->scope->includesModule($file->module())) {
            $report->add(Severity::Debug, "{$label}: skipped, out of scope.");
            return $report;
        }

        // Version / installation checks (warn, or error under strict).
        if (!$this->checkOwner($label, $file, $report)) {
            return $report;
        }

        // Resolve the settings service and, for scoped files, the target id.
        $service = $this->serviceManager->get($type->serviceId());
        $targetId = null;
        if ($type->isScoped()) {
            $targetId = $this->resolveTarget($label, $file, $report);
            if ($targetId === null) {
                return $report;
            }
            $service->setTargetId($targetId);
        }

        // Apply.
        $applied = 0;
        foreach ($file->settings() as $key => $value) {
            $key = strtolower((string) $key);
            if (!$this->scope->includesKey($key)) {
                continue;
            }
            if (!$type->isScoped() && in_array($key, self::PROTECTED_CORE_KEYS, true) && !$this->force) {
                $report->add(
                    Severity::Warning,
                    "Skipping protected key '{$key}' in '{$label}' (use --force to import it)."
                );
                continue;
            }
            if ($this->dryRun) {
                $report->add(Severity::Debug, "{$label}: would set {$key}");
            } else {
                $service->set($key, $value);
            }
            $applied++;
        }

        $deleted = 0;
        if ($this->prune) {
            $fileKeys = array_map('strtolower', array_map('strval', array_keys($file->settings())));
            $deleted = $this->pruneScope($label, $file, $targetId, $fileKeys, $service, $report);
        }

        $verb = $this->dryRun ? 'would be applied' : 'applied';
        $summary = "{$label}: {$applied} key(s) {$verb}";
        if ($this->prune) {
            $pruneVerb = $this->dryRun ? 'would be pruned' : 'pruned';
            $summary .= ", {$deleted} {$pruneVerb}";
        }
        $report->add(Severity::Debug, $summary);

        $report->markApplied();

        return $report;
    }

    /**
     * Verify the file's owner (module installed + version, or core version). Returns false to skip.
     */
    private function checkOwner(string $label, SettingsDocument $file, SettingsImportReport $report): bool
    {
        if ($file->isCore()) {
            $expected = $file->omekaVersion();
            if ($expected !== null && $expected !== $this->omekaVersion) {
                $this->mismatch(
                    "'{$label}' was exported from Omeka {$expected}, this instance is {$this->omekaVersion}.",
                    $report
                );
            }
            return true;
        }

        $module = $file->module();
        if (!$this->moduleApi->isActive($module)) {
            $this->mismatch("'{$label}' targets module '{$module}', which is not active on this instance. Skipping.", $report);
            return false;
        }

        $expected = $file->moduleVersion();
        if ($expected !== null) {
            // Look up the installed version, tolerating a lookup failure (isActive() already passed).
            $actual = null;
            try {
                $db = $this->moduleApi->getModule($module)->getDb();
                $actual = is_array($db) ? ($db['version'] ?? null) : null;
            } catch (Exception $e) {
                $actual = null;
            }
            // Report the mismatch outside the try, so strict's exception is not swallowed.
            if ($actual !== null && $actual !== $expected) {
                $this->mismatch("'{$label}' was exported from {$module} {$expected}, this instance has {$actual}.", $report);
            }
        }

        return true;
    }

    /**
     * Resolve a scoped file's target to a local numeric id, preferring the symbolic key.
     *
     * @return int|null The resolved id, or null to skip the file
     */
    private function resolveTarget(string $label, SettingsDocument $file, SettingsImportReport $report): ?int
    {
        $type = $file->type();

        if ($type === SettingType::SiteSetting && $file->site() !== null) {
            $matches = $this->api->search('sites', ['slug' => $file->site()])->getContent();
            if ($matches) {
                return $matches[0]->id();
            }
            $this->mismatch("'{$label}' targets site '{$file->site()}', which does not exist here. Skipping.", $report);
            return null;
        }

        if ($type === SettingType::UserSetting && $file->user() !== null) {
            $matches = $this->api->search('users', ['email' => $file->user()])->getContent();
            if ($matches) {
                return $matches[0]->id();
            }
            $this->mismatch("'{$label}' targets user '{$file->user()}', which does not exist here. Skipping.", $report);
            return null;
        }

        // Fall back to the numeric id only when no symbolic key is present.
        $resource = $type->targetResource();
        $id = $file->targetId();
        if ($id !== null && $resource !== null) {
            try {
                return $this->api->read($resource, $id)->getContent()->id();
            } catch (Exception $e) {
                // fall through to the skip below
            }
        }

        $this->mismatch("'{$label}' has no resolvable target ({$resource}). Skipping.", $report);
        return null;
    }

    /**
     * Delete keys owned by this file's module/scope that the file no longer contains.
     *
     * @param string[] $fileKeys keys present in the file (lowercased)
     * @return int number of keys deleted
     */
    private function pruneScope(
        string $label,
        SettingsDocument $file,
        ?int $targetId,
        array $fileKeys,
        $service,
        SettingsImportReport $report,
    ): int {
        $type = $file->type();

        if ($type->isScoped()) {
            $existing = $this->connection->fetchFirstColumn(
                "SELECT id FROM {$type->tableName()} WHERE {$type->targetColumn()} = ?",
                [$targetId]
            );
        } else {
            $existing = $this->connection->fetchFirstColumn("SELECT id FROM {$type->tableName()}");
        }

        $module = strtolower($file->module());
        $deleted = 0;
        foreach ($existing as $key) {
            if (strtolower($this->mapper->moduleForKey($key)) !== $module) {
                continue;
            }
            if (!$this->scope->includesKey($key)) {
                continue; // an excluded key is left alone, never pruned
            }
            if (in_array(strtolower($key), $fileKeys, true)) {
                continue;
            }
            if (!$type->isScoped() && in_array(strtolower($key), self::PROTECTED_CORE_KEYS, true) && !$this->force) {
                continue;
            }
            if ($this->dryRun) {
                $report->add(Severity::Debug, "{$label}: would delete {$key}");
            } else {
                $service->set($key, null); // null deletes in Omeka's settings service
            }
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Report a mismatch as an error (strict) or a warning entry.
     */
    private function mismatch(string $message, SettingsImportReport $report): void
    {
        if ($this->strict) {
            throw new Exception($message);
        }
        $report->add(Severity::Warning, $message);
    }
}
