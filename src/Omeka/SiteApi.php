<?php
namespace OSC\Omeka;

use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use OSC\Helper\SiteConfig;

/**
 * Omeka S sites through the internal API, with the adapter's pitfalls handled in one place:
 * - updates are always partial: a full update resets every omitted key, which deletes the site's
 *   pages and item sets and empties its navigation;
 * - o:site_permission replaces the whole list, so one permission is changed by read, merge, write;
 * - the default site is the global setting `default_site`, not a site field.
 *
 * Callers must run with an elevated identity (AbstractCommand::getOmekaInstance() elevates): private
 * sites are invisible to an anonymous identity, and writes need ACL rights.
 */
class SiteApi
{
    /** Site permission roles known to Omeka S (Omeka\Entity\SitePermission). */
    public const ROLES = ['viewer', 'editor', 'admin'];

    public function __construct(private ServiceManager $serviceLocator)
    {
    }

    /**
     * @return SiteRepresentation[] All sites, by id
     */
    public function getSites(): array
    {
        return $this->api()->search('sites', ['sort_by' => 'id', 'sort_order' => 'asc'])->getContent();
    }

    /**
     * Find a site by id or slug. A numeric value is tried as an id first, then as a slug (a slug may
     * consist of digits only).
     */
    public function findSite(string $idOrSlug): ?SiteRepresentation
    {
        if (is_numeric($idOrSlug)) {
            try {
                return $this->api()->read('sites', (int) $idOrSlug)->getContent();
            } catch (NotFoundException) {
                // not an id: try it as a slug
            }
        }
        return $this->findSiteBySlug($idOrSlug);
    }

    public function findSiteBySlug(string $slug): ?SiteRepresentation
    {
        $sites = $this->api()->search('sites', ['slug' => $slug])->getContent();
        return $sites[0] ?? null;
    }

    /** The first site (by id) whose title matches, ignoring case. */
    public function findSiteByTitle(string $title): ?SiteRepresentation
    {
        foreach ($this->getSites() as $site) {
            if (strcasecmp($site->title(), $title) === 0) {
                return $site;
            }
        }
        return null;
    }

    /**
     * Create a site. Omeka makes the creating identity a site admin; with an explicit owner that entry
     * is replaced by one for the owner (o:site_permission replaces the list).
     *
     * @param SiteConfig $config  The site fields
     * @param int|null   $ownerId User id of the owner (default: the creating identity)
     */
    public function create(SiteConfig $config, ?int $ownerId = null): SiteRepresentation
    {
        $data = $config->toApiPayload();
        if ($ownerId !== null) {
            $data['o:owner'] = ['o:id' => $ownerId];
            $data['o:site_permission'] = [['o:user' => ['o:id' => $ownerId], 'o:role' => 'admin']];
        }
        return $this->api()->create('sites', $data)->getContent();
    }

    /**
     * Update the given o:* fields only; every other field, page and permission is left untouched.
     */
    public function update(SiteRepresentation $site, array $data): SiteRepresentation
    {
        return $this->api()->update('sites', $site->id(), $data, [], ['isPartial' => true])->getContent();
    }

    /** Delete a site, and clear default_site when it pointed at it. */
    public function delete(SiteRepresentation $site): void
    {
        $id = $site->id();
        $this->api()->delete('sites', $id);
        if ($this->getDefaultSiteId() === $id) {
            $this->setDefaultSite(null);
        }
    }

    /**
     * @return array<int, array{user: \Omeka\Api\Representation\UserRepresentation, role: string}>
     */
    public function getPermissions(SiteRepresentation $site): array
    {
        $permissions = [];
        foreach ($site->sitePermissions() as $permission) {
            $permissions[] = ['user' => $permission->user(), 'role' => $permission->role()];
        }
        return $permissions;
    }

    /**
     * @return array<string,string> Lower-cased user email => role
     */
    public function getPermissionMap(SiteRepresentation $site): array
    {
        $map = [];
        foreach ($this->getPermissions($site) as $permission) {
            $map[strtolower($permission['user']->email())] = $permission['role'];
        }
        return $map;
    }

    /** Give a user a role on the site (add the permission, or change its role), keeping the others. */
    public function setPermission(SiteRepresentation $site, int $userId, string $role): SiteRepresentation
    {
        $list = [];
        $found = false;
        foreach ($this->getPermissions($site) as $permission) {
            $id = $permission['user']->id();
            if ($id === $userId) {
                $permission['role'] = $role;
                $found = true;
            }
            $list[] = ['o:user' => ['o:id' => $id], 'o:role' => $permission['role']];
        }
        if (!$found) {
            $list[] = ['o:user' => ['o:id' => $userId], 'o:role' => $role];
        }
        return $this->update($site, ['o:site_permission' => $list]);
    }

    /** Remove a user's permission on the site, keeping the others. */
    public function removePermission(SiteRepresentation $site, int $userId): SiteRepresentation
    {
        $list = [];
        foreach ($this->getPermissions($site) as $permission) {
            if ($permission['user']->id() !== $userId) {
                $list[] = ['o:user' => ['o:id' => $permission['user']->id()], 'o:role' => $permission['role']];
            }
        }
        return $this->update($site, ['o:site_permission' => $list]);
    }

    public function getDefaultSiteId(): ?int
    {
        $id = $this->settings()->get('default_site');
        return is_numeric($id) ? (int) $id : null;
    }

    /** Make a site the default site, or clear the default with null. */
    public function setDefaultSite(?int $siteId): void
    {
        if ($siteId === null) {
            $this->settings()->delete('default_site');
            return;
        }
        // the admin settings form stores the id as a string
        $this->settings()->set('default_site', (string) $siteId);
    }

    private function api(): ApiManager
    {
        return $this->serviceLocator->get('Omeka\ApiManager');
    }

    private function settings()
    {
        return $this->serviceLocator->get('Omeka\Settings');
    }
}
