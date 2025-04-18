# Omeka-S-Cli

Omeka-S-CLI is a command line tool to manage Omeka S installs.

## Usage

    omeka-s-cli <command> [options]
    omeka-s-cli <command> --help

Commands:

```
config
  config:modules         Export list of modules
  config:themes          Export list of themes
module
  module:delete          Delete module
  module:disable         Disable module
  module:download        Download module
  module:enable          Enable module
  module:install         Install module
  module:list            List downloaded modules
  module:repositories    List available module repositories
  module:search          Search/list available modules
  module:status          Get module status
  module:uninstall       Uninstall module
  module:upgrade         Uninstall module
theme
  theme:delete           Delete theme
  theme:download         Download theme
  theme:list             List downloaded themes
  theme:search           Search/list available modules
  theme:status           Get theme status

Run `<command> --help` for specific help

```

## Requirements

- PHP (>= 8) with PDO_MySQL and Zip enabled
- Omeka-S (>= 3.2)

## Installation

 - Download "omeka-s-cli.phar" from latest release.

## Build

This project uses https://github.com/box-project/box to create a phar file.

### box global install

```bash
composer global require humbug/box
```
### compile phar

```bash
box compile
```

## To do

- [ ] Core management (version, latest-version, install, update)
- [ ] Config management (list, get, set)

## Credits

Built @ the [Ghent Center For Digital Humanities](https://www.ghentcdh.ugent.be/), Ghent University by:

* Frederic Lamsens

Inspired by:

- [Libnamic Omeka S Cli](https://github.com/Libnamic/omeka-s-cli/)
- [biblibre Omeka CLI](https://github.com/biblibre/omeka-cli)