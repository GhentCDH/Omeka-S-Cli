<?php
namespace OSC\Helper;

use InvalidArgumentException;
use LogicException;

/**
 * A site's identifying and display fields: title, slug, summary, theme, visibility, and whether new
 * items are assigned to it.
 *
 * One source of truth for the site field set shared by the site:* commands and the blueprint `sites`
 * section: its defaults, its validation, the transform into the Omeka API payload, and the canonical
 * blueprint shape used by blueprint:export.
 *
 * Two shapes: a complete site ({@see fromArray()}, for create and export), with a title and defaults
 * for what is not given; and an update ({@see forUpdate()}), which holds only the fields to change.
 *
 * This object does no IO: whether the theme exists is checked by the caller (see
 * {@see \OSC\Commands\Site\AbstractSiteCommand}).
 */
class SiteConfig
{
    /** Theme used when none is given; it ships with the Omeka S core. */
    public const DEFAULT_THEME = 'default';

    /** Omeka S accepts letters, digits, underscores and hyphens in a site slug. */
    public const SLUG_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * Every field is set for a complete site; for an update, null means "leave unchanged".
     */
    private function __construct(
        private ?string $title,
        private ?string $slug,
        private ?string $summary,
        private ?string $theme,
        private ?bool $isPublic,
        private ?bool $assignNewItems,
        private bool $isUpdate = false,
    ) {
    }

    /**
     * Build a complete site from a loose config array (CLI values or a blueprint site entry). Unknown
     * keys are ignored; a blank value counts as missing.
     *
     * @throws InvalidArgumentException When the title is missing or the slug is invalid
     */
    public static function fromArray(array $data): self
    {
        $title = self::clean($data['title'] ?? null);
        if ($title === null) {
            throw new InvalidArgumentException('A site must have a title.');
        }

        $slug = self::clean($data['slug'] ?? null);
        if ($slug !== null) {
            self::assertValidSlug($slug);
        }

        return new self(
            $title,
            $slug,
            self::clean($data['summary'] ?? null),
            self::clean($data['theme'] ?? null) ?? self::DEFAULT_THEME,
            (bool) ($data['isPublic'] ?? true),
            (bool) ($data['assignNewItems'] ?? true),
        );
    }

    /**
     * Build an update: only the keys present (and not null) are changed, with no defaults. A given
     * title, slug or theme must be valid; an empty summary clears it.
     *
     * @throws InvalidArgumentException When a given title or theme is blank, or a given slug is invalid
     */
    public static function forUpdate(array $data): self
    {
        $title = self::trimmed($data['title'] ?? null);
        if ($title === '') {
            throw new InvalidArgumentException('A site must have a title.');
        }

        $slug = self::trimmed($data['slug'] ?? null);
        if ($slug !== null) {
            self::assertValidSlug($slug);
        }

        $theme = self::trimmed($data['theme'] ?? null);
        if ($theme === '') {
            throw new InvalidArgumentException('A site must have a theme.');
        }

        return new self(
            $title,
            $slug,
            self::trimmed($data['summary'] ?? null),
            $theme,
            isset($data['isPublic']) ? (bool) $data['isPublic'] : null,
            isset($data['assignNewItems']) ? (bool) $data['assignNewItems'] : null,
            true,
        );
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /** @throws InvalidArgumentException If the slug has characters Omeka does not accept */
    private static function assertValidSlug(string $slug): void
    {
        if (!self::isValidSlug($slug)) {
            throw new InvalidArgumentException(
                "Invalid slug '{$slug}'. A slug may only contain letters, digits, underscores and hyphens."
            );
        }
    }

    /** Trim a value; a blank value counts as missing. */
    private static function clean(mixed $value): ?string
    {
        $value = self::trimmed($value);
        return $value === '' ? null : $value;
    }

    /** Trim a value, keeping a blank value (for an update, '' is a value). */
    private static function trimmed(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }

    /** The title; null only for an update that leaves it unchanged. */
    public function title(): ?string
    {
        return $this->title;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    /** The theme; null only for an update that leaves it unchanged. */
    public function theme(): ?string
    {
        return $this->theme;
    }

    /**
     * The fields as the Omeka `sites` API expects them on create. An empty slug lets Omeka derive one
     * from the title.
     *
     * @return array<string,mixed>
     */
    public function toApiPayload(): array
    {
        $this->assertComplete(__FUNCTION__);
        return [
            'o:title' => $this->title,
            'o:slug' => $this->slug ?? '',
            'o:summary' => $this->summary ?? '',
            'o:theme' => $this->theme,
            'o:is_public' => $this->isPublic,
            'o:assign_new_items' => $this->assignNewItems,
            // required by Omeka's validation; the CLI does not manage the item pool
            'o:item_pool' => [],
        ];
    }

    /**
     * The fields to change, as the Omeka `sites` API expects them on a partial update: only the fields
     * that are set.
     *
     * @return array<string,mixed>
     */
    public function toApiPatch(): array
    {
        $patch = [
            'o:title' => $this->title,
            'o:slug' => $this->slug,
            'o:summary' => $this->summary,
            'o:theme' => $this->theme,
            'o:is_public' => $this->isPublic,
            'o:assign_new_items' => $this->assignNewItems,
        ];

        return array_filter($patch, fn($v) => $v !== null);
    }

    /**
     * The canonical blueprint shape: omits null fields and the defaults (theme 'default', public).
     * assignNewItems is not part of the blueprint schema, so it is never emitted.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $this->assertComplete(__FUNCTION__);
        $config = [
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'theme' => $this->theme === self::DEFAULT_THEME ? null : $this->theme,
            'isPublic' => $this->isPublic ? null : false,
        ];

        return array_filter($config, fn($v) => $v !== null);
    }

    /** @throws LogicException When called on an update, which does not describe a whole site */
    private function assertComplete(string $method): void
    {
        if ($this->isUpdate) {
            throw new LogicException("SiteConfig::{$method}() needs a complete site; use toApiPatch() for an update.");
        }
    }
}
