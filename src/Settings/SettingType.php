<?php

namespace OSC\Settings;

/**
 * The three scopes an Omeka setting can live in, and the single authority for how each maps onto
 * Omeka's services and tables.
 *
 * Omeka core and modules share three key/value tables — setting (global), site_setting (per site)
 * and user_setting (per user). This enum ties each scope to its settings service id, its table, and
 * (for the scoped ones) the target column and api resource used to resolve a site/user.
 */
enum SettingType: string
{
    case Setting = 'setting';
    case SiteSetting = 'site_setting';
    case UserSetting = 'user_setting';

    /** The service manager id of the settings service that reads/writes this scope. */
    public function serviceId(): string
    {
        return match ($this) {
            self::Setting => 'Omeka\Settings',
            self::SiteSetting => 'Omeka\Settings\Site',
            self::UserSetting => 'Omeka\Settings\User',
        };
    }

    /** The database table backing this scope (identical to the enum value). */
    public function tableName(): string
    {
        return $this->value;
    }

    /** Whether this scope is pinned to a target (a site or a user). */
    public function isScoped(): bool
    {
        return $this !== self::Setting;
    }

    /** The target foreign-key column, or null for global settings. */
    public function targetColumn(): ?string
    {
        return match ($this) {
            self::Setting => null,
            self::SiteSetting => 'site_id',
            self::UserSetting => 'user_id',
        };
    }

    /** The api resource name used to resolve a target to an id, or null for global settings. */
    public function targetResource(): ?string
    {
        return match ($this) {
            self::Setting => null,
            self::SiteSetting => 'sites',
            self::UserSetting => 'users',
        };
    }
}
