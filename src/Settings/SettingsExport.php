<?php

namespace OSC\Settings;

use OSC\Helper\ModuleSettingsMapper;
use OSC\Helper\Slug;
use RuntimeException;

/**
 * The exportable settings of an instance, grouped into per-module, per-scope {@see SettingsDocument}s.
 *
 * The command feeds it raw database rows (one pass per table); this class does everything else:
 * decode each value, attribute the key to a module, apply the {@see SettingsScope}, and accumulate
 * the result into the right file. It is pure with respect to Omeka — it takes plain arrays — so all
 * of the grouping/attribution logic is unit-testable without a database.
 */
class SettingsExport
{
    /** @var array<string, SettingsDocument> keyed by filename */
    private array $files = [];

    /** @var array<int, array{slug: string, email: string}> user id => filename slug + email */
    private array $userSlugs;

    /**
     * @param array<string, ?string> $moduleVersions module id => installed version
     * @param array<int, string>     $siteSlugs      site id => slug
     * @param array<int, string>     $userEmails     user id => email
     */
    public function __construct(
        private SettingsScope $scope,
        private ModuleSettingsMapper $mapper,
        private array $moduleVersions,
        private string $omekaVersion,
        private array $siteSlugs,
        array $userEmails,
        private string $exportedAt,
    ) {
        $this->userSlugs = $this->buildUserSlugs($userEmails);
    }

    /**
     * Ingest all global settings.
     *
     * @param iterable<array{id: string, value: ?string}> $rows
     */
    public function setSettings(iterable $rows): void
    {
        if (!$this->scope->includesType(SettingType::Setting)) {
            return;
        }

        foreach ($rows as $row) {
            $owner = $this->mapper->moduleForKey($row['id']);
            if (!$this->scope->includesModule($owner) || !$this->scope->includesKey($row['id'])) {
                continue;
            }
            $file = $this->file("{$owner}.setting", SettingType::Setting, $owner);
            $file->addSetting($row['id'], $this->decode($row['id'], $row['value']));
        }
    }

    /**
     * Ingest all site settings.
     *
     * @param iterable<array{id: string, site_id: int|string, value: ?string}> $rows
     */
    public function setSiteSettings(iterable $rows): void
    {
        if (!$this->scope->includesType(SettingType::SiteSetting)) {
            return;
        }

        foreach ($rows as $row) {
            $siteId = (int) $row['site_id'];
            $owner = $this->mapper->moduleForKey($row['id']);
            if (!$this->scope->includesSite($siteId)
                || !$this->scope->includesModule($owner)
                || !$this->scope->includesKey($row['id'])
            ) {
                continue;
            }
            // Skip a row whose site is unknown to us (e.g. an orphan left behind by a deleted site).
            $slug = $this->siteSlugs[$siteId] ?? null;
            if ($slug === null) {
                continue;
            }
            $file = $this->file(
                "{$owner}.site_setting.{$slug}",
                SettingType::SiteSetting,
                $owner,
                site: $slug,
                targetId: $siteId,
                scopeLabel: $slug,
            );
            $file->addSetting($row['id'], $this->decode($row['id'], $row['value']));
        }
    }

    /**
     * Ingest all user settings.
     *
     * @param iterable<array{id: string, user_id: int|string, value: ?string}> $rows
     */
    public function setUserSettings(iterable $rows): void
    {
        if (!$this->scope->includesType(SettingType::UserSetting)) {
            return;
        }

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $owner = $this->mapper->moduleForKey($row['id']);
            if (!$this->scope->includesUser($userId)
                || !$this->scope->includesModule($owner)
                || !$this->scope->includesKey($row['id'])
            ) {
                continue;
            }
            // Skip a row whose user is unknown to us (e.g. an orphan left behind by a deleted user).
            $target = $this->userSlugs[$userId] ?? null;
            if ($target === null) {
                continue;
            }
            $file = $this->file(
                "{$owner}.user_setting.{$target['slug']}",
                SettingType::UserSetting,
                $owner,
                user: $target['email'],
                targetId: $userId,
                scopeLabel: $target['slug'],
            );
            $file->addSetting($row['id'], $this->decode($row['id'], $row['value']));
        }
    }

    /**
     * @return SettingsDocument[]
     */
    public function files(): array
    {
        return array_values($this->files);
    }

    /**
     * Find or create the file for a group, building its metadata on first encounter.
     */
    private function file(
        string $filename,
        SettingType $type,
        string $owner,
        ?string $site = null,
        ?string $user = null,
        ?int $targetId = null,
        ?string $scopeLabel = null,
    ): SettingsDocument {
        if (!isset($this->files[$filename])) {
            $isCore = $owner === ModuleSettingsMapper::CORE;
            $this->files[$filename] = new SettingsDocument(
                type: $type,
                module: $owner,
                settings: [],
                moduleVersion: $isCore ? null : ($this->moduleVersions[$owner] ?? null),
                omekaVersion: $isCore ? $this->omekaVersion : null,
                site: $site,
                user: $user,
                targetId: $targetId,
                exportedAt: $this->exportedAt,
                scopeLabel: $scopeLabel,
            );
        }

        return $this->files[$filename];
    }

    /**
     * Assign a unique filename slug to every user, disambiguating email-slug collisions by appending
     * the numeric id. Keeps the email for the file metadata.
     *
     * @param array<int, string> $userEmails id => email
     * @return array<int, array{slug: string, email: string}>
     */
    private function buildUserSlugs(array $userEmails): array
    {
        $used = [];
        $byId = [];
        foreach ($userEmails as $id => $email) {
            $slug = Slug::email($email);
            if (isset($used[$slug])) {
                $slug .= '-' . $id;
            }
            $used[$slug] = true;
            $byId[(int) $id] = ['slug' => $slug, 'email' => $email];
        }

        return $byId;
    }

    /**
     * Decode a stored setting value (Omeka stores every value JSON-encoded).
     *
     * @throws RuntimeException When a stored value is not valid JSON — decoding it silently to null
     *                          would turn an export/import round trip into a deletion.
     */
    private function decode(string $key, ?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Could not decode the stored value for '{$key}': " . $e->getMessage(), 0, $e);
        }
    }
}
