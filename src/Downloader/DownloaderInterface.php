<?php

namespace OSC\Downloader;

interface DownloaderInterface
{
    public function download(string $url): ?string;
}