<?php
namespace OSC\Blueprint\Bundle;

use Closure;
use Composer\Semver\Semver;
use Exception;
use InvalidArgumentException;
use OSC\Blueprint\Blueprint;
use OSC\Blueprint\BlueprintValidator;
use OSC\Blueprint\SelfContainedCheck;
use OSC\Blueprint\SourceFields;
use OSC\Downloader\AddonResolver;
use OSC\Downloader\ResolvedAddon;
use OSC\Downloader\ZipDownloader;
use OSC\Helper\ModuleNamespace;
use OSC\Helper\Path;
use OSC\Helper\ResourceFetcher;
use OSC\Omeka\OmekaDotOrgApi;
use stdClass;
use Throwable;

/**
 * Fetch every source a blueprint needs into one directory, and write a rewritten `blueprint.json`
 * there whose sources are relative paths inside it. The bundle then deploys without the catalogs or
 * remote URLs the original refers to (see SourceFields for where sources live).
 *
 * Add-ons are bundled as their release ZIPs, unchanged, after checking that each fits the target
 * Omeka S version and that a module's namespace is its name. A git source is refused: a checkout may
 * need build steps before it can be installed.
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

    /**
     * Asked for when downloading a vocabulary: a namespace URL often serves HTML to a browser and RDF
     * only when asked for it, as the Omeka S importer does.
     */
    private const VOCABULARY_ACCEPT = 'Accept: text/turtle, application/rdf+xml;q=0.9, application/ld+json;q=0.8, '
        . 'application/n-triples;q=0.7, */*;q=0.1';

    /** Content types of a web page, which is never a vocabulary. */
    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    /** @var Closure(string, string, string[]): ?string */
    private Closure $download;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param Closure(): string                         $latestOmekaVersion The latest Omeka S release
     * @param (Closure(string, string, string[]): ?string)|null $download fn(source, target, headers): Content-Type; copies or downloads a source
     * @param (Closure(string): void)|null              $progress Receives a line per source being bundled
     */
    public function __construct(
        private AddonResolver $modules,
        private AddonResolver $themes,
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
        $this->assertNoGitSources($data);

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
        $data = $this->bundleContent($data, $dir, $blueprint);

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

    /**
     * Refuse a git source before anything is downloaded: a bundle holds release ZIPs, and a git
     * checkout may need build steps before it can be installed.
     *
     * @throws Exception Naming the first add-on with a git source, or with a source that is not an add-on reference
     */
    private function assertNoGitSources(array $data): void
    {
        foreach (['modules', 'themes'] as $key) {
            foreach ($data[$key] ?? [] as $i => $entry) {
                if (!is_array($entry) || !isset($entry['source']) || $entry['source'] === '') {
                    continue;
                }
                $name = (string) ($entry['name'] ?? '');
                $version = isset($entry['version']) && $entry['version'] !== '' ? (string) $entry['version'] : null;
                try {
                    $isGit = AddonResolver::isGit(AddonResolver::uriFor($name, (string) $entry['source'], $version));
                } catch (InvalidArgumentException $e) {
                    throw new Exception("{$key}[{$i}] '{$name}': {$e->getMessage()}", 0, $e);
                }
                if ($isGit) {
                    throw new Exception(
                        "{$key}[{$i}] '{$name}': a git source can't be bundled; a bundle holds release ZIPs. "
                        . 'Point source to a release ZIP (URL or path).'
                    );
                }
            }
        }
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
            [$folder, $addonVersion, $zipPath, $warnings] = $this->copyRelease($kind, $name, $resolved, $version, $omeka, $allowIncompatible, $dir);
        } catch (Throwable $e) {
            throw new Exception("{$label}: {$e->getMessage()}", 0, $e);
        }
        foreach (array_merge($resolved->warnings, $warnings) as $warning) {
            $this->warnings[] = "{$label}: {$warning}";
        }
        if ($kind === SourceFields::THEME && $folder !== $name) {
            $renamedThemes[strtolower($name)] = $folder;
        }
        return array_merge($item, ['name' => $folder, 'version' => $addonVersion, 'source' => './' . $zipPath]);
    }

    /**
     * Download an add-on's release ZIP, check it, and save it unchanged as
     * `<modules|themes>/<Folder>-<version>.zip`. <Folder> is the add-on's folder name: a module's
     * namespace (Omeka requires it to be the folder), a catalog theme's dirname, else the blueprint name.
     *
     * @return array{string, string, string, string[]} [folder, version, ZIP path relative to $dir, warnings]
     * @throws Exception About the add-on (the caller adds which entry it is)
     */
    private function copyRelease(
        string $kind,
        string $name,
        ResolvedAddon $resolved,
        ?string $pinnedVersion,
        string $omeka,
        bool $allowIncompatible,
        string $dir,
    ): array {
        $download = "{$dir}/.addon.download";
        try {
            $source = $resolved->downloader->getDownloadUrl();
            ($this->download)($source, $download, []);
            $addon = $this->readRelease($kind, $download, $source);

            $warnings = [];
            if ($pinnedVersion !== null && $resolved->form === ResolvedAddon::ZIP
                && self::normalizeVersion($pinnedVersion) !== self::normalizeVersion($addon['version'])) {
                $warnings[] = "version {$pinnedVersion} is pinned, but the ZIP contains {$addon['version']}; the ZIP wins";
            }
            $incompatibility = self::incompatibility($addon['constraint'], $omeka);
            if ($incompatibility !== null) {
                if (!$allowIncompatible) {
                    throw new Exception("{$incompatibility}; use --allow-incompatible to bundle it anyway");
                }
                $warnings[] = $incompatibility;
            }

            if ($kind === SourceFields::MODULE) {
                if ($addon['namespace'] === null) {
                    throw new Exception('no namespace found in Module.php');
                }
                if (strcasecmp($addon['namespace'], $name) !== 0) {
                    throw new Exception(
                        "Module.php declares namespace '{$addon['namespace']}', but the blueprint name is '{$name}'; "
                        . "the name must be the module's folder name"
                    );
                }
                $folder = $addon['namespace'];
            } else {
                // a catalog theme lives in the catalog's folder; a sourced theme in the blueprint name
                $folder = $resolved->dirname ?? $name;
            }
            if ($folder !== $name) {
                $warnings[] = "renamed to '{$folder}', its folder name";
            }

            $zipPath = ($kind === SourceFields::MODULE ? 'modules' : 'themes')
                . "/{$folder}-" . preg_replace('/[^A-Za-z0-9._-]/', '_', $addon['version']) . '.zip';
            $zipDir = dirname("{$dir}/{$zipPath}");
            if (!is_dir($zipDir) && !mkdir($zipDir, 0777, true) && !is_dir($zipDir)) {
                throw new Exception("could not create {$zipDir}");
            }
            if (!rename($download, "{$dir}/{$zipPath}")) {
                throw new Exception("could not write {$zipPath}");
            }
            return [$folder, $addon['version'], $zipPath, $warnings];
        } finally {
            if (is_file($download)) {
                @unlink($download);
            }
        }
    }

    /**
     * Unpack a downloaded release ZIP with ZipDownloader::extract(), as a deploy does, and read the
     * module or theme in it. The folder is found with Path::findSubpath(), the search a deploy uses
     * after unpacking the same ZIP.
     *
     * @return array{version: string, constraint: string, namespace: ?string} namespace is null for a theme
     * @throws Exception About the ZIP, which messages call $source
     */
    private function readRelease(string $kind, string $zipPath, string $source): array
    {
        $tmp = ZipDownloader::extract($zipPath, $source);
        try {
            return $kind === SourceFields::MODULE ? self::readModule($tmp, $source) : self::readTheme($tmp, $source) + ['namespace' => null];
        } finally {
            Path::removeFolder($tmp);
        }
    }

    /** @return array{version: string, constraint: string, namespace: ?string} */
    private static function readModule(string $tmp, string $source): array
    {
        $root = Path::findSubpath($tmp, 'config/module.ini');
        if ($root === null) {
            throw new Exception("no config/module.ini found in {$source}");
        }
        $moduleSrc = is_file("{$root}/Module.php") ? file_get_contents("{$root}/Module.php") : false;
        return self::readIni($root, 'module.ini') + [
            'namespace' => $moduleSrc !== false ? ModuleNamespace::fromModulePhp($moduleSrc) : null,
        ];
    }

    /** @return array{version: string, constraint: string} */
    private static function readTheme(string $tmp, string $source): array
    {
        $root = Path::findSubpath($tmp, 'config/theme.ini');
        if ($root === null) {
            throw new Exception("no config/theme.ini found in {$source}");
        }
        return self::readIni($root, 'theme.ini');
    }

    /**
     * The version and Omeka S constraint ('' when absent) in an add-on's config/$iniName.
     *
     * @return array{version: string, constraint: string}
     */
    private static function readIni(string $root, string $iniName): array
    {
        $ini = @parse_ini_file("{$root}/config/{$iniName}", true);
        $info = is_array($ini) ? ($ini['info'] ?? $ini) : [];
        $version = trim((string) ($info['version'] ?? ''));
        if ($version === '') {
            throw new Exception("config/{$iniName} has no version");
        }
        return ['version' => $version, 'constraint' => trim((string) ($info['omeka_version_constraint'] ?? ''))];
    }

    /** Why the add-on does not fit the target Omeka S version, or null when it does (or has no constraint). */
    private static function incompatibility(string $constraint, string $omekaVersion): ?string
    {
        if ($constraint === '') {
            return null;
        }
        try {
            $compatible = Semver::satisfies($omekaVersion, $constraint);
        } catch (Throwable) {
            return "has an unreadable Omeka S version constraint '{$constraint}'";
        }
        return $compatible ? null : "requires Omeka S {$constraint}, but the bundle targets {$omekaVersion}";
    }

    private static function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private function bundleVocabulary(string $label, array $vocabulary, string $dir): array
    {
        $source = (string) ($vocabulary['source'] ?? '');
        $prefix = (string) ($vocabulary['prefix'] ?? 'vocabulary');
        $label = "{$label} '{$prefix}'";
        $this->progress($label);

        // the file name depends on the format, known only once it is downloaded
        $download = "{$dir}/.vocabulary.download";
        $contentType = $this->fetch($label, $source, $download, [self::VOCABULARY_ACCEPT]);
        if ($contentType !== null && in_array($contentType, self::HTML_TYPES, true)) {
            @unlink($download);
            throw new Exception("{$label}: {$source} returned an HTML page, not RDF; use the URL of the vocabulary file.");
        }
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

    /**
     * Item sets, items and their media: the CLI's JSON-LD entries go to item-sets/ and items/, every
     * media file (an upload, or a simple media url that is not an http(s) URL) to media/. A link the
     * ingester keeps (url, iiif, youtube, html, ...) is left as it is.
     */
    private function bundleContent(array $data, string $dir, Blueprint $blueprint): array
    {
        foreach ($data['items'] ?? [] as $i => $item) {
            $data['items'][$i] = $this->bundleMediaList(is_array($item) ? $item : [], 'url', "items[{$i}]", $dir, $blueprint);
        }
        $lists = [SourceFields::EXTENSION_ITEM_SETS => 'item-sets', SourceFields::EXTENSION_ITEMS => 'items'];
        foreach ($lists as $path => $subdir) {
            [$object, $key] = explode('.', $path);
            foreach ($data[$object][$key] ?? [] as $i => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $label = "{$path}[{$i}]";
                // a title-only entry has no JSON-LD file, only media
                if (isset($entry['source'])) {
                    $entry['source'] = './' . $this->bundleFile($label, (string) $entry['source'], $dir, $subdir, 'jsonld');
                }
                $data[$object][$key][$i] = $this->bundleMediaList($entry, 'source', $label, $dir, $blueprint);
            }
        }
        return $data;
    }

    /**
     * $entry with each media file in its `media` list bundled, the media's $field pointing to it. A file
     * is an upload, a simple media url that is not an http(s) URL, or a value the loader recorded as a
     * file (a relative simple media url of a remote blueprint, resolved to a URL).
     */
    private function bundleMediaList(array $entry, string $field, string $label, string $dir, Blueprint $blueprint): array
    {
        foreach ($entry['media'] ?? [] as $j => $media) {
            if (!is_array($media) || !is_string($media[$field] ?? null)) {
                continue;
            }
            if (SourceFields::isMediaFile($media, $field) || (!isset($media['ingester']) && $blueprint->isFileSource($media[$field]))) {
                $entry['media'][$j][$field] = './' . $this->bundleMedia("{$label}.media[{$j}]", $media[$field], $dir);
            }
        }
        return $entry;
    }

    /**
     * Fetch a media file into media/. A name without an extension gets one from the Content-Type, since
     * Omeka S checks the extension; when the type is unknown too, the name is kept, with a warning.
     */
    private function bundleMedia(string $label, string $source, string $dir): string
    {
        $this->progress("{$label} {$source}");
        $name = $this->basename($source);
        if (pathinfo($name, PATHINFO_EXTENSION) !== '') {
            $target = $this->uniquePath($dir, 'media', $name);
            $this->fetch($label, $source, "{$dir}/{$target}");
            return $target;
        }

        // the name depends on the content type, known only once it is downloaded
        $download = "{$dir}/.media.download";
        $extension = MediaExtension::fromContentType($this->fetch($label, $source, $download));
        if ($extension === null) {
            $this->warnings[] = "{$label}: no file extension; Omeka may reject the file";
        }
        $target = $this->uniquePath($dir, 'media', $extension !== null ? "{$name}.{$extension}" : $name);
        if (!rename($download, "{$dir}/{$target}")) {
            @unlink($download);
            throw new Exception("{$label}: could not write {$target}.");
        }
        return $target;
    }

    private function bundleFile(string $label, string $source, string $dir, string $subdir, ?string $extension = null): string
    {
        $this->progress("{$label} {$source}");
        $target = $this->uniquePath($dir, $subdir, $this->basename($source, $extension));
        $this->fetch($label, $source, "{$dir}/{$target}");
        return $target;
    }

    /**
     * @param string[] $headers Request headers for a URL source
     * @throws Exception Naming $label when the source cannot be fetched
     */
    private function fetch(string $label, string $source, string $target, array $headers = []): ?string
    {
        if ($source === '') {
            throw new Exception("{$label}: no source.");
        }
        try {
            return ($this->download)($source, $target, $headers);
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
