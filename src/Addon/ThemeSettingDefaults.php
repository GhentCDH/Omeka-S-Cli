<?php
namespace OSC\Addon;

/**
 * The default theme settings a theme declares in the [config] section of its theme.ini.
 *
 * Omeka S stores a site's theme settings (`theme_settings_<theme>`) only when the theme settings form is
 * saved; the ini defaults merely prefill that form. These are the values the form shows: each element's
 * `attributes.value`, which Laminas also uses as the selected option of a Select and the state of a
 * Checkbox. Elements without one are left out (Save would store the browser's submission for those).
 * `resource_page_blocks` is not read: Omeka falls back to the ini for it.
 */
final class ThemeSettingDefaults
{
    /**
     * @param array|null $configSpec A theme's config spec (Theme::getConfigSpec(), null without [config])
     *
     * @return array<string,mixed> Setting name => default value
     */
    public static function fromConfigSpec(?array $configSpec): array
    {
        $defaults = [];
        foreach ($configSpec['elements'] ?? [] as $key => $element) {
            $attributes = is_array($element) ? ($element['attributes'] ?? []) : [];
            // array_key_exists, not empty(): "0" and "" are real defaults (e.g. nav_depth = 0)
            if (is_array($attributes) && array_key_exists('value', $attributes)) {
                $defaults[(string) ($element['name'] ?? $key)] = $attributes['value'];
            }
        }
        return $defaults;
    }
}
