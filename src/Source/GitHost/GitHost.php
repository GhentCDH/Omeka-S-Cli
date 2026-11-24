<?php
namespace OSC\Source\GitHost;

/**
 * A git hosting service's ways of pointing at a single file in a git repository (its browser "blob"
 * URLs and a short scheme such as gh:), turned into the file's raw-download URL. Pure string work,
 * no network.
 */
interface GitHost
{
    /**
     * The raw-download URL of $reference, or null when it is not one of this host's forms.
     *
     * @throws \InvalidArgumentException When it is, but leaves its git repository or has an invalid ref
     */
    public function rawUrl(string $reference): ?string;
}
