<?php
namespace OSC\Downloader;

/**
 * An add-on reference resolved to something to download: the downloader, the form it came in, and
 * for a catalog lookup the catalog's folder name and the selected version.
 */
final class ResolvedAddon
{
    public const CATALOG = 'catalog';
    public const ZIP = 'zip';
    public const GIT = 'git';

    /**
     * @param string[] $notes    Informational messages (e.g. the selected catalog version)
     * @param string[] $warnings Advisory messages (e.g. the latest version is incompatible)
     */
    public function __construct(
        public readonly DownloaderInterface $downloader,
        public readonly string $form,
        public readonly ?string $dirname = null,
        public readonly ?string $version = null,
        public readonly array $notes = [],
        public readonly array $warnings = [],
    ) {
    }
}
