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
    private const PHASES = ['core', 'modules', 'themes', 'files', 'vocabularies', 'resourceTemplates', 'users', 'settings'];

    /** Phases handled in the current process (no active-module services required). */
    private const IN_PROCESS_PHASES = ['modules', 'themes', 'files'];

    /**
     * The deploy as the user sees it: two numbered stages, each grouping phases. Independent of the
     * process boundaries a deploy needs internally (after the core install, after the modules).
     */
    private const STAGES = [
        1 => ['title' => 'Install core, modules, themes and files', 'phases' => ['core', 'modules', 'themes', 'files']],
        2 => [
            'title'  => 'Configure vocabularies, resource templates, users and settings',
            'phases' => ['vocabularies', 'resourceTemplates', 'users', 'settings'],
        ],
    ];

    /** A deploy shows outcomes by default; -v adds the sub-commands' detail, -vv debug output. */
    protected const DEFAULT_VERBOSITY = self::VERBOSITY_OUTCOMES;

    public function __construct()
    {
        parent::__construct('blueprint:deploy', 'Deploy an Omeka S site from a blueprint');
        $this->argument('<source>', 'Path or URL to the blueprint (jsonc allowed)');
        $this->option('-u --update', 'Re-download and update resources that already exist', 'boolval', false);
        $this->option('-f --force', 'Allow deploying onto an installed instance (resets it when the core phase runs)', 'boolval', false);
        $this->option(
            '--skip',
            'Comma-separated phases to skip (core, modules, themes, files, vocabularies, resourceTemplates, users, settings)'
        );
        // internal: phases already applied by an earlier stage of a multi-process deploy; kept silent
        // rather than reported as skipped. Set automatically when the deploy re-executes itself.
        $this->option('--defer', 'Internal: phases already applied by a previous deploy stage');
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
        ?string $defer = null,
        ?string $dbHost = null,
        ?string $dbPort = null,
        ?string $dbName = null,
        ?string $dbUser = null,
        ?string $dbPassword = null,
        ?string $adminName = 'Admin',
        ?string $adminEmail = 'admin@example.com',
        ?string $adminPassword = 'admin',
    ): void {
        $skipPhases = $this->parsePhases($skip);
        $deferPhases = $this->parsePhases($defer);

        // a later stage of a multi-process deploy: the first process already reported loading the
        // blueprint (and its warnings), so the continuation stays quiet about it
        $isContinuation = (bool) $deferPhases;

        if (!$isContinuation) {
            $this->note("Loading blueprint from '{$source}' ...", true);
        }
        $loader = new BlueprintLoader();
        $blueprint = $loader->load($source);
        $loaderWarnings = $loader->takeWarnings();
        if (!$isContinuation) {
            foreach ($loaderWarnings as $warning) {
                $this->warn("  {$warning}", true);
            }
        }

        $errors = (new BlueprintValidator())->validateBlueprint($blueprint->toArray());
        if ($errors) {
            $this->error('Blueprint is invalid:', true);
            foreach ($errors as $error) {
                $this->error("  - {$error}", true);
            }
            throw new Exception("Refusing to deploy an invalid blueprint. Run 'blueprint:validate' for details.");
        }

        $dryRun = $this->isDryRun();
        $update = (bool) $update;
        $force = (bool) $force;

        if ($dryRun) {
            $this->warn('Dry run: no changes will be made.', true);
        }

        $core = new CoreInstaller($this);
        $coreDeferred = in_array('core', $deferPhases, true);
        $coreRequested = !$coreDeferred && !in_array('core', $skipPhases, true);

        $this->announceStage(1, $deferPhases);

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
                $this->reExecRemaining($source, $skipPhases, $this->mergeSkip($deferPhases, ['core']), $update, 'Core installed');
                return;
            }
        } elseif (!$dryRun && !$coreDeferred) {
            // ── sync onto an existing instance ──
            $core->assertExistingInstallDeployable(DatabaseConfig::fromOmekaPath($this->getOmekaPath()), $force);
        }

        // ── remaining phases ────────────────────────────────────────────────────────────────
        // Installing modules only registers their services at the next Omeka bootstrap, so when the
        // blueprint installs modules do modules, themes and files here and the module-dependent phases
        // in a fresh process (same reason module:update shells out).
        $moduleBoundaryNeeded = !$dryRun
            && !in_array('modules', $skipPhases, true)
            && !in_array('modules', $deferPhases, true)
            && $blueprint->hasInstallableModules();

        if ($moduleBoundaryNeeded) {
            // stage 1: modules, themes and files here; the module-dependent phases are deferred to a
            // fresh process, since a module's services only register at the next Omeka bootstrap
            $stage1Defer = $this->mergeSkip($deferPhases, array_diff(self::PHASES, self::IN_PROCESS_PHASES));
            (new BlueprintApplier($this, false, $update, $skipPhases, $stage1Defer))->applyModulesAndThemes($blueprint);

            // stage 2: the deferred phases, now that the modules are active
            $stage2Defer = $this->mergeSkip($deferPhases, self::IN_PROCESS_PHASES);
            $this->reExecRemaining($source, $skipPhases, $stage2Defer, $update, 'Modules ready');
            return;
        }

        $applier = new BlueprintApplier($this, $dryRun, $update, $skipPhases, $deferPhases);
        $applier->applyModulesAndThemes($blueprint);
        $this->announceStage(2, $deferPhases);
        $applier->applyConfiguration($blueprint);
        $this->ok($dryRun ? 'Dry run complete.' : 'Blueprint deployed.', true);
    }

    /**
     * Print a stage heading, in the process that starts the stage: a stage with a phase deferred
     * (already applied by an earlier process) was announced there.
     *
     * @param int      $stage The stage number (a key of STAGES)
     * @param string[] $defer Phases already applied by an earlier process
     */
    private function announceStage(int $stage, array $defer): void
    {
        if (array_intersect(self::STAGES[$stage]['phases'], $defer)) {
            return;
        }
        $count = count(self::STAGES);
        $this->heading("Phase {$stage}/{$count}: " . self::STAGES[$stage]['title']);
    }

    /** Parse a comma-separated phase list into a trimmed array. */
    private function parsePhases(?string $csv): array
    {
        return $csv ? array_values(array_filter(array_map('trim', explode(',', $csv)))) : [];
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
     * Re-run deploy for the remaining phases in a fresh process (so just-installed core/modules are
     * active there). Phases already done are passed as --defer (silent), the user's --skip is carried
     * through, and $reason explains the reload.
     *
     * @param string[] $skip
     * @param string[] $defer
     */
    private function reExecRemaining(string $source, array $skip, array $defer, bool $update, string $reason): void
    {
        if (!array_diff(self::PHASES, $skip, $defer)) {
            $this->ok('Blueprint deployed.', true);
            return;
        }
        $arguments = ['blueprint:deploy', $source, '--force'];
        if ($skip) {
            $arguments[] = '--skip';
            $arguments[] = implode(',', $skip);
        }
        if ($defer) {
            $arguments[] = '--defer';
            $arguments[] = implode(',', $defer);
        }
        if ($update) {
            $arguments[] = '--update';
        }
        $this->debug("{$reason}, continuing in a new process ...", true);
        $exitCode = $this->runInNewProcess($arguments);
        if ($exitCode !== 0) {
            throw new Exception("Deploying the remaining phases failed (exit code {$exitCode}).");
        }
    }
}
