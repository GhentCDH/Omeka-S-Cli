<?php

namespace OSC\Settings;

use InvalidArgumentException;
use OSC\Helper\ModuleSettingsMapper;
use OSC\Helper\Slug;

/**
 * The settings of one scope (global / site / user) plus the metadata needed to import them back —
 * the in-memory form of what becomes one exported file once serialized.
 *
 * This is the single authority on the on-disk format. It validates its own structure (via
 * {@see self::fromArray()}) and renders it ({@see self::toArray()}), so neither the export nor the
 * import command has to know the layout. It deliberately knows nothing about a live Omeka instance:
 * checking that a module is installed, that versions match, or that a target site/user exists is the
 * importer's job, not the document's.
 *
 * The metadata is flattened to the top level (no wrapping "_meta"); the only nested object is
 * "settings", so a setting key can never collide with a metadata field.
 */
class SettingsDocument
{
    /**
     * @param array<string, mixed> $settings   key => value (values already decoded)
     * @param ?string              $scopeLabel  filename label for scoped documents (e.g. a site slug
     *                                          or disambiguated email slug); null for global settings
     */
    public function __construct(
        private SettingType $type,
        private string $module,
        private array $settings,
        private ?string $moduleVersion = null,
        private ?string $omekaVersion = null,
        private ?string $site = null,
        private ?string $user = null,
        private ?int $targetId = null,
        private ?string $exportedAt = null,
        private ?string $scopeLabel = null,
    ) {
    }

    /**
     * Build a document from a decoded file, validating its structure.
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException When the structure is invalid
     */
    public static function fromArray(array $data): self
    {
        $typeValue = $data['type'] ?? null;
        if (!is_string($typeValue) || ($type = SettingType::tryFrom($typeValue)) === null) {
            $known = implode(', ', array_map(static fn(SettingType $t) => $t->value, SettingType::cases()));
            throw new InvalidArgumentException(
                "Invalid or missing 'type' (got '" . self::describe($typeValue) . "'). Expected one of: {$known}."
            );
        }

        $module = $data['module'] ?? null;
        if (!is_string($module) || $module === '') {
            throw new InvalidArgumentException("Missing or empty 'module'.");
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            throw new InvalidArgumentException("'settings' must be an object.");
        }

        $site = self::stringOrNull($data['site'] ?? null, 'site');
        $user = self::stringOrNull($data['user'] ?? null, 'user');
        $targetId = self::positiveIntOrNull($data['targetId'] ?? null, 'targetId');

        if ($type->isScoped() && $site === null && $user === null && $targetId === null) {
            throw new InvalidArgumentException(
                "A '{$type->value}' file must identify its target (a 'site'/'user', or a 'targetId')."
            );
        }

        // Derive the filename label so a document decoded from disk reproduces the same filename it
        // was written under: the site slug for site settings, the slugified email for user settings.
        $scopeLabel = null;
        if ($type === SettingType::SiteSetting && $site !== null) {
            $scopeLabel = $site;
        } elseif ($type === SettingType::UserSetting && $user !== null) {
            $scopeLabel = Slug::email($user);
        }

        return new self(
            type: $type,
            module: $module,
            settings: $settings,
            moduleVersion: self::stringOrNull($data['moduleVersion'] ?? null, 'moduleVersion'),
            omekaVersion: self::stringOrNull($data['omekaVersion'] ?? null, 'omekaVersion'),
            site: $site,
            user: $user,
            targetId: $targetId,
            exportedAt: self::stringOrNull($data['exportedAt'] ?? null, 'exportedAt'),
            scopeLabel: $scopeLabel,
        );
    }

    /**
     * Add or overwrite one setting. Lets a builder (e.g. {@see \OSC\Settings\SettingsExport}) grow a
     * document one row at a time instead of assembling an array first.
     */
    public function addSetting(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
    }

    /**
     * The flattened structure written to disk: metadata at the top level, data under "settings"
     * (keys sorted for stable, diff-friendly output). Null metadata fields are omitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'type' => $this->type->value,
            'module' => $this->module,
        ];
        if ($this->moduleVersion !== null) {
            $out['moduleVersion'] = $this->moduleVersion;
        }
        if ($this->omekaVersion !== null) {
            $out['omekaVersion'] = $this->omekaVersion;
        }
        if ($this->site !== null) {
            $out['site'] = $this->site;
        }
        if ($this->user !== null) {
            $out['user'] = $this->user;
        }
        if ($this->targetId !== null) {
            $out['targetId'] = $this->targetId;
        }
        if ($this->exportedAt !== null) {
            $out['exportedAt'] = $this->exportedAt;
        }

        $settings = $this->settings;
        ksort($settings);
        $out['settings'] = $settings;

        return $out;
    }

    /**
     * The file name: "<module>.<type>[.<scopeLabel>].jsonc".
     */
    public function filename(): string
    {
        $name = "{$this->module}.{$this->type->value}";
        if ($this->scopeLabel !== null) {
            $name .= ".{$this->scopeLabel}";
        }

        return "{$name}.jsonc";
    }

    public function isCore(): bool
    {
        return $this->module === ModuleSettingsMapper::CORE;
    }

    public function type(): SettingType
    {
        return $this->type;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function moduleVersion(): ?string
    {
        return $this->moduleVersion;
    }

    public function omekaVersion(): ?string
    {
        return $this->omekaVersion;
    }

    public function site(): ?string
    {
        return $this->site;
    }

    public function user(): ?string
    {
        return $this->user;
    }

    public function targetId(): ?int
    {
        return $this->targetId;
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Accept a string/scalar metadata value, or null; reject arrays/objects instead of casting them
     * to the literal string "Array".
     */
    private static function stringOrNull(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException(
                "'{$field}' must be a string (got " . self::describe($value) . ")."
            );
        }

        return (string) $value;
    }

    /**
     * Accept a positive integer id (int or all-digit string), or null; reject anything else so a
     * garbage id can never masquerade as a resolvable target.
     */
    private static function positiveIntOrNull(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
        } else {
            throw new InvalidArgumentException(
                "'{$field}' must be a positive integer (got " . self::describe($value) . ")."
            );
        }
        if ($id <= 0) {
            throw new InvalidArgumentException("'{$field}' must be a positive integer (got '{$id}').");
        }

        return $id;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
