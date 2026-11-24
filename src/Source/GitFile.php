<?php
namespace OSC\Source;

use InvalidArgumentException;
use OSC\Source\GitHost\GitHubHost;
use OSC\Source\GitHost\GitHost;
use OSC\Source\GitHost\GitLabHost;

/**
 * A single file in a git repository, written as a gh:/gl: shortcut or a browser blob URL, is fetched
 * from its raw-download URL. It has no type of its own: rawUrl() turns it into that HttpUrl straight
 * away, keeping the shortcut as the URL's original() so messages can name it.
 */
final class GitFile
{
    /**
     * @return HttpUrl|null The raw file's URL, or null when $written is no git file reference
     * @throws InvalidArgumentException When it is one, but leaves its git repository or has an invalid ref
     */
    public static function rawUrl(string $written): ?HttpUrl
    {
        foreach (self::hosts() as $host) {
            $raw = $host->rawUrl($written);
            if ($raw !== null) {
                return HttpUrl::fromUrl($raw, $written);
            }
        }
        return null;
    }

    /** @return GitHost[] In the order they are tried */
    public static function hosts(): array
    {
        return [new GitHubHost(), new GitLabHost()];
    }
}
