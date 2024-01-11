# Omeka-S-Cli

Omeka-S-CLI is a command line tool to manage Omeka-S installs.

## Requirements

- PHP (>= 8)
- Composer 2
- Omeka-S install (>= 3.2)

## Installation

- Clone this repository and install dependencies using `composer install`. 
- Create a symlink in `/usr/local/bin` to `bin/omeka-s-cli`.

## Usage

    omeka-s-cli <command> [options]

Commands:

```
module
  module:available    List available modules
  module:delete       Delete module
  module:disable      Disable module
  module:download     Download modules
  module:enable       Enable module
  module:install      Install module
  module:list         List downloaded modules
  module:status       Get module status
  module:uninstall    Uninstall module
  module:upgrade      Upgrade module
theme
  theme:available     List available themes
  theme:delete        Delete theme
  theme:download      Download theme
  theme:list          List downloaded themes
  theme:status        Get theme status
```

## To do

- [ ] Core management (version, latest-version, install, update)
- [ ] Config management (list, get, set)


## Inspired by

- [Libnamic Omeka S Cli](https://github.com/Libnamic/omeka-s-cli/)
- [biblibre Omeka CLI](https://github.com/biblibre/omeka-cli)