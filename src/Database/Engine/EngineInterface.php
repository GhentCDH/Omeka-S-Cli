<?php

namespace OSC\Database\Engine;

use OSC\Database\DumpOptions;
use OSC\Database\DumpWriter;

interface EngineInterface
{
    /**
     * Write a dump of the database to the given writer.
     */
    public function export(DumpOptions $options, DumpWriter $writer): void;

    /**
     * Load a dump from the given stream.
     *
     * @param resource $stream
     */
    public function import($stream): void;

    /**
     * Human readable name of the engine, for messages.
     */
    public function getName(): string;
}
