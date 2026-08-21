<?php

namespace OSC\Database;

use Generator;

/**
 * Split a SQL dump into executable statements while streaming it.
 *
 * String literals, quoted identifiers, comments and DELIMITER blocks are taken into account, so a
 * semicolon inside a value or a trigger body does not end a statement. Conditional comments
 * (/*! ... *\/) are kept, since MySQL executes them.
 */
class SqlStatementReader
{
    /**
     * @param resource $stream
     * @return Generator<string>
     */
    public static function statements($stream): Generator
    {
        $delimiter = ';';
        $inString = null;
        $inComment = false;
        $buffer = '';
        $first = true;

        while (($line = fgets($stream)) !== false) {
            if ($first) {
                $line = preg_replace('~^\xEF\xBB\xBF~', '', $line);
                $first = false;
            }

            if ($inString === null && !$inComment && trim($buffer) === ''
                && preg_match('~^\s*DELIMITER\s+(\S+)~i', $line, $matches)
            ) {
                $delimiter = $matches[1];
                $buffer = '';
                continue;
            }

            $length = strlen($line);
            $position = 0;

            while ($position < $length) {
                if ($inString !== null) {
                    $end = self::scanString($line, $position, $inString);
                    $buffer .= substr($line, $position, $end['length']);
                    $position += $end['length'];
                    if ($end['closed']) {
                        $inString = null;
                    }
                    continue;
                }

                if ($inComment) {
                    $end = strpos($line, '*/', $position);
                    if ($end === false) {
                        $position = $length;
                        continue;
                    }
                    $inComment = false;
                    $position = $end + 2;
                    continue;
                }

                $skip = strcspn($line, "'\"`-#/" . $delimiter[0], $position);
                if ($skip > 0) {
                    $buffer .= substr($line, $position, $skip);
                    $position += $skip;
                    if ($position >= $length) {
                        break;
                    }
                }

                $character = $line[$position];
                $next = $line[$position + 1] ?? '';

                // End of statement?
                if ($character === $delimiter[0] && substr($line, $position, strlen($delimiter)) === $delimiter) {
                    $statement = trim($buffer);
                    $buffer = '';
                    $position += strlen($delimiter);
                    if ($statement !== '') {
                        yield $statement;
                    }
                    continue;
                }

                if ($character === "'" || $character === '"' || $character === '`') {
                    $inString = $character;
                    $buffer .= $character;
                    $position++;
                    continue;
                }

                // Line comment: "-- " (or "--" at end of line) and "#".
                if ($character === '#'
                    || ($character === '-' && $next === '-'
                        && ($position + 2 >= $length || in_array($line[$position + 2], [' ', "\t", "\r", "\n"], true)))
                ) {
                    $buffer .= "\n";
                    $position = $length;
                    continue;
                }

                if ($character === '/' && $next === '*') {
                    // Conditional comments are executed by MySQL, so they are kept verbatim.
                    if (($line[$position + 2] ?? '') === '!') {
                        $buffer .= '/*!';
                        $position += 3;
                        continue;
                    }
                    $end = strpos($line, '*/', $position + 2);
                    if ($end === false) {
                        $inComment = true;
                        $position = $length;
                    } else {
                        $position = $end + 2;
                    }
                    $buffer .= ' ';
                    continue;
                }

                $buffer .= $character;
                $position++;
            }
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            yield $statement;
        }
    }

    /**
     * Scan forward inside a string literal or a quoted identifier.
     *
     * @return array{length: int, closed: bool} Characters consumed, and whether the quote closed.
     */
    private static function scanString(string $line, int $start, string $quote): array
    {
        $length = strlen($line);
        $position = $start;
        // Backslash escapes apply to string literals, not to backtick quoted identifiers.
        $stopAt = $quote === '`' ? $quote : '\\' . $quote;

        while ($position < $length) {
            $position += strcspn($line, $stopAt, $position);
            if ($position >= $length) {
                break;
            }
            if ($line[$position] === '\\') {
                $position += 2;
                continue;
            }
            // A doubled quote is an escaped quote, not the end of the string.
            if (($line[$position + 1] ?? '') === $quote) {
                $position += 2;
                continue;
            }
            return ['length' => $position + 1 - $start, 'closed' => true];
        }

        return ['length' => $length - $start, 'closed' => false];
    }
}
