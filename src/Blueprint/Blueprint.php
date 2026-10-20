<?php
namespace OSC\Blueprint;

/**
 * A resolved blueprint: the fully loaded, import-inlined description of an Omeka S environment.
 *
 * Wraps the decoded array behind named accessors so callers do not poke at raw keys. Produced by
 * {@see BlueprintLoader::load()} and consumed by the validator, the core installer and the applier.
 */
class Blueprint
{
    /**
     * @param string[] $fileSources The resolved media values that are files to fetch (see isFileSource())
     */
    public function __construct(private array $data, private array $fileSources = [])
    {
    }

    /**
     * Whether a resolved media value is a file the blueprint ships, rather than a link. Recorded by the
     * loader before resolution: a relative simple media `url` of a remote blueprint resolves to a URL,
     * yet is a file next to the blueprint (and so is bundled).
     */
    public function isFileSource(string $value): bool
    {
        return in_array($value, $this->fileSources, true);
    }

    /** The raw, import-resolved array (for schema validation and serialization). */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<int,mixed> */
    public function modules(): array
    {
        return $this->data['modules'] ?? [];
    }

    /** @return array<int,mixed> */
    public function themes(): array
    {
        return $this->data['themes'] ?? [];
    }

    /** @return array<int,mixed> */
    public function files(): array
    {
        return $this->data['files'] ?? [];
    }

    /** @return array<int,mixed> */
    public function vocabularies(): array
    {
        return $this->data['vocabularies'] ?? [];
    }

    /** @return array<int,mixed> */
    public function resourceTemplates(): array
    {
        return $this->data['resourceTemplates'] ?? [];
    }

    /** @return array<int,mixed> */
    public function users(): array
    {
        return $this->data['users'] ?? [];
    }

    /** @return array<int,mixed> */
    public function sites(): array
    {
        return $this->data['sites'] ?? [];
    }

    /** @return array<int,array> The shared format's simple item sets */
    public function itemSets(): array
    {
        return $this->data['itemSets'] ?? [];
    }

    /** @return array<int,array> The shared format's simple items */
    public function items(): array
    {
        return $this->data['items'] ?? [];
    }

    /** @return array<int,array> The CLI's item-set entries ({source, title?}), see SourceFields::EXTENSION_ITEM_SETS */
    public function extensionItemSets(): array
    {
        return $this->path(SourceFields::EXTENSION_ITEM_SETS) ?? [];
    }

    /** @return array<int,array> The CLI's item entries ({source, title?, media?}), see SourceFields::EXTENSION_ITEMS */
    public function extensionItems(): array
    {
        return $this->path(SourceFields::EXTENSION_ITEMS) ?? [];
    }

    /** The value at a dotted path, or null when a key is missing. */
    private function path(string $dotted): mixed
    {
        $value = $this->data;
        foreach (explode('.', $dotted) as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->data['settings'] ?? [];
    }

    /** @return array<string,mixed> Install-time values: title, locale, timezone and admin */
    public function install(): array
    {
        return $this->data['install'] ?? [];
    }

    /**
     * The Omeka S release ZIP to install from (resolved path or URL), or null to download the release.
     * Read from SourceFields::CORE_SOURCE.
     */
    public function installSource(): ?string
    {
        $value = $this->data;
        foreach (explode('.', SourceFields::CORE_SOURCE) as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function preferredOmekaVersion(): ?string
    {
        return $this->data['preferredVersions']['omeka'] ?? null;
    }

    /**
     * Whether any module is to be installed or activated (as opposed to only downloaded). Drives the
     * cross-process boundary in the deploy command.
     */
    public function hasInstallableModules(): bool
    {
        foreach ($this->modules() as $module) {
            if (is_string($module)) {
                return true; // default state is activate
            }
            $state = $module['state'] ?? 'activate';
            if (in_array($state, ['install', 'activate'], true)) {
                return true;
            }
        }
        return false;
    }
}
