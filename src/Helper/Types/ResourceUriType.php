<?php

namespace OSC\Helper\Types;

enum ResourceUriType {
    case GitRepo;
    case GitHubRepo;
    case ZipUrl;
    case ZipFile;
    case IdVersion;
}