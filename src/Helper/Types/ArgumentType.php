<?php

namespace OSC\Helper\Types;

enum ArgumentType {
    case GitRepo;
    case ZipUrl;
    case IdVersion;
}