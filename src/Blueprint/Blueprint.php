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
    public function __construct(private array $data)
    {
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

    /** @return array<string,mixed> */
    public function settings(): array
    {
        return $this->data['settings'] ?? [];
    }

    /** @return array<string,mixed> */
    public function siteOptions(): array
    {
        return $this->data['siteOptions'] ?? [];
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
