<?php
namespace OSC\Blueprint;

use Closure;
use Exception;
use OSC\Helper\ResourceFetcher;
use Otar\JSONC;
use OSC\Helper\UrlCredentials;
use OSC\Source\CliSourceResolver;
use OSC\Source\Document;
use OSC\Source\DocumentSourceResolver;
use OSC\Source\GitRepository;
use OSC\Source\Location;
use OSC\Source\SourceException;
use OSC\Source\SourceForm;

/**
 * Load a blueprint from a file or URL, parse it as jsonc, and resolve every `{ "$import": <uri> }`
 * reference into a single, fully inlined blueprint array.
 *
 * References live under the key they extend (`modules`, `themes`, ...): an `$import` entry is
 * replaced in place by the items of the referenced list (itself jsonc, and itself allowed to
 * contain further `$import` entries). Referenced sources are resolved relative to the file/URL that
 * contains them. Circular references are detected and rejected.
 *
 * Sources written in a blueprint (`$import` and the source fields listed in SourceFields) are resolved
 * by DocumentSourceResolver, as the shared spec says: a URL, a git source or a relative path (absolute paths
 * and other schemes are rejected). A relative path resolves against the document that declares it and
 * must stay inside that document's root: the top-level blueprint's folder (or --root) for the
 * blueprint and the files it imports by relative path; the file's own folder for a file imported by
 * an absolute URL or gh:/gl:. So a blueprint can never reach an arbitrary local file.
 *
 * De-duplication: within a resolved list, entries sharing a natural identity (module/theme `name`,
 * file `destination`, vocabulary `namespaceUri`, resource-template `label`, user `email`, site
 * `slug`, item/item-set `title`) collapse into the first occurrence: a later entry is shallow-merged
 * into it and the entry keeps its first position (so module install order is stable). When such a
 * merge actually changes the value, an advisory warning is recorded (see takeWarnings()), so
 * intentional layering keeps working while an accidental duplicate stays visible.
 */
class BlueprintLoader
{
    /** Keys whose value is a list of items that may contain `$import` references. */
    private const LIST_KEYS = ['modules', 'themes', 'files', 'vocabularies', 'resourceTemplates', 'users', 'sites', 'itemSets', 'items'];

    /** Absolute sources currently being resolved, to detect circular imports. */
    private array $visiting = [];

    /** Advisory messages gathered during the current load (e.g. a duplicate that overrode a value). */
    private array $warnings = [];

    /** Fetches a document's content: fn(Location): string. */
    private Closure $fetch;

    /** Self-contained violations gathered during the current load (see takeViolations()). */
    private array $violations = [];

    /** The resolved media values of the current load that are files (see Blueprint::isFileSource()). */
    private array $fileSources = [];

    /** Schema errors of the documents of the current load, prefixed with their label (see takeErrors()). */
    private array $errors = [];

    /** The folder of the top-level blueprint (a directory or a URL ending in `/`), for document labels. */
    private string $labelBase = '';

    /**
     * @param Closure|null           $fetch         Fetches a document: fn(Location): string (default
     *                                              ResourceFetcher::fetch)
     * @param string|null            $rootOverride  Directory local references must stay inside; defaults
     *                                              to the directory of the top-level blueprint
     * @param bool                   $selfContained Record (instead of following) every reference that is
     *                                              not a relative path inside the blueprint; see takeViolations()
     * @param DocumentValidator|null $validator     Validates each document as written, right after it is
     *                                              fetched; an invalid one is not resolved further (its
     *                                              sources and imports are left alone). See takeErrors()
     */
    public function __construct(
        ?Closure $fetch = null,
        private ?string $rootOverride = null,
        private bool $selfContained = false,
        private ?DocumentValidator $validator = null,
    ) {
        $this->fetch = $fetch ?? fn(Location $location): string => ResourceFetcher::fetch($location);
    }

    /**
     * Load and fully resolve a blueprint.
     *
     * @param string $source Path or URL to the blueprint (a repo-aware reference is accepted too)
     * @return Blueprint The normalized, import-resolved blueprint
     * @throws Exception On fetch/parse errors, circular imports or a reference outside the root
     */
    public function load(string $source): Blueprint
    {
        $document = $this->begin($source);
        $source = $document->source->value();
        return $this->guarded($document->source, function () use ($document, $source) {
            $blueprint = $this->decodeObject(($this->fetch)($document->source), $source);
            $this->fileSources = [];
            if (!$this->isValid($blueprint, null, $source)) {
                return new Blueprint($blueprint);
            }
            $resolved = $this->resolve($blueprint, $document);
            return new Blueprint($resolved, array_values(array_unique($this->fileSources)));
        });
    }

