<?php
namespace OSC\Blueprint\Bundle;

use Closure;
use Exception;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintValidator;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\AddonResolver;
use OSC\Helper\ResourceFetcher;
use OSC\Omeka\OmekaDotOrgApi;
use stdClass;
use Throwable;

/**
 * Fetch every source a blueprint needs into one directory, and write a rewritten `blueprint.json`
 * there whose sources are relative paths inside it. The bundle then deploys without the catalogs,
 * git hosts or remote URLs the original refers to (see SourceFields for where sources live).
 *
 * Versions are pinned on the way: the Omeka S version (the latest release when the blueprint has
 * none) and every add-on's version. Add-on names become their folder names (catalog dirname or
 * module namespace), and site themes follow a renamed theme. Everything that is not a source is
 * copied unchanged.
 */
class Bundler
{
    /** Top-level keys that are JSON objects, so an empty one is written as {} rather than []. */
    private const OBJECT_KEYS = ['meta', 'preferredVersions', 'install', 'settings'];

    /** @var Closure(string, string): ?string */
    private Closure $download;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param Closure(): string                         $latestOmekaVersion The latest Omeka S release
     * @param (Closure(string, string): ?string)|null   $download fn(source, target): Content-Type; copies or downloads a source
     * @param (Closure(string): void)|null              $progress Receives a line per source being bundled
     */
    public function __construct(
        private AddonResolver $modules,
        private AddonResolver $themes,
        private AddonPackager $packager,
        private Closure $latestOmekaVersion,
        ?Closure $download = null,
        private ?Closure $progress = null,
    ) {
        $this->download = $download ?? ResourceFetcher::download(...);
    }

    /**
     * @param string $dir An existing, empty directory to write the bundle into
     * @return string[] Warnings, each prefixed with the entry it is about
     * @throws Exception Naming the entry that could not be bundled
     */
    public function bundle(Blueprint $blueprint, string $dir, bool $allowIncompatible = false): array
    {
        $this->warnings = [];
        $data = $blueprint->toArray();

        $omeka = $this->omekaVersion($data);
        $data['preferredVersions'] = array_merge($data['preferredVersions'] ?? [], ['omeka' => $omeka]);
        $this->assertGitAvailable($data);

        $core = $this->bundleCore($blueprint->installSource(), $omeka, $dir);
        $data = self::withValue($data, explode('.', SourceFields::CORE_SOURCE), './' . $core);

        $renamedThemes = [];
        foreach (['modules' => SourceFields::MODULE, 'themes' => SourceFields::THEME] as $key => $kind) {
            foreach ($data[$key] ?? [] as $i => $entry) {
                $data[$key][$i] = $this->bundleAddon($kind, "{$key}[{$i}]", $entry, $omeka, $allowIncompatible, $dir, $renamedThemes);
            }
        }
        foreach ($data['sites'] ?? [] as $i => $site) {
            $theme = is_array($site) ? ($site['theme'] ?? null) : null;
            if (is_string($theme) && isset($renamedThemes[strtolower($theme)])) {
                $data['sites'][$i]['theme'] = $renamedThemes[strtolower($theme)];
            }
        }
        foreach ($data['vocabularies'] ?? [] as $i => $vocabulary) {
            $data['vocabularies'][$i] = $this->bundleVocabulary("vocabularies[{$i}]", $vocabulary, $dir);
        }
        foreach ($data['resourceTemplates'] ?? [] as $i => $template) {
            $data['resourceTemplates'][$i]['source'] = './' . $this->bundleFile(
                "resourceTemplates[{$i}]",
                (string) ($template['source'] ?? ''),
                $dir,
                'resource-templates',
                'json'
            );
        }
        foreach ($data['files'] ?? [] as $i => $file) {
            $data['files'][$i]['source'] = './' . $this->bundleFile("files[{$i}]", (string) ($file['source'] ?? ''), $dir, 'files');
        }

        $this->writeBlueprint($data, $dir);
        return $this->warnings;
    }

    /** @throws Exception When the version is 'dev' or the latest cannot be determined */
    private function omekaVersion(array $data): string
    {
        $version = trim((string) ($data['preferredVersions']['omeka'] ?? ''));
        if ($version === '') {
            $version = trim(($this->latestOmekaVersion)());
            if ($version === '') {
                throw new Exception('Could not determine the latest Omeka S version; set preferredVersions.omeka.');
            }
            $this->progress("Omeka S: using the latest release, {$version}");
        }
        if (strtolower($version) === 'dev') {
            throw new Exception("preferredVersions.omeka is 'dev', which cannot be bundled reproducibly; pin an Omeka S release.");
        }
        return $version;
    }

    /** @throws Exception When an add-on comes from git and git is not installed */
    private function assertGitAvailable(array $data): void
    {
        foreach (['modules', 'themes'] as $key) {
            foreach ($data[$key] ?? [] as $entry) {
                $source = is_array($entry) ? (string) ($entry['source'] ?? '') : '';
                $isGit = str_starts_with($source, 'gh:') || str_starts_with($source, 'git@')
                    || str_ends_with($source, '.git') || str_contains($source, '.git#');
                if ($isGit && !self::hasCommand('git')) {
                    throw new Exception("{$key} '{$entry['name']}' comes from git, but 'git' is not on the PATH.");
                }
            }
        }
    }

