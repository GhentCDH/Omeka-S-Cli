<?php

namespace OSC\Settings;

/**
 * What a settings export or import covers.
 *
 * A pure policy object shared by both commands: it answers "is this in scope?" for a type, a module,
 * a site, a user, and an individual key. It holds no instance/DB state, so it is trivially testable
 * and can later be built from a config file instead of CLI options.
 *
 * A null filter means "no restriction" (everything of that kind is in scope). `excludedKeys` is a
 * denylist applied on top of everything else — empty by default, ready for future policy (e.g. never
 * export a debug flag or a per-instance title) without any interface change.
 */
class SettingsScope
{
    /**
     * @param SettingType[] $types        Types in scope
     * @param ?string[]     $modules      Lowercased module ids in scope, or null for all
     * @param ?int[]        $siteIds      Site ids in scope, or null for all (export only)
     * @param ?int[]        $userIds      User ids in scope, or null for all (export only)
     * @param string[]      $excludedKeys Lowercased keys to always exclude
     */
    public function __construct(
        private array $types,
        private ?array $modules = null,
        private ?array $siteIds = null,
        private ?array $userIds = null,
        private array $excludedKeys = [],
    ) {
        $this->modules = $modules === null ? null : array_map('strtolower', $modules);
        $this->excludedKeys = array_map('strtolower', $excludedKeys);
    }

    /** Everything: all types, no filters. The default for a plain import. */
    public static function all(): self
    {
        return new self(SettingType::cases());
    }

    /**
     * Build a scope from the CLI options shared by config:export and config:import.
     *
     * The single home for the --scope/--module/--site/--user parsing, so the two commands cannot
     * drift apart. Site/user id filters are export-only (numeric ids are not portable across
     * instances); import passes them as null.
     *
     * @throws \InvalidArgumentException When --scope is not "all" or a known {@see SettingType} value
     */
    public static function fromOptions(
        ?string $scope,
        ?string $module,
        ?int $site = null,
        ?int $user = null,
    ): self {
        $scope = $scope ?: 'all';
        if ($scope === 'all') {
            $types = SettingType::cases();
        } elseif (($type = SettingType::tryFrom($scope)) !== null) {
            $types = [$type];
        } else {
            $valid = 'all, ' . implode(', ', array_map(static fn(SettingType $t) => $t->value, SettingType::cases()));
            throw new \InvalidArgumentException("Invalid --scope '{$scope}'. Use one of: {$valid}.");
        }

        return new self(
            types: $types,
            modules: $module !== null ? [$module] : null,
            siteIds: $site !== null ? [$site] : null,
            userIds: $user !== null ? [$user] : null,
        );
    }

    public function includesType(SettingType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function includesModule(string $module): bool
    {
        return $this->modules === null || in_array(strtolower($module), $this->modules, true);
    }

    public function includesSite(int $siteId): bool
    {
        return $this->siteIds === null || in_array($siteId, $this->siteIds, true);
    }

    public function includesUser(int $userId): bool
    {
        return $this->userIds === null || in_array($userId, $this->userIds, true);
    }

    public function includesKey(string $key): bool
    {
        return !in_array(strtolower($key), $this->excludedKeys, true);
    }
}
