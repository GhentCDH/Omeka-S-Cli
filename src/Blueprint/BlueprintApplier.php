<?php
namespace OSC\Blueprint;

use Exception;
use Omeka\Module\Manager as ModuleManager;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Module\Exceptions\ModuleExistsException;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Exceptions\WarningException;
use OSC\Helper\Path;

/**
 * Apply a resolved blueprint to an Omeka S instance by driving the existing CLI commands in order:
 * modules, themes, vocabularies, resource templates, users, settings.
 *
 * Most phases run in-process. Modules are special: every module is downloaded first (a filesystem step
 * that needs no bootstrap), then each module is installed/enabled in its OWN fresh process. A module's
 * services register only at a bootstrap where it is active, so a module that depends on another (e.g.
 * on Common) must be installed in a process where that dependency is already active — which per-module
 * processes guarantee, since each module is listed after the ones it depends on. Relative asset paths
 * (vocabulary/resource-template sources) are already resolved by BlueprintLoader against the source
 * that declared them, so the applier uses them as-is.
 */
class BlueprintApplier
{
    /**
     * @param AbstractCommand $command The invoking command (for command lookup, output, verbosity)
     * @param bool            $dryRun  Report actions without performing them
     * @param bool            $update  Re-download/overwrite and update existing resources
     * @param string[]        $skip    Phase names to skip
     */
    public function __construct(
        private AbstractCommand $command,
        private bool $dryRun = false,
        private bool $update = false,
        private array $skip = [],
    ) {
    }

    public function apply(Blueprint $blueprint): void
    {
        $this->runPhase('modules', $blueprint->modules(), fn($d) => $this->applyModules($d));
        $this->runPhase('themes', $blueprint->themes(), fn($d) => $this->applyThemes($d));
        $this->runPhase('vocabularies', $blueprint->vocabularies(), fn($d) => $this->applyVocabularies($d));
        $this->runPhase('resourceTemplates', $blueprint->resourceTemplates(), fn($d) => $this->applyResourceTemplates($d));
        $this->runPhase('users', $blueprint->users(), fn($d) => $this->applyUsers($d));
        $this->runPhase('settings', $blueprint->settings(), fn($d) => $this->applySettings($d));
    }

    private function runPhase(string $name, mixed $data, callable $run): void
    {
        if (in_array($name, $this->skip, true)) {
            $this->command->info("• {$name}: skipped", true);
            return;
        }
        if (empty($data)) {
            return;
        }
        $this->command->info("• {$name}", true);
        $run($data);
    }

    // --- modules ---------------------------------------------------------------------------------

    private function applyModules(array $modules): void
    {
        $modules = array_map([$this, 'normalizeModule'], $modules);

        // 1. download every module (bundled modules ship with core and are skipped)
        foreach ($modules as $module) {
            $uri = $this->moduleUri($module);
            if ($uri === null) {
                $this->command->info("  {$module['name']}: bundled, nothing to download", true);
                continue;
            }
            if ($this->dryRun) {
                $this->command->info("  would download module '{$module['name']}' ({$uri})", true);
                continue;
            }
            // re-download when --update, or when a pinned version differs from what is on disk
            $force = $this->update || $this->versionMismatch('modules', 'module.ini', $module['name'], $module['version'] ?? null);
            try {
                $this->run('module:download', fn($c) => $c->execute($uri, $force), false);
            } catch (ModuleExistsException) {
                $this->command->info("  {$module['name']}: already at the required version, skipping (use --update to replace)", true);
            }
        }

        // 2. install + enable each module in its OWN process, in blueprint order. A module's services
        //    register only at a bootstrap where it is active, so a dependent (e.g. on Common) must be
        //    installed in a fresh process where its dependency is already active. Blueprint order lists
        //    dependencies first, so processing one module per process satisfies that.
        if ($this->dryRun) {
            foreach ($modules as $module) {
                if ($module['name'] === '' || !in_array($module['state'], ['install', 'activate'], true)) {
                    continue;
                }
                $this->command->info("  would install module '{$module['name']}'", true);
                if ($module['state'] === 'activate') {
                    $this->command->info("  would enable module '{$module['name']}'", true);
                }
            }
            return;
        }

        // snapshot current states once, to skip modules already in their target state (cheap re-deploys)
        $moduleApi = $this->command->getOmekaInstance(false)->getModuleApi();
        foreach ($modules as $module) {
            $name = $module['name'];
            $state = $module['state'];
            if ($name === '' || !in_array($state, ['install', 'activate'], true)) {
                continue;
            }

            $onDisk = $moduleApi->getModule($name);
            $isInstalled = $onDisk && in_array(
                $onDisk->getState(),
                [ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NOT_ACTIVE],
                true
            );
            $isActive = $onDisk && $onDisk->getState() === ModuleManager::STATE_ACTIVE;

            if (!$isInstalled) {
                // install also activates the module in Omeka S
                $this->runModuleStep('module:install', $name);
            } elseif ($state === 'activate' && !$isActive) {
                // already installed but inactive: activate it
                $this->runModuleStep('module:enable', $name);
            }
        }
    }

