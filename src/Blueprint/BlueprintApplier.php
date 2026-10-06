<?php
namespace OSC\Blueprint;

use Exception;
use Omeka\Module\Manager as ModuleManager;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Module\Exceptions\ModuleExistsException;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\WarningException;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;

/**
 * Apply a resolved blueprint to an Omeka S instance by driving the existing CLI commands in order:
 * modules, themes, files, vocabularies, resource templates, users, settings.
 *
 * Most phases run in-process. Modules are special: every module is downloaded first (a filesystem step
 * that needs no bootstrap), then each module is installed/enabled in its OWN fresh process. A module's
 * services register only at a bootstrap where it is active, so a module that depends on another (e.g.
 * on Common) must be installed in a process where that dependency is already active — which per-module
 * processes guarantee, since each module is listed after the ones it depends on. Relative asset paths
 * (file/vocabulary/resource-template sources) are already resolved by BlueprintLoader against the
 * source that declared them, so the applier uses them as-is.
 */
class BlueprintApplier
{
    /** Human-readable phase names for the per-phase status lines. */
    private const PHASE_LABELS = [
        'modules'           => 'Modules',
        'themes'            => 'Themes',
        'files'             => 'Files',
        'vocabularies'      => 'Vocabularies',
        'resourceTemplates' => 'Resource templates',
        'users'             => 'Users',
        'settings'          => 'Settings',
    ];

    /**
     * @param AbstractCommand $command The invoking command (for command lookup, output, verbosity)
     * @param bool            $dryRun  Report actions without performing them
     * @param bool            $update  Re-download/overwrite and update existing resources
     * @param string[]        $skip    Phases the user asked to skip (reported as skipped)
     * @param string[]        $defer   Phases handled in another stage of a multi-process deploy (silent)
     */
    public function __construct(
        private AbstractCommand $command,
        private bool $dryRun = false,
        private bool $update = false,
        private array $skip = [],
        private array $defer = [],
    ) {
    }

    public function apply(Blueprint $blueprint): void
    {
        $this->applyModulesAndThemes($blueprint);
        $this->applyConfiguration($blueprint);
    }

    /**
     * The phases that add code and files to the instance: modules, themes and files. Files come last,
     * so they can land inside a module or theme, and before configuration, which may read them.
     */
    public function applyModulesAndThemes(Blueprint $blueprint): void
    {
        $this->runPhase('modules', $blueprint->modules(), fn($d) => $this->applyModules($d));
        $this->runPhase('themes', $blueprint->themes(), fn($d) => $this->applyThemes($d));
        $this->runPhase('files', $blueprint->files(), fn($d) => $this->applyFiles($d));
    }

    /**
     * The phases that configure the instance (and may need the modules' services): vocabularies,
     * resource templates, users and settings.
     */
    public function applyConfiguration(Blueprint $blueprint): void
    {
        $this->runPhase('vocabularies', $blueprint->vocabularies(), fn($d) => $this->applyVocabularies($d));
        $this->runPhase('resourceTemplates', $blueprint->resourceTemplates(), fn($d) => $this->applyResourceTemplates($d));
        $this->runPhase('users', $blueprint->users(), fn($d) => $this->applyUsers($d));
        $this->runPhase('settings', $blueprint->settings(), fn($d) => $this->applySettings($d));
    }

