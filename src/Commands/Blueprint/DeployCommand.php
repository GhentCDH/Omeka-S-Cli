<?php
namespace OSC\Commands\Blueprint;

use Exception;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintApplier;
use OSC\Blueprint\BlueprintLoader;
use OSC\Blueprint\BlueprintValidator;
use OSC\Helper\DatabaseConfig;
use OSC\Helper\Path;
use OSC\Helper\UserConfig;

class DeployCommand extends AbstractBlueprintCommand
{
    /** All phases, in deploy order. */
    private const PHASES = ['core', 'modules', 'themes', 'vocabularies', 'resourceTemplates', 'users', 'settings'];

    /** Phases handled in the current process (no active-module services required). */
    private const IN_PROCESS_PHASES = ['modules', 'themes'];

    public function __construct()
    {
        parent::__construct('blueprint:deploy', 'Deploy an Omeka S site from a blueprint');
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->option('-u --update', 'Re-download and update resources that already exist', 'boolval', false);
        $this->option('-f --force', 'Allow deploying onto an installed instance (resets it when the core phase runs)', 'boolval', false);
        $this->option(
            '--skip',
            'Comma-separated phases to skip (core, modules, themes, vocabularies, resourceTemplates, users, settings)'
        );
        $this->optionDryRun();

        // Core phase: database connection (secrets come from flags, never the blueprint)
        $this->option('--db-host', 'Database host (core phase)');
        $this->option('--db-port', 'Database port (core phase)');
        $this->option('--db-name', 'Database name (core phase)');
        $this->option('--db-user', 'Database user (core phase)');
        $this->option('--db-password', 'Database password (core phase)');

        // Core phase: administrator account
        $this->option('--admin-name', 'Administrator name (core phase)', 'strval', 'Admin');
        $this->option('--admin-email', 'Administrator e-mail (core phase)', 'strval', 'admin@example.com');
        $this->option('--admin-password', 'Administrator password (core phase)', 'strval', 'admin');

        $this->usage(
            'blueprint:deploy ./site.blueprint.jsonc --base-path /var/www/omeka-s --db-name omeka --db-user omeka --db-password secret<eol/>'
            . 'blueprint:deploy ./site.blueprint.jsonc --dry-run<eol/>'
            . 'blueprint:deploy ./site.blueprint.jsonc --skip core --force   (sync config onto an existing site)'
        );
    }

    public function execute(
        string $source,
        ?bool $update = false,
        ?bool $force = false,
        ?string $skip = null,
        ?string $dbHost = null,
        ?string $dbPort = null,
        ?string $dbName = null,
        ?string $dbUser = null,
        ?string $dbPassword = null,
        ?string $adminName = 'Admin',
        ?string $adminEmail = 'admin@example.com',
        ?string $adminPassword = 'admin',
    ): void {
        $this->info("Loading blueprint from '{$source}' ...", true);
        $blueprint = (new BlueprintLoader())->load($source);

        $errors = (new BlueprintValidator())->validateBlueprint($blueprint->toArray());
        if ($errors) {
            $this->error('Blueprint is invalid:', true);
            foreach ($errors as $error) {
                $this->error("  - {$error}", true);
            }
            throw new Exception("Refusing to deploy an invalid blueprint. Run 'blueprint:validate' for details.");
        }

        $skipPhases = $skip ? array_values(array_filter(array_map('trim', explode(',', $skip)))) : [];
        $dryRun = $this->isDryRun();
        $update = (bool) $update;
        $force = (bool) $force;

        if ($dryRun) {
            $this->warn('Dry run: no changes will be made.', true);
        }

        $core = new CoreInstaller($this);
        $coreRequested = !in_array('core', $skipPhases, true);

        // ── core phase ──────────────────────────────────────────────────────────────────────
        if ($coreRequested) {
            if ($dryRun) {
                $core->reportDryRun($blueprint);
                $skipPhases[] = 'core';
            } else {
                $basePath = $this->values()['basePath'] ?? null;
                if (!$basePath) {
                    throw new Exception(
                        'The core phase needs --base-path (where Omeka S is or will be installed). '
                        . "Use '--skip core' to deploy onto the current instance."
                    );
                }
                $targetPath = rtrim(Path::toAbsolutePath($basePath, $this->getCwd()), DIRECTORY_SEPARATOR);
                $database = DatabaseConfig::fromOmekaPath($targetPath, [
                    'host' => $dbHost, 'port' => $dbPort, 'dbname' => $dbName,
                    'username' => $dbUser, 'password' => $dbPassword,
                ]);
                $admin = new UserConfig($adminName, $adminEmail, $adminPassword);

                $core->run($blueprint, $targetPath, $database, $admin, $force);

                // core is installed now: run the remaining phases in a fresh process so it is seen
                $this->reExecRemaining($source, $this->mergeSkip($skipPhases, ['core']), $update);
                return;
            }
        } elseif (!$dryRun) {
            // ── sync onto an existing instance ──
            $core->assertExistingInstallDeployable(DatabaseConfig::fromOmekaPath($this->getOmekaPath()), $force);
        }

        // ── remaining phases ────────────────────────────────────────────────────────────────
        // Installing modules only registers their services at the next Omeka bootstrap, so when the
        // blueprint installs modules do modules+themes here and the module-dependent phases in a
        // fresh process (same reason module:update shells out).
        $moduleBoundaryNeeded = !$dryRun
            && !in_array('modules', $skipPhases, true)
            && $blueprint->hasInstallableModules();

        if ($moduleBoundaryNeeded) {
            $restPhases = array_diff(self::PHASES, self::IN_PROCESS_PHASES);
            $phase1Skip = $this->mergeSkip($skipPhases, $restPhases);
            (new BlueprintApplier($this, false, $update, $phase1Skip, $source))->apply($blueprint);

            $phase2Skip = $this->mergeSkip($skipPhases, self::IN_PROCESS_PHASES);
            if (count($phase2Skip) < count(self::PHASES)) {
                $this->reExecRemaining($source, $phase2Skip, $update);
            }
            return;
        }

        (new BlueprintApplier($this, $dryRun, $update, $skipPhases, $source))->apply($blueprint);
        $this->ok($dryRun ? 'Dry run complete.' : 'Blueprint deployed.', true);
    }

    /**
     * @param string[] $skipPhases
     * @param string[] $add
     * @return string[]
     */
    private function mergeSkip(array $skipPhases, array $add): array
    {
        return array_values(array_unique(array_merge($skipPhases, $add)));
    }

    /**
     * Re-run deploy for the remaining phases in a fresh process (modules already active there).
     *
     * @param string[] $skipPhases
     */
    private function reExecRemaining(string $source, array $skipPhases, bool $update): void
    {
        if (count($skipPhases) >= count(self::PHASES)) {
            $this->ok('Blueprint deployed.', true);
            return;
        }
        $arguments = ['blueprint:deploy', $source, '--skip', implode(',', $skipPhases), '--force'];
        if ($update) {
            $arguments[] = '--update';
        }
        $this->info('Continuing in a fresh process ...', true);
        $exitCode = $this->runInNewProcess($arguments);
        if ($exitCode !== 0) {
            throw new Exception("Deploying the remaining phases failed (exit code {$exitCode}).");
        }
    }
}
