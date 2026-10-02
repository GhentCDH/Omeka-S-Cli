<?php
namespace OSC\Blueprint;

use Exception;
use OSC\Commands\AbstractCommand;
use OSC\Commands\Module\Exceptions\ModuleExistsException;
use OSC\Commands\Theme\Exceptions\ThemeExistsException;
use OSC\Downloader\ZipDownloader;
use OSC\Exceptions\WarningException;
use OSC\Helper\Path;
use OSC\Helper\Reference\ReferenceResolver;
use OSC\Helper\ResourceFetcher;

/**
 * Apply a resolved blueprint to an Omeka S instance by driving the existing CLI commands in order:
 * modules, themes, files, vocabularies, resource templates, users, settings.
 *
 * The commands run in-process, sharing one Omeka bootstrap. Modules are a special case: every module
 * is downloaded first (a pure filesystem step that does not bootstrap Omeka), and only then are they
 * installed/enabled. That ordering matters — Omeka reads the modules directory when it boots, so a
 * module downloaded after boot would be invisible. Installing all downloads together lets the first
 * install trigger the boot with every new module already on disk (the same reason `module:download
 * -i` works).
 */
class BlueprintApplier
{
    /** Resolves repo-aware and relative asset references against the blueprint source. */
    private ReferenceResolver $resolver;

    /**
     * @param AbstractCommand       $command   The invoking command (for command lookup, output, verbosity)
     * @param bool                  $dryRun    Report actions without performing them
     * @param bool                  $update    Re-download/overwrite and update existing resources
     * @param string[]              $skip      Phase names to skip
     * @param string|null           $baseSource The blueprint source, to resolve relative asset paths
     * @param ReferenceResolver|null $resolver  Reference resolver (defaults to the standard providers)
     */
    public function __construct(
        private AbstractCommand $command,
        private bool $dryRun = false,
        private bool $update = false,
        private array $skip = [],
        private ?string $baseSource = null,
        ?ReferenceResolver $resolver = null,
    ) {
        $this->resolver = $resolver ?? ReferenceResolver::withDefaults();
    }

    public function apply(Blueprint $blueprint): void
    {
        $this->runPhase('modules', $blueprint->modules(), fn($d) => $this->applyModules($d));
        $this->runPhase('themes', $blueprint->themes(), fn($d) => $this->applyThemes($d));
        $this->runPhase('files', $blueprint->files(), fn($d) => $this->applyFiles($d));
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

        // 1. download every module (one already on disk without a source is used as is)
        foreach ($modules as $module) {
            // re-download when --update, or when a pinned version differs from what is on disk
            $force = $this->update || $this->versionMismatch('modules', 'module.ini', $module['name'], $module['version'] ?? null);
            if (!$force && $module['source'] === null && $this->isOnDisk('modules', $module['name'])) {
                $this->command->info("  {$module['name']}: already present, nothing to download", true);
                continue;
            }
            $uri = $this->addonUri($module);
            if ($this->dryRun) {
                $this->command->info("  would download module '{$module['name']}' ({$uri})", true);
                continue;
            }
            try {
                $this->run('module:download', fn($c) => $c->execute($uri, $force), false);
            } catch (ModuleExistsException) {
                $this->command->info("  {$module['name']}: already at the required version, skipping (use --update to replace)", true);
            }
        }

        // 2. install (state install|activate), in blueprint order (author lists dependencies first)
        $installIds = $this->moduleNames($modules, ['install', 'activate']);
        foreach ($installIds as $id) {
            if ($this->dryRun) {
                $this->command->info("  would install module '{$id}'", true);
                continue;
            }
            $this->run('module:install', fn($c) => $c->execute($id));
        }

        // 3. enable (state activate), in blueprint order
        $enableIds = $this->moduleNames($modules, ['activate']);
        foreach ($enableIds as $id) {
            if ($this->dryRun) {
                $this->command->info("  would enable module '{$id}'", true);
                continue;
            }
            $this->run('module:enable', fn($c) => $c->execute($id));
        }
    }

