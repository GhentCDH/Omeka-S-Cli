<?php
namespace OSC\Helper;

use OSC\Exceptions\OfflineException;

/**
 * Offline mode: the CLI does not contact the module/theme repositories (omeka.org, Daniel-KM) or the Omeka
 * version API on its own. Sources the user passes (release ZIPs, git, vocabularies, media) are still
 * fetched.
 *
 * Switched on by the global --offline option or the OMEKA_S_CLI_OFFLINE environment variable.
 */
final class Offline
{
    public const ENV = 'OMEKA_S_CLI_OFFLINE';

    private const TRUTHY = ['1', 'true', 'yes', 'on'];

    public static function isEnabled(): bool
    {
        $value = getenv(self::ENV);
        return $value !== false && in_array(strtolower(trim($value)), self::TRUTHY, true);
    }

    /** Switch offline mode on, also for child processes (runInNewProcess() inherits the environment). */
    public static function enable(): void
    {
        putenv(self::ENV . '=1');
        $_ENV[self::ENV] = '1';
    }

    /**
     * @param string      $what        What the CLI would do, e.g. "search the module repositories"
     * @param string|null $alternative What the user can do instead, e.g. "pass a version number instead"
     * @throws OfflineException When offline mode is on
     */
    public static function assertOnline(string $what, ?string $alternative = null): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $message = "Offline mode: cannot {$what}. ";
        $message .= $alternative
            ? ucfirst($alternative) . ', or drop --offline / unset ' . self::ENV . '.'
            : 'Drop --offline or unset ' . self::ENV . '.';
        throw new OfflineException($message);
    }
}
