<?php
namespace OSC\Commands\Site;

use InvalidArgumentException;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\Theme\Theme;
use OSC\Commands\User\AbstractUserCommand;
use OSC\Exceptions\NotFoundException;
use OSC\Omeka\SiteApi;

/**
 * Shared behaviour of the site:* commands. Extends AbstractUserCommand for its user lookup (by id or
 * e-mail), which the permission commands need.
 */
abstract class AbstractSiteCommand extends AbstractUserCommand
{
    protected function siteApi(): SiteApi
    {
        return $this->getOmekaInstance()->getSiteApi();
    }

    /**
     * Resolve the site a command was asked to act on.
     *
     * @param string $site           Site ID or slug
     * @param bool   $ignoreNotFound Report a missing site instead of failing on it
     *
     * @return SiteRepresentation The resolved site (always; absence throws or is reported and aborts)
     *
     * @throws InvalidArgumentException If the site does not exist
     */
    protected function requireSite(string $site, bool $ignoreNotFound = false): SiteRepresentation
    {
        $siteRepresentation = $this->siteApi()->findSite($site);
        if ($siteRepresentation) {
            return $siteRepresentation;
        }

        $this->skipMissing(new InvalidArgumentException("Site not found: {$site}"), $ignoreNotFound);
    }

    /**
     * An installed theme, by id in any casing (Omeka does not check that a theme exists).
     *
     * @throws InvalidArgumentException If the theme is not installed
     */
    protected function requireTheme(string $theme): Theme
    {
        $themeApi = $this->getOmekaInstance()->getThemeApi();
        try {
            return $themeApi->getTheme($theme);
        } catch (NotFoundException) {
            // a theme downloaded earlier in this process (e.g. by a blueprint deploy) needs a reload
        }
        try {
            return $themeApi->getTheme($theme, true);
        } catch (NotFoundException) {
            throw new InvalidArgumentException("Theme not found: {$theme}. Download it first with 'theme:download {$theme}'.");
        }
    }

    /** Store the theme's default settings for the site, unless it has settings for that theme already. */
    protected function seedThemeSettings(SiteRepresentation $site, Theme $theme): void
    {
        if ($this->siteApi()->seedThemeSettings($site, $theme)) {
            $this->info("Theme '{$theme->getId()}': default settings stored for site '{$site->slug()}'.", true);
        }
    }

    /** @throws InvalidArgumentException If the role is not a site role */
    protected function assertValidSiteRole(string $role): void
    {
        if (!in_array($role, SiteApi::ROLES, true)) {
            throw new InvalidArgumentException("Invalid site role: {$role}. Valid roles are: " . implode(', ', SiteApi::ROLES));
        }
    }

    /** The role a user has on a site, or null without a permission. */
    protected function currentRole(SiteRepresentation $site, int $userId): ?string
    {
        foreach ($this->siteApi()->getPermissions($site) as $permission) {
            if ($permission['user']->id() === $userId) {
                return $permission['role'];
            }
        }
        return null;
    }

    /** The row site:list and the --json output of site:add/site:update show for a site. */
    protected function siteRow(SiteRepresentation $site, ?int $defaultSiteId): array
    {
        return [
            'id'         => $site->id(),
            'slug'       => $site->slug(),
            'title'      => $site->title(),
            'theme'      => $site->theme(),
            'is_public'  => $site->isPublic(),
            'is_default' => $site->id() === $defaultSiteId,
            'owner'      => $site->owner()?->email(),
        ];
    }
}
