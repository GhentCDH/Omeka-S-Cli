<?php

namespace OSC\Database;

/**
 * Writes dump data to a stream, normalizing it on the way.
 *
 * DEFINER clauses name the user a view, trigger or routine runs as. That user rarely exists on the
 * server the dump is restored to, so it is replaced by CURRENT_USER, which means "whoever runs the
 * import". The replacement is applied to the output of both engines, mysqldump included.
 */
class DumpWriter
{
    /** @var resource */
    private $stream;

    private string $carry = '';

    private bool $normalizeDefiner;

    private int $bytes = 0;

    /**
     * @param resource $stream
     */
    public function __construct($stream, bool $normalizeDefiner = true)
    {
        $this->stream = $stream;
        $this->normalizeDefiner = $normalizeDefiner;
    }

    public function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        if (!$this->normalizeDefiner) {
            $this->put($data);
            return;
        }

        // A DEFINER clause never spans a line, so everything up to the last newline can be
        // rewritten right away; the rest waits for the next chunk.
        $this->carry .= $data;
        $position = strrpos($this->carry, "\n");
        if ($position === false) {
            return;
        }

        $chunk = substr($this->carry, 0, $position + 1);
        $this->carry = substr($this->carry, $position + 1);
        $this->put(self::normalizeDefiner($chunk));
    }

    public function close(): void
    {
        if ($this->carry !== '') {
            $this->put($this->normalizeDefiner ? self::normalizeDefiner($this->carry) : $this->carry);
            $this->carry = '';
        }
    }

    public function getBytesWritten(): int
    {
        return $this->bytes;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    public static function normalizeDefiner(string $sql): string
    {
        $sql = preg_replace('~DEFINER\s*=\s*`[^`]+`@`[^`]+`~i', 'DEFINER=CURRENT_USER', $sql);

        return preg_replace('~DEFINER\s*=\s*[^\s`]+@[^\s`*]+~i', 'DEFINER=CURRENT_USER', $sql);
    }

    private function put(string $data): void
    {
        $written = fwrite($this->stream, $data);
        if ($written === false) {
            throw new \Exception('Could not write to the dump destination.');
        }
        $this->bytes += $written;
    }
}