    private static function hasCommand(string $command): bool
    {
        exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    private function bundleCore(?string $source, string $omeka, string $dir): string
    {
        $hasSource = $source !== null && $source !== '';
        $target = $this->uniquePath($dir, 'core', $hasSource ? $this->basename($source, 'zip') : "omeka-s-{$omeka}.zip");
        $this->progress("Omeka S core {$omeka}");
        $this->fetch(SourceFields::CORE_SOURCE, $hasSource ? $source : OmekaDotOrgApi::coreReleaseUrl($omeka), "{$dir}/{$target}");
        return $target;
    }

    /**
     * @param array<string,string> $renamedThemes lowercase blueprint name => folder name, filled for renamed themes
     */
    private function bundleAddon(
        string $kind,
        string $label,
        mixed $entry,
        string $omeka,
        bool $allowIncompatible,
        string $dir,
        array &$renamedThemes,
    ): mixed {
        $item = is_string($entry) ? ['name' => $entry] : $entry;
        $name = (string) ($item['name'] ?? '');
        $source = isset($item['source']) && $item['source'] !== '' ? (string) $item['source'] : null;
        $version = isset($item['version']) && $item['version'] !== '' ? (string) $item['version'] : null;
        if ($kind === SourceFields::THEME && $source === null && in_array($name, SelfContainedCheck::CORE_THEMES, true)) {
            return $entry; // ships with the core
        }

        $label = "{$label} '{$name}'";
        $this->progress($label);
        try {
            $resolver = $kind === SourceFields::MODULE ? $this->modules : $this->themes;
            $resolved = $resolver->resolve(AddonResolver::uriFor($name, $source, $version), fn() => $omeka, $allowIncompatible);
            foreach ($resolved->notes as $note) {
                $this->progress("  {$note}");
            }
            $packaged = $this->packager->package($kind, $name, $resolved, $version, $omeka, $allowIncompatible, $dir);
        } catch (Throwable $e) {
            throw new Exception("{$label}: {$e->getMessage()}", 0, $e);
        }
        foreach (array_merge($resolved->warnings, $packaged->warnings) as $warning) {
            $this->warnings[] = "{$label}: {$warning}";
        }
        if ($kind === SourceFields::THEME && $packaged->name !== $name) {
            $renamedThemes[strtolower($name)] = $packaged->name;
        }
        return array_merge($item, ['name' => $packaged->name, 'version' => $packaged->version, 'source' => './' . $packaged->zipPath]);
    }

    private function bundleVocabulary(string $label, array $vocabulary, string $dir): array
    {
        $source = (string) ($vocabulary['source'] ?? '');
        $prefix = (string) ($vocabulary['prefix'] ?? 'vocabulary');
        $label = "{$label} '{$prefix}'";
        $this->progress($label);

        // the file name depends on the format, known only once it is downloaded
        $download = "{$dir}/.vocabulary.download";
        $contentType = $this->fetch($label, $source, $download);
        $format = strtolower(trim((string) ($vocabulary['format'] ?? '')));
        if ($format === '' || $format === 'auto') {
            $format = VocabularyFormat::detect($contentType, $source);
            if ($format !== null) {
                $vocabulary['format'] = $format;
            }
        }
        $target = $this->uniquePath($dir, 'vocabularies', $this->safeName($prefix) . '.' . VocabularyFormat::extension($format, $source));
        if (!rename($download, "{$dir}/{$target}")) {
            throw new Exception("{$label}: could not write {$target}.");
        }
        $vocabulary['source'] = './' . $target;
        return $vocabulary;
    }

    private function bundleFile(string $label, string $source, string $dir, string $subdir, ?string $extension = null): string
    {
        $this->progress("{$label} {$source}");
        $target = $this->uniquePath($dir, $subdir, $this->basename($source, $extension));
        $this->fetch($label, $source, "{$dir}/{$target}");
        return $target;
    }

    /** @throws Exception Naming $label when the source cannot be fetched */
    private function fetch(string $label, string $source, string $target): ?string
    {
        if ($source === '') {
            throw new Exception("{$label}: no source.");
        }
        try {
            return ($this->download)($source, $target);
        } catch (Throwable $e) {
            @unlink($target);
            throw new Exception("{$label}: {$e->getMessage()}", 0, $e);
        }
    }

    /** A free path `$subdir/<name>` under $dir (name, name-2, name-3, ...), relative to $dir. */
    private function uniquePath(string $dir, string $subdir, string $name): string
    {
        $path = "{$dir}/{$subdir}";
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception("Could not create {$path}.");
        }
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        for ($n = 2; file_exists("{$path}/{$candidate}"); $n++) {
            $candidate = "{$stem}-{$n}" . ($extension !== '' ? ".{$extension}" : '');
        }
        return "{$subdir}/{$candidate}";
    }

    /** The safe file name of a source path or URL, with $extension appended when it has none. */
    private function basename(string $source, ?string $extension = null): string
    {
        $path = ResourceFetcher::isUrl($source) ? (string) parse_url($source, PHP_URL_PATH) : $source;
        $name = $this->safeName(basename($path));
        if ($extension !== null && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= ".{$extension}";
        }
        return $name;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        return trim($name, '.') !== '' ? $name : 'source';
    }

    /**
     * $data with $value at the nested $path (a list of keys), creating the objects on the way.
     *
     * @param string[] $path
     */
    private static function withValue(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $data[$key] = $value;
            return $data;
        }
        $data[$key] = self::withValue(is_array($data[$key] ?? null) ? $data[$key] : [], $path, $value);
        return $data;
    }

    private function writeBlueprint(array $data, string $dir): void
    {
        unset($data['$schema']);
        $data = ['$schema' => BlueprintValidator::SCHEMA_ID] + $data;
        foreach (self::OBJECT_KEYS as $key) {
            if (array_key_exists($key, $data) && $data[$key] === []) {
                $data[$key] = new stdClass();
            }
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents("{$dir}/blueprint.json", $json . "\n") === false) {
            throw new Exception("Could not write {$dir}/blueprint.json.");
        }
    }

    private function progress(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }
}
