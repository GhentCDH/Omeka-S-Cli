# Installation

omeka-s-cli ships as a single PHAR file: a self-contained PHP archive that you run like any other
command. Install it on the server that hosts Omeka S.

## Requirements

- PHP 8.1 or newer, command line version, with the `pdo_mysql` and `zip` extensions
- Omeka S 4 or newer (or none yet: omeka-s-cli can [download and install it](commands/core.md))
- `git`, only to download modules or themes from a git repository
- the `curl` extension is recommended; without it PHP's HTTP stream wrapper is used, which needs
  `allow_url_fopen`

Check the PHP version and the required extensions:

```bash
--8<-- "docs/examples/installation.sh:requirements"
```

```text
PHP 8.2.34 (cli) (built: Oct  6 2026 01:32:07) (NTS)
...
pdo_mysql
zip
```

## Install the PHAR

Download the latest release and install it as `omeka-s-cli` in a directory on your `PATH`:

```bash
--8<-- "docs/examples/installation.sh:install"
```

Check that it runs:

```bash
--8<-- "docs/examples/installation.sh:version"
```

The download URL always points at the latest release. To pin a version, replace `latest/download`
with `download/v<version>`, e.g. `.../releases/download/v0.18.1/omeka-s-cli.phar`. All releases are
listed on the [GitHub releases page](https://github.com/GhentCDH/Omeka-S-Cli/releases).

## Update

`self-update` replaces the PHAR with the latest release. It needs write access to the file, hence
`sudo` for a PHAR in `/usr/local/bin`:

```bash
--8<-- "docs/examples/installation.sh:self-update"
```

--8<-- "build/docs/commands/self-update.md"

## Run as the web server user

omeka-s-cli works directly on the files and the database of an Omeka S installation. Run it as the
user that owns the installation, usually the web server user (`www-data` on Debian and Ubuntu,
`apache` on RHEL and derivatives): prefix the commands with `sudo -u www-data`. Files that the CLI
creates as `root` (downloaded modules and themes, `config/database.ini`, files in `files/`) cannot be
changed by Omeka S afterwards.

## Build from source

To run the development version, see [Development](development.md).
