# Omeka-S-Cli

Omeka-S-CLI is a command line tool to manage Omeka-S installs.

## Usage

    omeka-s-cli <command> [options]
    omeka-s-cli <command> --help

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

## Requirements

- PHP (>= 8) with PDO_MySQL and Zip enabled
- Omeka-S (>= 3.2)

## Installation

- Clone this repository. 
- Create a symlink to `bin/omeka-s-cli.phar` in `/usr/local/bin/omeka-s-cli`.

## To do

- [ ] Core management (version, latest-version, install, update)
- [ ] Config management (list, get, set)

## Credits

Built @ the [Ghent Center For Digital Humanities](https://www.ghentcdh.ugent.be/), Ghent University by:

* Frederic Lamsens

Inspired by:

- [Libnamic Omeka S Cli](https://github.com/Libnamic/omeka-s-cli/)
- [biblibre Omeka CLI](https://github.com/biblibre/omeka-cli)