    /**
     * Run a module operation in a fresh process, so the module's services are available to the modules
     * processed after it. Throws when the child process fails.
     */
    private function runModuleStep(string $command, string $id): void
    {
        $exitCode = $this->command->runInNewProcess([$command, $id]);
        if ($exitCode !== 0) {
            throw new Exception("'{$command} {$id}' failed (exit code {$exitCode}).");
        }
    }

    /**
     * @param array{name:string,state:string,source:mixed,version:?string} $module
     * @return string|null The module:download argument, or null for a bundled module
     */
    private function moduleUri(array $module): ?string
    {
        $source = $module['source'] ?? null;
        if (is_array($source)) {
            $type = $source['type'] ?? null;
            if ($type === 'bundled') {
                return null;
            }
            if ($type === 'url') {
                return $source['url'] ?? null;
            }
            if ($type === 'omeka.org') {
                $slug = $source['slug'] ?? $module['name'];
                $version = $module['version'] ?? null;
                return $version ? "{$slug}:{$version}" : $slug;
            }
        }
        $version = $module['version'] ?? null;
        return $version ? "{$module['name']}:{$version}" : $module['name'];
    }

    private function normalizeModule(mixed $module): array
    {
        if (is_string($module)) {
            return ['name' => $module, 'state' => 'activate', 'source' => null, 'version' => null];
        }
        return [
            'name'    => $module['name'] ?? '',
            'state'   => $module['state'] ?? 'activate',
            'source'  => $module['source'] ?? null,
            'version' => $module['version'] ?? null,
        ];
    }

    /**
     * @param array $modules Normalized modules
     * @param string[] $states States to keep
     * @return string[] Module names in the given states
     */
    private function moduleNames(array $modules, array $states): array
    {
        $names = [];
        foreach ($modules as $module) {
            if (in_array($module['state'], $states, true) && $module['name'] !== '') {
                $names[] = $module['name'];
            }
        }
        return $names;
    }

    // --- themes ----------------------------------------------------------------------------------

    private function applyThemes(array $themes): void
    {
        foreach ($themes as $theme) {
            $theme = is_string($theme)
                ? ['name' => $theme, 'source' => null, 'version' => null]
                : $theme;
            $uri = $this->themeUri($theme);
            if ($uri === null) {
                $this->command->info("  {$theme['name']}: bundled, nothing to download", true);
                continue;
            }
            if ($this->dryRun) {
                $this->command->info("  would download theme '{$theme['name']}' ({$uri})", true);
                continue;
            }
            $force = $this->update || $this->versionMismatch('themes', 'theme.ini', $theme['name'] ?? '', $theme['version'] ?? null);
            try {
                $this->run('theme:download', fn($c) => $c->execute($uri, $force, false), false);
            } catch (ThemeExistsException) {
                $this->command->info("  {$theme['name']}: already at the required version, skipping (use --update to replace)", true);
            }
        }
    }

    private function themeUri(array $theme): ?string
    {
        $source = $theme['source'] ?? null;
        if (is_array($source)) {
            $type = $source['type'] ?? null;
            if ($type === 'bundled') {
                return null;
            }
            if ($type === 'url') {
                return $source['url'] ?? null;
            }
            if ($type === 'omeka.org') {
                $slug = $source['slug'] ?? $theme['name'];
                $version = $theme['version'] ?? null;
                return $version ? "{$slug}:{$version}" : $slug;
            }
        }
        $version = $theme['version'] ?? null;
        return $version ? "{$theme['name']}:{$version}" : ($theme['name'] ?? null);
    }

    // --- vocabularies ----------------------------------------------------------------------------