    private function runPhase(string $name, mixed $data, callable $run): void
    {
        $label = self::PHASE_LABELS[$name] ?? $name;

        // handled in another stage of a multi-process deploy: stay silent here, it is not skipped
        if (in_array($name, $this->defer, true)) {
            return;
        }
        if (in_array($name, $this->skip, true)) {
            $this->command->section($label, null, 'skipped (--skip)');
            return;
        }
        if (empty($data)) {
            $this->command->section($label, null, 'nothing to do');
            return;
        }
        $this->command->section($label);
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
                $this->command->note("{$module['name']}: bundled, nothing to download", true);
                continue;
            }
            if ($this->dryRun) {
                $this->command->note("would download module '{$module['name']}' ({$uri})", true);
                continue;
            }
            // re-download when --update, or when a pinned version differs from what is on disk
            $force = $this->update || $this->versionMismatch('modules', 'module.ini', $module['name'], $module['version'] ?? null);
            try {
                $this->run('module:download', fn($c) => $c->execute($uri, $force), false);
            } catch (ModuleExistsException) {
                $this->command->note("{$module['name']}: already present at the required version, skipping (use --update to replace)", true);
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
                $this->command->note("would install module '{$module['name']}'", true);
                if ($module['state'] === 'activate') {
                    $this->command->note("would enable module '{$module['name']}'", true);
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
                $this->command->note("{$theme['name']}: bundled, nothing to download", true);
                continue;
            }
            if ($this->dryRun) {
                $this->command->note("would download theme '{$theme['name']}' ({$uri})", true);
                continue;
            }
            $force = $this->update || $this->versionMismatch('themes', 'theme.ini', $theme['name'] ?? '', $theme['version'] ?? null);
            try {
                $this->run('theme:download', fn($c) => $c->execute($uri, $force, false), false);
            } catch (ThemeExistsException) {
                $this->command->note("{$theme['name']}: already present at the required version, skipping (use --update to replace)", true);
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

    // --- files -----------------------------------------------------------------------------------

    private function applyFiles(array $files): void
    {
        foreach ($files as $file) {
            $destination = $this->safeDestination((string) ($file['destination'] ?? ''));
            // $source was already resolved by the loader against the source that declared it
            $source = (string) ($file['source'] ?? '');
            $extract = (bool) ($file['extract'] ?? false);
            if ($this->dryRun) {
                $this->command->note('would ' . ($extract ? 'extract' : 'copy') . " '{$source}' to '{$destination}'", true);
                continue;
            }
            $target = $this->command->resolveOmekaPath() . '/' . $destination;
            if ($extract) {
                $tmp = (new ZipDownloader($source))->download();
                try {
                    // a single top-level directory is stripped, as with add-on archives
                    $entries = array_values(array_diff(scandir($tmp) ?: [], ['.', '..']));
                    $root = count($entries) === 1 && is_dir("{$tmp}/{$entries[0]}") ? "{$tmp}/{$entries[0]}" : $tmp;
                    Path::copyFolder($root, $target);
                } finally {
                    Path::removeFolder($tmp);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new Exception("Could not create directory '{$dir}'.");
                }
                if (file_put_contents($target, ResourceFetcher::fetch($source)) === false) {
                    throw new Exception("Could not write '{$target}'.");
                }
            }
            $this->command->ok("File '{$destination}' " . ($extract ? 'extracted' : 'written') . '.', true);
        }
    }

    /**
     * A files[].destination, checked to stay inside the Omeka S root: relative, without '..'
     * segments. The schema enforces the same rule; this guards blueprints that skipped validation.
     *
     * @throws \InvalidArgumentException
     */
    private function safeDestination(string $destination): string
    {
        $segments = explode('/', str_replace('\\', '/', $destination));
        if ($destination === '' || $segments[0] === '' || preg_match('/^[A-Za-z]:/', $destination) || in_array('..', $segments, true)) {
            throw new \InvalidArgumentException("Unsafe file destination '{$destination}': it must be a path inside the Omeka S root.");
        }
        return $destination;
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
                $this->command->note("would import vocabulary '{$label}'", true);
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
                $this->propagateContext($cmd);
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
                $this->command->note("would import resource template '{$label}'", true);
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
                $this->command->note("would create user '{$email}' ({$role})", true);
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
                $this->command->note("would set '{$id}'", true);
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
        $this->propagateContext($cmd);
        if (!$catchWarnings) {
            $call($cmd);
            return;
        }
        try {
            $call($cmd);
        } catch (WarningException $e) {
            // a non-fatal advisory (e.g. "already exists, use --update"): keep apply idempotent
            $this->command->warn($e->getMessage(), true);
        }
    }

    /**
     * Hand the invoking command's context to a sub-command run in-process: its verbosity, and the
     * Omeka S base path (so the sub-command works on the same instance, whatever the working
     * directory, instead of relying on a path resolved earlier by another command).
     */
    private function propagateContext(AbstractCommand $cmd): void
    {
        $cmd->primeValue('verbosity', $this->command->values()['verbosity'] ?? 1);
        $cmd->primeValue('basePath', $this->command->resolveOmekaPath());
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
