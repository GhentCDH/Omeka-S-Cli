<?php
namespace OSC\Downloader;

use Closure;
use Exception;
use OSC\Exceptions\NotFoundException;
use OSC\Helper\ResourceUriParser;
use OSC\Helper\Types\ResourceUriType;
use OSC\Helper\VersionCompatibility;
use OSC\Manager\AbstractManager;

/**
 * Turn an add-on reference into a downloader:
 * - a catalog name: looked up through the repository manager, picking a version compatible with the
 *   target Omeka S;
 * - a ZIP release (URL or file);
 * - a git repository (URL or gh:owner/repo), at an optional ref.
 *
 * Shared by module:download, theme:download and blueprint:bundle. It needs no Omeka S install: the
 * Omeka S version is asked for only when a catalog lookup needs it.
 */
class AddonResolver
{
    /**
     * @param AbstractManager $manager The module or theme repository manager
     * @param string          $kind    'module' or 'theme', used in messages
     */
    public function __construct(private AbstractManager $manager, private string $kind)
    {
    }

    /**
     * The add-on reference for a blueprint entry: its source, or its name (resolved through the
     * catalogs) when it has none. The version selects the catalog release or the tag of a git
     * source; a ZIP source already pins the release, so it wins.
     */
    public static function uriFor(string $name, ?string $source, ?string $version): string
    {
        if ($source === null || $source === '') {
            return $version ? "{$name}:{$version}" : $name;
        }
        $isGit = str_starts_with($source, 'gh:') || str_ends_with($source, '.git');
        if ($version && $isGit && !str_contains($source, '#')) {
            return "{$source}#{$version}";
        }
        return $source;
    }

    /** Whether a reference (as uriFor() builds it) is a git repository: a git URL or gh:owner/repo. */
    public static function isGit(string $uri): bool
    {
        $type = ResourceUriParser::parse($uri)->getType();
        return $type === ResourceUriType::GitRepo || $type === ResourceUriType::GitHubRepo;
    }

    /**
     * @param string            $uri          id[:version], a ZIP URL/file, a git URL[#ref] or gh:owner/repo[#ref]
     * @param Closure(): string $omekaVersion The target Omeka S version; called only for a catalog lookup
     * @param bool              $force        Accept a pinned catalog version that is incompatible
     * @throws NotFoundException When the catalog has no such add-on or version
     * @throws Exception         When a pinned catalog version is incompatible (without $force)
     */
    public function resolve(string $uri, Closure $omekaVersion, bool $force = false): ResolvedAddon
    {
        $parsed = ResourceUriParser::parse($uri);
        return match ($parsed->getType()) {
            ResourceUriType::GitRepo => new ResolvedAddon(
                new GitDownloader($parsed->getId(), $parsed->getVersion()),
                ResolvedAddon::GIT
            ),
            ResourceUriType::GitHubRepo => new ResolvedAddon(
                new GitDownloader('https://github.com/' . $parsed->getId() . '.git', $parsed->getVersion()),
                ResolvedAddon::GIT
            ),
            ResourceUriType::ZipUrl, ResourceUriType::ZipFile => new ResolvedAddon(
                new ZipDownloader($parsed->getId()),
                ResolvedAddon::ZIP
            ),
            ResourceUriType::IdVersion => $this->fromCatalog($parsed->getId(), $parsed->getVersion(), $omekaVersion(), $force),
        };
    }

    private function fromCatalog(string $id, ?string $version, string $omekaVersion, bool $force): ResolvedAddon
    {
        $kind = $this->kind;
        $title = ucfirst($kind);
        $result = $this->manager->find($id);
        if (!$result) {
            throw new NotFoundException("Could not find {$kind} '{$id}' in any repository.");
        }
        $item = $result->getItem();
        $dirname = $item->getDirname();
        $notes = [];
        $warnings = [];

        if ($version) {
            $details = $item->getVersion($version);
            if (!$details) {
                throw new NotFoundException("{$title} '{$dirname}' has no version '{$version}'.");
            }
            if (!$force && !VersionCompatibility::isCompatible($details, $omekaVersion)) {
                throw new Exception("Cannot use {$kind} '{$dirname}' version '{$version}': incompatible with Omeka S {$omekaVersion}.");
            }
        } else {
            $details = VersionCompatibility::getLatestCompatible($item->getVersions(), $omekaVersion);
            if (!$details) {
                throw new NotFoundException("{$title} '{$dirname}' has no version compatible with Omeka S {$omekaVersion}.");
            }
            $latest = $item->getLatestVersion()->getVersionNumber();
            if ($details->getVersionNumber() !== $latest) {
                $warnings[] = "Cannot use {$kind} '{$dirname}' latest version v{$latest}: incompatible with Omeka S {$omekaVersion}.";
            }
            $notes[] = "Selected '{$dirname}' v{$details->getVersionNumber()} (compatible with Omeka S {$omekaVersion}).";
        }

        return new ResolvedAddon(
            new ZipDownloader($details->getDownloadUrl()),
            ResolvedAddon::CATALOG,
            $dirname,
            $details->getVersionNumber(),
            $notes,
            $warnings,
        );
    }
}
