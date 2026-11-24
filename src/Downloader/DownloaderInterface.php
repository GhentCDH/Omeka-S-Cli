<?php

namespace OSC\Downloader;

interface DownloaderInterface
{
    public function download(): ?string;

    /** The real URL or path to fetch or clone (credentials included): for code, not for messages. */
    public function getDownloadUrl(): string;

    /** For messages, without credentials: the source as written and its real location. */
    public function display(): string;
}