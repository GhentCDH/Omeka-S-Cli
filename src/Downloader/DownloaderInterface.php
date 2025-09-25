<?php

namespace OSC\Downloader;

interface DownloaderInterface
{
    public function download(): ?string;

    public function getDownloadUrl(): string;
}