    private function applyVocabularies(array $vocabularies): void
    {
        $cmd = $this->command->app()->commands()['vocabulary:import'] ?? null;
        if (!$cmd instanceof AbstractCommand) {
            throw new Exception("Required command 'vocabulary:import' is not available.");
        }

        foreach ($vocabularies as $vocabulary) {
            $label = $vocabulary['label'] ?? $vocabulary['prefix'] ?? '?';
            if ($this->dryRun) {
                $this->command->info("  would import vocabulary '{$label}'", true);
                continue;
            }

            // normalise the RDF source to the canonical `source` key (so the importer emits no
            // deprecation warning). The path itself was already resolved by the loader against the
            // source that declared it, so it is used as-is here.
            $rdfSource = $vocabulary['source'] ?? $vocabulary['file'] ?? $vocabulary['url'] ?? null;
            if ($rdfSource !== null) {
                unset($vocabulary['file'], $vocabulary['url']);
                $vocabulary['source'] = $rdfSource;
            }

            // a blueprint vocabulary entry is an importer config: hand it to the importer as a
            // config file, so inline and $import-referenced configs follow one code path
            $configFile = Path::createTempFile('bp-vocab-');
            file_put_contents(
                $configFile,
                json_encode($vocabulary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            try {
                $this->propagateVerbosity($cmd);
                $cmd->execute($configFile, $this->update);
            } catch (WarningException $e) {
                // e.g. the vocabulary already exists and --update was not requested
                $this->command->warn("  {$label}: " . $e->getMessage(), true);
            } finally {
                @unlink($configFile);
            }
        }
    }

    // --- resource templates ----------------------------------------------------------------------

    private function applyResourceTemplates(array $templates): void
    {
        foreach ($templates as $template) {
            $source = $template['source'] ?? null;
            if (!$source) {
                $this->command->warn("  resource template entry without 'source', skipping.", true);
                continue;
            }
            $label = $template['label'] ?? basename((string) $source);
            if ($this->dryRun) {
                $this->command->info("  would import resource template '{$label}'", true);
                continue;
            }
            // $source was already resolved by the loader against the source that declared it
            $ignoreDeps = (bool) ($template['ignoreDeps'] ?? false);
            $this->run(
                'resource-template:import',
                fn($c) => $c->execute($source, null, $template['label'] ?? null, $this->update, $ignoreDeps)
            );
        }
    }

    // --- users -----------------------------------------------------------------------------------

    private function applyUsers(array $users): void
    {
        foreach ($users as $user) {
            $email = $user['email'] ?? null;
            if (!$email) {
                $this->command->warn("  user entry without 'email', skipping.", true);
                continue;
            }
            $name = $user['username'] ?? $user['name'] ?? $email;
            $role = $user['role'] ?? 'author';
            $password = $user['password'] ?? null;
            $isInactive = array_key_exists('isActive', $user) ? !$user['isActive'] : false;

            if ($this->dryRun) {
                $this->command->info("  would create user '{$email}' ({$role})", true);
                continue;
            }
            // ignoreExisting = true keeps apply idempotent
            $this->run('user:add', fn($c) => $c->execute($email, $name, $role, $password, $isInactive, false, true));
        }
    }

    // --- settings --------------------------------------------------------------------------------

    private function applySettings(array $settings): void
    {
        foreach ($settings as $id => $value) {
            if ($this->dryRun) {
                $this->command->info("  would set '{$id}'", true);
                continue;
            }
            // json_encode round-trips losslessly through config:set's convertStringToType()
            $this->run('config:set', fn($c) => $c->execute((string) $id, json_encode($value)));
        }
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function run(string $name, callable $call, bool $catchWarnings = true): void
    {
        $cmd = $this->command->app()->commands()[$name] ?? null;
        if (!$cmd instanceof AbstractCommand) {
            throw new Exception("Required command '{$name}' is not available.");
        }
        $this->propagateVerbosity($cmd);
        if (!$catchWarnings) {
            $call($cmd);
            return;
        }
        try {
            $call($cmd);
        } catch (WarningException $e) {
            // a non-fatal advisory (e.g. "already exists, use --update"): keep apply idempotent
            $this->command->warn('  ' . $e->getMessage(), true);
        }
    }

    private function propagateVerbosity(AbstractCommand $cmd): void
    {
        $cmd->primeValue('verbosity', $this->command->values()['verbosity'] ?? 1);
    }

    /**
     * Whether a pinned version differs from the one currently on disk (so it must be re-downloaded).
     * No pinned version, or nothing on disk yet, is not a mismatch.
     */
    private function versionMismatch(string $dir, string $iniName, string $name, ?string $want): bool
    {
        if (!$want || $name === '') {
            return false;
        }
        $have = $this->onDiskVersion($dir, $iniName, $name);
        if ($have === null) {
            return false;
        }
        return $this->normalizeVersion($have) !== $this->normalizeVersion($want);
    }

    /**
     * The version recorded in a downloaded module's/theme's ini file, or null if it is not on disk.
     */
    private function onDiskVersion(string $dir, string $iniName, string $name): ?string
    {
        try {
            $iniPath = $this->command->resolveOmekaPath() . "/{$dir}/{$name}/config/{$iniName}";
        } catch (\Throwable) {
            return null;
        }
        if (!is_file($iniPath)) {
            return null;
        }
        $ini = @parse_ini_file($iniPath, true);
        if (!is_array($ini)) {
            return null;
        }
        $version = $ini['info']['version'] ?? $ini['version'] ?? null;
        return $version !== null ? (string) $version : null;
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
