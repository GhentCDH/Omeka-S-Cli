<?php
namespace OSC\Helper;

use InvalidArgumentException;

/**
 * A site's identifying and display fields: title, slug, summary, theme, visibility, and whether new
 * items are assigned to it.
 *
 * One source of truth for the site field set shared by the site:* commands and the blueprint `sites`
 * section: its defaults, its validation, the transform into the Omeka API payload, and the canonical
 * blueprint shape used by blueprint:export.
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

    private function __construct(
        private string $title,
        private ?string $slug,
        private ?string $summary,
        private string $theme,
        private bool $isPublic,
        private bool $assignNewItems,
    ) {
    }

    /**
     * Build from a loose config array (CLI values or a blueprint site entry). Unknown keys are ignored.
     *
     * @throws InvalidArgumentException When the title is missing or the slug is invalid
     */
    public static function fromArray(array $data): self
    {
        $clean = static function (mixed $value): ?string {
            if ($value === null) {
                return null;
            }
            $value = is_string($value) ? trim($value) : $value;
            return $value === '' ? null : (string) $value;
        };

        $title = $clean($data['title'] ?? null);
        if ($title === null) {
            throw new InvalidArgumentException('A site must have a title.');
        }

        $slug = $clean($data['slug'] ?? null);
        if ($slug !== null && !self::isValidSlug($slug)) {
            throw new InvalidArgumentException(
                "Invalid slug '{$slug}'. A slug may only contain letters, digits, underscores and hyphens."
            );
        }

        return new self(
            $title,
            $slug,
            $clean($data['summary'] ?? null),
            $clean($data['theme'] ?? null) ?? self::DEFAULT_THEME,
            (bool) ($data['isPublic'] ?? true),
            (bool) ($data['assignNewItems'] ?? true),
        );
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function theme(): string
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
     * The canonical blueprint shape: omits null fields and the defaults (theme 'default', public).
     * assignNewItems is not part of the blueprint schema, so it is never emitted.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $config = [
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary,
            'theme' => $this->theme === self::DEFAULT_THEME ? null : $this->theme,
            'isPublic' => $this->isPublic ? null : false,
        ];

        return array_filter($config, fn($v) => $v !== null);
    }
}