    /**
     * The module:download / theme:download argument for an add-on entry: its source, or its name
     * (resolved through omeka.org) when it has none. The version selects the omeka.org release or
     * the tag of a git source; a ZIP URL already pins the release, so it wins.
     *
     * @param array{name:string,source?:?string,version?:?string} $addon
     */
    private function addonUri(array $addon): string
    {
        $source = $addon['source'] ?? null;
        $version = $addon['version'] ?? null;
        if ($source === null) {
            return $version ? "{$addon['name']}:{$version}" : $addon['name'];
        }
        $isGit = str_starts_with($source, 'gh:') || str_ends_with($source, '.git');
        if ($version && $isGit && !str_contains($source, '#')) {
            return "{$source}#{$version}";
        }
        return $source;
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
            $theme = is_string($theme) ? ['name' => $theme] : $theme;
            $theme += ['name' => '', 'source' => null, 'version' => null];
            $force = $this->update || $this->versionMismatch('themes', 'theme.ini', $theme['name'], $theme['version']);
            // e.g. the default theme, which ships with the core
            if (!$force && $theme['source'] === null && $this->isOnDisk('themes', $theme['name'])) {
                $this->command->info("  {$theme['name']}: already present, nothing to download", true);
                continue;
            }
            $uri = $this->addonUri($theme);
            if ($this->dryRun) {
                $this->command->info("  would download theme '{$theme['name']}' ({$uri})", true);
                continue;
            }
            try {
                $this->run('theme:download', fn($c) => $c->execute($uri, $force, false), false);
            } catch (ThemeExistsException) {
                $this->command->info("  {$theme['name']}: already at the required version, skipping (use --update to replace)", true);
            }
        }
    }

    // --- files -----------------------------------------------------------------------------------

    private function applyFiles(array $files): void
    {
        foreach ($files as $file) {
            $destination = $this->safeDestination((string) ($file['destination'] ?? ''));
            $source = $this->resolveAssetPath((string) ($file['source'] ?? ''));
            $extract = (bool) ($file['extract'] ?? false);
            if ($this->dryRun) {
                $this->command->info('  would ' . ($extract ? 'extract' : 'copy') . " '{$source}' to '{$destination}'", true);
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
            $this->command->info("  {$destination}", true);
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
                $this->command->info("  would import vocabulary '{$label}'", true);
                continue;
            }

            // a blueprint vocabulary entry is an importer config: hand it to the importer as a
            // config file, so inline and $import-referenced configs follow one code path
            $configFile = Path::createTempFile('bp-vocab-');
            file_put_contents(
                $configFile,
                json_encode($this->vocabularyConfig($vocabulary), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
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

    /**
     * The vocabulary:import config for a blueprint entry: its `source`, resolved against the
     * blueprint, becomes the importer's `url` or `file`.
     */
    private function vocabularyConfig(array $vocabulary): array
    {
        $source = $this->resolveAssetPath($vocabulary['source'] ?? null);
        unset($vocabulary['source']);
        if ($source !== null && $source !== '') {
            $vocabulary[ResourceFetcher::isUrl($source) ? 'url' : 'file'] = $source;
        }
        return $vocabulary;
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
            $source = $this->resolveAssetPath($source);
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
     * Resolve a relative asset path against the blueprint's location. URLs and absolute paths pass
     * through unchanged.
     */
    private function resolveAssetPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }
        return $this->resolver->resolve($path, $this->baseSource);
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

    /** Whether a module/theme directory exists in the Omeka S installation. */
    private function isOnDisk(string $dir, string $name): bool
    {
        if ($name === '') {
            return false;
        }
        try {
            return is_dir($this->command->resolveOmekaPath() . "/{$dir}/{$name}");
        } catch (\Throwable) {
            return false;
        }
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
