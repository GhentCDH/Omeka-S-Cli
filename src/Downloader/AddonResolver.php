<?php
namespace OSC\Downloader;

use OSC\Source\Location;
use OSC\Source\GitRepository;
use OSC\Source\NamedAddon;
use Closure;
use Exception;
use InvalidArgumentException;
use OSC\Addon\AddonReference;
use OSC\Addon\AddonType;
use OSC\Exceptions\NotFoundException;
use OSC\Exceptions\OfflineException;
use OSC\Helper\Offline;
use OSC\Addon\AddonVersion;
use OSC\Manager\AbstractManager;

/**
 * Turn an add-on reference into a downloader:
 * - a repository name: looked up through the repository manager, picking a version compatible with the
 *   target Omeka S;
 * - a ZIP release (URL or file);
 * - a git repository (git URL, https://github.com/owner/repo or gh:owner/repo), at an optional ref.
 *
 * The reference is an AddonReference: built from a blueprint entry's own fields, or parsed from a
 * command argument by CliSourceResolver::addon(). Shared by module:download, theme:download and
 * blueprint:bundle. It needs no Omeka S install: the Omeka S version is asked for only when a
 * repository lookup needs it.
 */
class AddonResolver
{
    /**
     * @param AbstractManager $manager The module or theme repository manager
     * @param AddonType       $type    Module or theme, used in messages
     */
    public function __construct(private AbstractManager $manager, private AddonType $type)
    {
    }

    /**
     * @param AddonReference        $reference    A parsed command argument or a blueprint entry
     * @param Closure(): string     $omekaVersion The target Omeka S version; called only for a repository lookup
     * @param bool                  $force        Accept a pinned repository version that is incompatible
     * @throws NotFoundException        When the repositories have no such add-on or version
     * @throws Exception                When a pinned repository version is incompatible (without $force)
     */
    public function resolve(AddonReference $reference, Closure $omekaVersion, bool $force = false): ResolvedAddon
    {
        $source = $reference->source;
        return match (true) {
            $source instanceof GitRepository => new ResolvedAddon(new GitDownloader($source), ResolvedAddon::GIT),
            $source instanceof Location => new ResolvedAddon(new ZipDownloader($source), ResolvedAddon::ZIP),
            $source instanceof NamedAddon => $this->fromRepository($source->name, $source->version, $omekaVersion, $force),
        };
    }

    /**
     * @param Closure(): string $omekaVersion
     * @throws OfflineException When offline mode is on: checked before the Omeka S version is asked for,
     *                          which may itself be a network lookup
     */
    private function fromRepository(string $id, ?string $version, Closure $omekaVersion, bool $force): ResolvedAddon
    {
        Offline::assertOnline(
            "look up '{$id}' in the {$this->type->value} repositories",
            'pass a release ZIP URL/path or git source instead'
        );
        $omekaVersion = $omekaVersion();

        $kind = $this->type->value;
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
            if (!$force && !AddonVersion::isCompatible($details, $omekaVersion)) {
                throw new Exception("Cannot use {$kind} '{$dirname}' version '{$version}': incompatible with Omeka S {$omekaVersion}.");
            }
        } else {
            $details = AddonVersion::latestCompatible($item->getVersions(), $omekaVersion);
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
            new ZipDownloader(Location::fromResolved($details->getDownloadUrl())),
            ResolvedAddon::REPOSITORY,
            $dirname,
            $details->getVersionNumber(),
            $notes,
            $warnings,
        );
    }
}
