<?php

namespace OSC\Helper;

use Exception;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Run an external process, streaming its input and output.
 *
 * The command is given as an argument array, so no shell is involved and no escaping is needed.
 * Output is handed to a callback as it arrives instead of being collected: that keeps the real exit
 * code, makes stderr available for the error message, and never holds the whole output in memory,
 * which matters when the process is a mysqldump of an arbitrarily large database.
 */
class ProcessRunner
{
    private const STDERR_LIMIT = 65536;

    /**
     * Whether external processes can be run at all.
     *
     * Hosts sometimes disable proc_open. Callers that have a pure PHP alternative should ask first
     * rather than let the process layer fail.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Run a command to completion.
     *
     * @param string[]      $argv     Command and arguments
     * @param callable|null $onStdout Called with every chunk of the process output
     * @param resource|null $stdin    Stream fed to the process input
     *
     * @return array{code: int, stderr: string}
     *
     * @throws Exception If the process can not be started.
     */
    public static function run(array $argv, ?callable $onStdout = null, $stdin = null): array
    {
        $process = new Process($argv);
        // A dump or an import takes as long as it takes.
        $process->setTimeout(null);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        try {
            $process->start();
        } catch (ProcessException $e) {
            throw new Exception("Could not start '{$argv[0]}': " . $e->getMessage(), 0, $e);
        }

        $stderr = '';

        // getIterator() empties the internal buffers while it yields. Process::run() with a
        // callback would stream as well, but it also keeps every byte in a php://temp buffer that
        // spills to disc, which would put a second copy of the whole dump on the file system.
        foreach ($process->getIterator() as $type => $chunk) {
            if ($type === Process::OUT) {
                if ($onStdout !== null) {
                    $onStdout($chunk);
                }
            } elseif (strlen($stderr) < self::STDERR_LIMIT) {
                $stderr .= $chunk;
            }
        }

        $process->wait();

        return ['code' => (int) $process->getExitCode(), 'stderr' => trim($stderr)];
    }
}