    /**
     * The schema errors of the most recent load()/loadPartial() (with a validator), then cleared: one
     * "<document>: <json pointer>: <message>" per error, the document named relative to the top-level
     * blueprint's folder (or by its URL). An invalid document was not resolved further.
     *
     * @return string[]
     */
    public function takeErrors(): array
    {
        $errors = $this->errors;
        $this->errors = [];
        return $errors;
    }

    /**
     * Validate a document as written (when a validator is set), recording its errors under its label.
     *
     * @param string|null $type Null for the blueprint, else the list key or 'settings'
     */
    private function isValid(mixed $data, ?string $type, string $source): bool
    {
        if ($this->validator === null) {
            return true;
        }
        $label = $this->label($source);
        $errors = $this->validator->validateDocument($data, $type);
        foreach ($errors as $error) {
            $this->errors[] = "{$label}: {$error}";
        }
        return $errors === [];
    }

    /** A document's name in messages: relative to the top-level blueprint's folder, else its URL. */
    private function label(string $source): string
    {
        if ($this->labelBase !== '' && str_starts_with($source, $this->labelBase)) {
            return substr($source, strlen($this->labelBase));
        }
        return UrlCredentials::redact($source);
    }

    /**
     * Advisory messages collected during the most recent load()/loadPartial(), then cleared.
     *
     * Currently: a notice that a later entry (often from an $import) updated an earlier one with a
     * different value. A duplicate that re-declares an identical value is not reported.
     *
     * @return string[]
     */
    public function takeWarnings(): array
    {
        $warnings = array_values(array_unique($this->warnings));
        $this->warnings = [];
        return $warnings;
    }

    /**
     * In self-contained mode: the references of the most recent load()/loadPartial() that are not a
     * relative path inside the blueprint (a URL, a repo reference, a git source, or a path leaving
     * the blueprint's URL), then cleared. Such a reference is skipped, never fetched.
     *
     * @return string[]
     */
    public function takeViolations(): array
    {
        $violations = array_values(array_unique($this->violations));
        $this->violations = [];
        return $violations;
    }

    /** Decode jsonc content (comments and trailing commas allowed), throwing on invalid JSON. */
    private function decode(string $content): mixed
    {
        return JSONC::decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Load and resolve a standalone partial (a bare list, or the settings map/list).
     *
     * @param string $source Path or URL to the partial
     * @param string $type   One of the list keys, or 'settings'
     * @return mixed The resolved list (or settings map)
     * @throws Exception
     */
    public function loadPartial(string $source, string $type): mixed
    {
        $document = $this->begin($source);
        $source = $document->source->value();
        return $this->guarded($document->source, function () use ($document, $type, $source) {
            $data = $this->decode(($this->fetch)($document->source));
            if ($type === 'settings') {
                return $this->isValid($data, $type, $source) ? $this->resolveSettings($data, $document) : $data;
            }
            $list = $this->asList($data, $source);
            return $this->isValid($list, $type, $source) ? $this->resolveList($list, $document, $type) : $list;
        });
    }

    /**
     * Reset the per-load state and resolve the top-level source. The top-level source is given by the
     * user, not written in a blueprint, so it may be absolute; its folder (local or URL) is the root,
     * unless --root gives a local one.
     */
    private function begin(string $source): Document
    {
        $this->warnings = [];
        $this->visiting = [];
        $this->violations = [];
        $this->errors = [];
        $document = Document::open(CliSourceResolver::file($source, getcwd()), $this->rootOverride);
        $this->labelBase = rtrim($document->source->folder(), '/') . '/';
        return $document;
    }

    /** Decode jsonc content that must be a JSON object (not a list); $source is used for errors only. */
    private function decodeObject(string $content, string $source): array
    {
        $data = $this->decode($content);
        if (!is_array($data) || array_is_list($data)) {
            throw new Exception("Blueprint '" . UrlCredentials::redact($source) . "' must be a JSON object.");
        }
        return $data;
    }

    /** A decoded list source as a list: a single item (an object) becomes a one-element list. */
    private function asList(mixed $data, string $source): array
    {
        if (!is_array($data)) {
            throw new Exception("'" . UrlCredentials::redact($source) . "' must be a JSON array or object.");
        }
        return array_is_list($data) ? $data : [$data];
    }

    private function resolve(array $blueprint, Document $source): array
    {
        foreach (self::LIST_KEYS as $key) {
            if (isset($blueprint[$key]) && is_array($blueprint[$key])) {
                $blueprint[$key] = $this->resolveList($blueprint[$key], $source, $key);
            }
        }
        if (isset($blueprint['settings'])) {
            $blueprint['settings'] = $this->resolveSettings($blueprint['settings'], $source);
        }
        foreach (SourceFields::objects() as $path => $fields) {
            $blueprint = $this->resolveObject($blueprint, explode('.', $path), $source, $fields, $path);
        }
        // lists inside an object: resolved entry by entry (no $import, no de-duplication)
        foreach (SourceFields::dottedLists() as $path) {
            $blueprint = $this->resolveDottedList($blueprint, explode('.', $path), $source, $path);
        }
        return $blueprint;
    }

    /**
     * Resolve the source fields of every entry of the list at $path (a list of keys) inside $data.
     *
     * @param string[] $path Keys leading to the list
     * @throws Exception
     */
    private function resolveDottedList(array $data, array $path, Document $source, string $label): array
    {
        $key = array_shift($path);
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return $data;
        }
        if ($path !== []) {
            $data[$key] = $this->resolveDottedList($data[$key], $path, $source, $label);
            return $data;
        }
        if (array_is_list($data[$key])) {
            foreach ($data[$key] as $i => $entry) {
                if (is_array($entry)) {
                    $data[$key][$i] = $this->resolveFields($entry, $source, SourceFields::forList($label), "{$label}[{$i}]");
                }
            }
        }
        return $data;
    }

