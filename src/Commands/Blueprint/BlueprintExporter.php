<?php
namespace OSC\Commands\Blueprint;

use Omeka\Module\Manager as ModuleManager;
use Omeka\Site\Theme\Manager as ThemeManager;
use OSC\Helper\ResourceFetcher;
use OSC\Helper\SiteConfig;
use OSC\Omeka\OmekaInstance;
use Throwable;

/**
 * Read a live Omeka S instance and build the blueprint that describes it.
 *
 * First cut: modules, themes, vocabularies and sites; the sites' user permissions, with the users they
 * name, only when {@see ExportOptions} includes them. Vocabulary sources are resolved best-effort
 * against the GhentCDH vocabulary index (Omeka does not store the RDF import source); a vocabulary that
 * can not be resolved is emitted without a source, and its prefix is collected so the command can note
 * it. A helper for {@see ExportCommand}; it reads the instance and returns plain arrays, leaving all
 * output/formatting to the command.
 */
class BlueprintExporter
{
    /** Vocabularies Omeka installs by default; re-importing them would be noise. */
    private const DEFAULT_VOCAB_PREFIXES = ['dcterms', 'dctype'];

    /** @var string[] Prefixes of vocabularies whose source could not be resolved. */
    private array $unresolvedVocabularies = [];

    public function __construct(
        private OmekaInstance $instance,
        private ?string $omekaVersion = null,
        private ExportOptions $options = new ExportOptions(),
    ) {
    }

    /**
     * @return array The blueprint describing the instance.
     */
    public function export(): array
    {
        $blueprint = [];
        if ($this->omekaVersion) {
            $blueprint['preferredVersions'] = ['omeka' => $this->omekaVersion];
        }
        if ($modules = $this->exportModules()) {
            $blueprint['modules'] = $modules;
        }
        if ($themes = $this->exportThemes()) {
            $blueprint['themes'] = $themes;
        }
        if ($vocabularies = $this->exportVocabularies()) {
            $blueprint['vocabularies'] = $vocabularies;
        }
        [$users, $sites] = $this->exportSites($this->options->includes(ExportOptions::SITE_PERMISSIONS));
        if ($users) {
            $blueprint['users'] = $users;
        }
        if ($sites) {
            $blueprint['sites'] = $sites;
        }
        return $blueprint;
    }

    /**
     * @return string[] Prefixes of vocabularies exported without a source.
     */
    public function unresolvedVocabularies(): array
    {
        return $this->unresolvedVocabularies;
    }

    private function exportModules(): array
    {
        $modules = [];
        foreach ($this->instance->getModuleApi()->getModules() as $module) {
            $state = $this->mapModuleState($module->getState());
            if ($state === null) {
                continue; // not found / invalid — nothing to reproduce
            }
            $entry = ['name' => $module->getId(), 'state' => $state];
            if ($version = $module->getIni('version')) {
                $entry['version'] = $version;
            }
            $modules[] = $entry;
        }
        return $modules;
    }

    private function mapModuleState(string $state): ?string
    {
        return match ($state) {
            ModuleManager::STATE_ACTIVE, ModuleManager::STATE_NEEDS_UPGRADE => 'activate',
            ModuleManager::STATE_NOT_ACTIVE => 'install',
            ModuleManager::STATE_NOT_INSTALLED => 'download',
            default => null,
        };
    }

    private function exportThemes(): array
    {
        $broken = [ThemeManager::STATE_NOT_FOUND, ThemeManager::STATE_INVALID_INI, ThemeManager::STATE_INVALID_OMEKA_VERSION];
        $themes = [];
        foreach ($this->instance->getThemeApi()->getThemes() as $theme) {
            if (in_array($theme->getState(), $broken, true)) {
                continue;
            }
            $id = $theme->getId();
            if ($id === 'default') {
                // ships with the core: no source and no version, so deploy uses the one on disk
                $themes[] = $id;
                continue;
            }
            $entry = ['name' => $id];
            if ($version = $theme->getIni('version')) {
                $entry['version'] = $version;
            }
            $themes[] = $entry;
        }
        return $themes;
    }

    private function exportVocabularies(): array
    {
        $sourceMap = [];
        $vocabularies = [];
        foreach ($this->instance->getApi()->search('vocabularies')->getContent() as $vocabulary) {
            $prefix = $vocabulary->prefix();
            if (in_array($prefix, self::DEFAULT_VOCAB_PREFIXES, true)) {
                continue;
            }
            $namespaceUri = $vocabulary->namespaceUri();
            $entry = [
                'prefix' => $prefix,
                'namespaceUri' => $namespaceUri,
                'label' => $vocabulary->label(),
            ];
            $url = $sourceMap[strtolower($namespaceUri)] ?? null;
            // an empty source keeps the entry schema-valid while marking it as needing a real one
            $entry['source'] = $url ?? '';
            if (!$url) {
                $this->unresolvedVocabularies[] = $prefix;
            }
            $vocabularies[] = $entry;
        }
        return $vocabularies;
    }

    /**
     * Every site; with $withPermissions also its user permissions, plus a minimal `users` entry for
     * every user a permission names (a permission's user must be declared in the blueprint).
     * Passwords are never exported.
     *
     * @return array{0: array, 1: array} [users, sites]
     */
    private function exportSites(bool $withPermissions): array
    {
        // private sites are invisible to an anonymous identity
        $this->instance->elevatePrivileges();
        $siteApi = $this->instance->getSiteApi();
        $defaultSiteId = $siteApi->getDefaultSiteId();

        $users = [];
        $sites = [];
        foreach ($siteApi->getSites() as $site) {
            $entry = SiteConfig::fromArray([
                'title' => $site->title(),
                'slug' => $site->slug(),
                'summary' => $site->summary(),
                'theme' => $site->theme(),
                'isPublic' => $site->isPublic(),
            ])->toArray();
            if ($site->id() === $defaultSiteId) {
                $entry['setAsDefault'] = true;
            }

            if ($withPermissions) {
                $permissions = [];
                foreach ($siteApi->getPermissions($site) as $permission) {
                    $user = $permission['user'];
                    $permissions[] = ['user' => $user->email(), 'role' => $permission['role']];
                    $users[strtolower($user->email())] = [
                        'email' => $user->email(),
                        'username' => $user->name(),
                        'role' => $user->role(),
                        'isActive' => $user->isActive(),
                    ];
                }
                if ($permissions) {
                    $entry['permissions'] = $permissions;
                }
            }
            $sites[] = $entry;
        }

        return [array_values($users), $sites];
    }

}
