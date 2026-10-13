# Omeka-S-Cli

omeka-s-cli is a command line tool to install, configure and maintain [Omeka S](https://omeka.org/s/)
instances. It works directly on an Omeka S installation on the same server, so you can script
everything you would otherwise click through in the admin interface, and more: downloading the core,
modules and themes, upgrades, users, sites, vocabularies, resource templates and settings.

## What it does

- **Core**: download any Omeka S version, install it, update it and run the database migrations.
- **Modules and themes**: search the [omeka.org](https://omeka.org/s/modules/) and
  [Daniel Berthereau's](https://daniel-km.github.io/UpgradeToOmekaS/en/omeka_s_modules.html)
  catalogs, download from a catalog, a zip release or a git repository, picking the latest version
  that is compatible with your Omeka S; install, upgrade, enable, disable and delete, one at a time
  or in batch.
- **Users and sites**: add, update and delete users, manage API keys, create sites and give users a
  role on them.
- **Data model**: import vocabularies (from a file, a URL or the LOV repository), custom vocabularies
  and resource templates.
- **Settings**: get and set global settings; export and import global, site and user settings.
- **Blueprints**: describe a complete instance in one file and deploy it with one command.
- **Dummy data**: generate items and item sets to test a site or a module.

Most commands can print JSON, tables or CSV, and use exit codes you can rely on in scripts.

## Quick start

Install omeka-s-cli (see [Installation](installation.md) for the requirements):

```bash
--8<-- "docs/examples/installation.sh:install"
```

Download Omeka S, configure the database and run the installer:

```bash
--8<-- "docs/examples/core.sh:download"
--8<-- "docs/examples/core.sh:database-ini"
--8<-- "docs/examples/core.sh:install"
```

Then continue with [Usage](usage.md) for how the commands work, or go straight to
[install automation](automation/index.md).