    /**
     * Resolve the source fields of the object at $path (a list of keys) inside $data.
     *
     * @param string[]             $path   Keys leading to the object
     * @param array<string,string> $fields field => kind (see SourceFields)
     * @param string               $label  The dotted object path, for messages
     * @throws Exception
     */
    private function resolveObject(array $data, array $path, Document $source, array $fields, string $label): array
    {
        $key = array_shift($path);
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return $data;
        }
        $data[$key] = $path === []
            ? $this->resolveFields($data[$key], $source, $fields, $label)
            : $this->resolveObject($data[$key], $path, $source, $fields, $label);
        return $data;
    }

    /**
     * Resolve every `$import` in a list and de-duplicate the result.
     *
     * @param array  $list   The raw list (inline items and/or references)
     * @param string $source The source that contains the list (for relative resolution)
     * @param string $key    The list key (for identity/de-duplication)
     * @return array
     * @throws Exception
     */
    private function resolveList(array $list, Document $source, string $key): array
    {
        $resolved = [];
        foreach ($list as $entry) {
            if (!$this->isReference($entry)) {
                // an inline item is declared by `$source`, so its relative asset refs resolve against it
                $resolved[] = $this->resolveItemAssets($entry, $source, $key);
                continue;
            }

            // items pulled in by an import were already asset-resolved against the imported source
            $imported = $this->import(
                $entry['$import'],
                $source,
                $key,
                fn(array $data, Document $ref) => $this->resolveList($data, $ref, $key)
            );
            foreach ($imported as $item) {
                $resolved[] = $item;
            }
        }
        return $this->dedupe($resolved, $key);
    }

    /**
     * Resolve the settings value into a single flat map of id => value.
     *
     * Accepts an inline map, or a list of maps/references merged in order (later values win).
     *
     * @param mixed  $settings
     * @param string $source
     * @return array
     * @throws Exception
     */
    private function resolveSettings(mixed $settings, Document $source): array
    {
        if (!is_array($settings)) {
            throw new Exception("The 'settings' value in '" . UrlCredentials::redact($source->source->value()) . "' must be an object or an array.");
        }

        // inline map
        if (!array_is_list($settings)) {
            return $settings;
        }

        // list of maps / references, merged in order
        $merged = [];
        foreach ($settings as $entry) {
            if ($this->isReference($entry)) {
                $imported = $this->import(
                    $entry['$import'],
                    $source,
                    'settings',
                    fn(mixed $data, Document $ref) => $this->resolveSettings($data, $ref)
                );
                $merged = array_merge($merged, $imported);
                continue;
            }
            if (!is_array($entry) || array_is_list($entry)) {
                throw new Exception("Each entry of a 'settings' list must be a map or an \$import reference.");
            }
            $merged = array_merge($merged, $entry);
        }
        return $merged;
    }

    /**
     * The single path for an `$import`: resolve the reference against the document that contains it,
     * guard against cycles, then fetch and decode the file and hand it, as a Document with its root,
     * to $resolve.
     *
     * An imported file that is not valid as written is reported (see takeErrors()) and left out.
     *
     * @param mixed    $raw     The `$import` value
     * @param Document $base    The document containing the reference
     * @param string   $type    The key it is imported under: a list key, or 'settings'
     * @param callable $resolve fn(mixed $data, Document $document): mixed; a list arrives as a list
     * @throws Exception
     */
    private function import(mixed $raw, Document $base, string $type, callable $resolve): mixed
    {
        if (!is_string($raw)) {
            throw new Exception("An \$import in '" . UrlCredentials::redact($base->source->value()) . "' must be a string.");
        }
        $location = $this->locate($raw, $base, '$import');
        if ($location === null) {
            return []; // a self-contained violation: recorded, not fetched
        }
        assert($location instanceof Location);
        $ref = $location->value();
        $document = new Document($location, DocumentSourceResolver::importRoot($raw, $location, $base));
        return $this->guarded($location, function () use ($ref, $type, $resolve, $document) {
            $data = $this->decode(($this->fetch)($document->source));
            if ($type !== 'settings') {
                $data = $this->asList($data, $ref);
            }
            return $this->isValid($data, $type, $ref) ? $resolve($data, $document) : [];
        });
    }

    /**
     * Run $fn with $source on the stack of sources being resolved, rejecting a source that is already
     * on it (a circular import).
     *
     * @throws Exception
     */
    private function guarded(Location $source, callable $fn): mixed
    {
        $guard = $this->guardKey($source);
        if (isset($this->visiting[$guard])) {
            throw new Exception("Circular \$import detected at '" . UrlCredentials::redact($source->value()) . "'.");
        }
        $this->visiting[$guard] = true;
        try {
            return $fn();
        } finally {
            unset($this->visiting[$guard]);
        }
    }

    /**
     * Turn a source written in a blueprint into a fetchable path or URL (see DocumentSourceResolver). In
     * self-contained mode a source that is not a relative path (a URL, a git source) is recorded as a
     * violation and null is returned, so the caller skips it.
     *
     * @param string $label      What the source is, for messages (e.g. "$import", "modules 'Log' source")
     * @param bool   $allowRepository Whether a git repository is allowed (module and theme sources)
     * @throws SourceException
     */
    private function reference(string $raw, Document $base, string $label, bool $allowRepository = false): ?string
    {
        return $this->locate($raw, $base, $label, $allowRepository)?->value();
    }

    /**
     * reference(), typed: a Location, or a GitRepository where $allowRepository allows one; null for a
     * self-contained violation.
     *
     * @throws SourceException
     */
    private function locate(string $raw, Document $base, string $label, bool $allowRepository = false): Location|GitRepository|null
    {
        $form = SourceForm::of($raw);
        if ($this->selfContained && $form !== null && $form !== SourceForm::RelativePath) {
            $this->violation($label, UrlCredentials::redact($raw), UrlCredentials::redact($base->source->value()), 'is not a relative path inside the blueprint');
            return null;
        }
        try {
            return $allowRepository ? DocumentSourceResolver::addon($raw, $base) : DocumentSourceResolver::file($raw, $base);
        } catch (SourceException $e) {
            throw new SourceException("{$label}: " . $e->getMessage(), $e->reason);
        }
    }

    /** Record a self-contained violation; returns null so the caller skips the reference. */
    private function violation(string $label, string $raw, string $base, string $problem): ?string
    {
        $this->violations[] = "{$label} '{$raw}' in '{$base}' {$problem}.";
        return null;
    }

    /**
     * Resolve an inline item's source fields (see SourceFields) against the source that declares it.
     *
     * @param mixed  $entry  The inline item
     * @param string $source The source declaring the item (for relative resolution)
     * @param string $key    The list key (selects which fields are sources)
     * @return mixed The item with its source fields resolved
     * @throws Exception
     */
    private function resolveItemAssets(mixed $entry, Document $source, string $key): mixed
    {
        if (!is_array($entry)) {
            return $entry;
        }
        $name = $this->identityLabel($entry, $key);
        return $this->resolveFields($entry, $source, SourceFields::forList($key), $name !== '' ? "{$key} '{$name}'" : $key);
    }

    /**
     * Resolve the source fields of an item or object against the document that declares it. A
     * gh:/gl: file becomes a raw URL; a URL passes through; an add-on's git repository is left to the
     * download commands. In self-contained mode, anything but a relative path is a violation.
     *
     * @param array                $entry  The item or object
     * @param string               $source The source declaring it (for relative resolution)
     * @param array<string,string> $fields field => kind (see SourceFields)
     * @param string               $label  What the entry is, for messages (e.g. "modules 'Log'")
     * @throws Exception
     */
    private function resolveFields(array $entry, Document $source, array $fields, string $label): array
    {
        foreach ($fields as $field => $kind) {
            // a field `a[].b`: the field `b` of every element of the list `a`
            if (str_contains($field, '[].')) {
                [$list, $inner] = explode('[].', $field, 2);
                if (isset($entry[$list]) && is_array($entry[$list]) && array_is_list($entry[$list])) {
                    foreach ($entry[$list] as $i => $element) {
                        if (is_array($element)) {
                            $entry[$list][$i] = $this->resolveNested($element, $source, $inner, $kind, "{$label} {$list}[{$i}]");
                        }
                    }
                }
                continue;
            }
            // an empty source is left to the schema (blueprint:export writes one as a placeholder)
            if (!isset($entry[$field]) || !is_string($entry[$field]) || $entry[$field] === '') {
                continue;
            }
            $ref = $this->reference($entry[$field], $source, "{$label} {$field}", SourceFields::isAddon($kind));
            if ($ref !== null) {
                $entry[$field] = $ref;
            }
        }
        return $entry;
    }

    /**
     * Resolve one field of a nested element. A media field is a source only for a file (an upload);
     * a link the ingester keeps is left as it is, and is never a self-contained violation.
     */
    private function resolveNested(array $element, Document $source, string $field, string $kind, string $label): array
    {
        $value = $element[$field] ?? null;
        if (!is_string($value) || $value === '') {
            return $element;
        }
        if ($kind === SourceFields::MEDIA && !SourceFields::isMediaFile($element, $field)) {
            return $element;
        }
        $ref = $this->reference($value, $source, "{$label}.{$field}");
        if ($ref !== null) {
            $element[$field] = $ref;
            if ($kind === SourceFields::MEDIA) {
                $this->fileSources[] = $ref;
            }
        }
        return $element;
    }

    private function isReference(mixed $entry): bool
    {
        return is_array($entry) && array_key_exists('$import', $entry);
    }

    private function guardKey(Location $source): string
    {
        // a local path by its real path, so a file reached through a symlink is the same file
        return $source->isRemote() ? $source->value() : (realpath($source->value()) ?: $source->value());
    }

    /**
     * Collapse entries with the same natural identity into the first occurrence: a later entry is
     * shallow-merged into it, and the entry keeps its first position. Entries without an identity keep
     * their place.
     *
     * @param array  $list
     * @param string $key
     * @return array
     */
    private function dedupe(array $list, string $key): array
    {
        $result = [];
        $positions = []; // identity => index in $result
        foreach ($list as $entry) {
            $id = $this->identity($entry, $key);
            if ($id === '') {
                $result[] = $entry;
                continue;
            }
            if (!array_key_exists($id, $positions)) {
                $positions[$id] = count($result);
                $result[] = $entry;
                continue;
            }

            $position = $positions[$id];
            [$earlier, $merged] = $this->merge($result[$position], $entry);
            // a later entry that changes the value updates the earlier one; record it so an accidental
            // duplicate is visible while intentional layering still works
            if ($merged != $earlier) {
                $label = $this->identityLabel($entry, $key);
                $this->warnings[] = "{$key}: '{$label}' is declared more than once; the later definition updates the earlier one.";
            }
            $result[$position] = $merged;
        }
        return $result;
    }

    /**
     * Shallow-merge a later duplicate into an earlier one. A bare string (module/theme name) is
     * normalized to `['name' => ...]` when the other side is an object; two strings keep the later one.
     *
     * @return array{0:mixed,1:mixed} The (normalized) earlier entry and the merged entry
     */
    private function merge(mixed $earlier, mixed $later): array
    {
        if (!is_array($earlier) && !is_array($later)) {
            return [$earlier, $later];
        }
        $earlier = is_array($earlier) ? $earlier : ['name' => $earlier];
        $later = is_array($later) ? $later : ['name' => $later];
        return [$earlier, array_merge($earlier, $later)];
    }

    private function identity(mixed $entry, string $key): string
    {
        return strtolower($this->identityLabel($entry, $key));
    }

    /** The natural-identity value of an entry, in its original casing (for messages). */
    private function identityLabel(mixed $entry, string $key): string
    {
        if (is_string($entry)) {
            // bare string form (module/theme name)
            return $entry;
        }
        if (!is_array($entry)) {
            return '';
        }
        $field = match ($key) {
            'modules', 'themes'   => $entry['name'] ?? '',
            'files'               => $entry['destination'] ?? '',
            'vocabularies'        => $entry['namespaceUri'] ?? '',
            'resourceTemplates'   => $entry['label'] ?? $entry['source'] ?? '',
            'users'               => $entry['email'] ?? '',
            'sites'               => $entry['slug'] ?? $entry['title'] ?? '',
            'itemSets', 'items'   => $entry['title'] ?? '',
            default               => '',
        };
        return is_string($field) ? $field : '';
    }
}
