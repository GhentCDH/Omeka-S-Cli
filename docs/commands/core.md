# Core

The `core:*` commands download, install, update and inspect Omeka S itself. Together with
[`config:create-db-ini`](#configure-the-database-connection) they take a server from an empty directory to
a working Omeka S installation.

## Download Omeka S

`core:download` fetches an Omeka S release from GitHub, verifies it, and unpacks it into the
destination directory. Without a version it downloads the latest release.

```bash
--8<-- "docs/examples/core.sh:download"
```

The destination must be empty, unless you pass `--force`. Add `--create-destination` to create a
directory that does not exist yet. To pin a release, give its version number:

```bash
--8<-- "docs/examples/core.sh:download-version"
```

## Install the core from a release ZIP

Use a release ZIP you already have (a path or a URL) instead of downloading one:

```bash
--8<-- "docs/examples/core.sh:download-source"
```

## Configure the database connection

Omeka S reads its database credentials from `config/database.ini`. `config:create-db-ini` writes that
file for the installation in the current directory:

```bash
--8<-- "docs/examples/core.sh:database-ini"
```

The database must exist and the user must be allowed to create tables in it. Pass the password from
an environment variable or a secret store, so it does not end up in your shell history.

## Install Omeka S

`core:install` runs the Omeka S installer: it creates the database tables, the administrator account,
and the basic settings.

```bash
--8<-- "docs/examples/core.sh:install"
```

!!! warning "Set the administrator password"
    Without `--admin-email` and `--admin-password`, the administrator is `admin@example.com` with the
    password `admin`. Always set both on a server that others can reach.

## Check the status

`core:status` reports `not_installed`, `needs_migration` or `installed`. `core:version` prints the
version of the code on disk (add `--installed` for the version recorded in the database).

```bash
--8<-- "docs/examples/core.sh:status"
```

```text
installed
4.2.1
```

For scripts, the `--is-installed`, `--needs-migration` and `--is-ready` flags print nothing and
answer through the exit code (0 for yes, 1 for no). That makes an installation step safe to re-run:

```bash
--8<-- "docs/examples/core.sh:is-installed"
```

`--json` gives the full status as an object:

```bash
--8<-- "docs/examples/core.sh:json"
```

## Update Omeka S

`core:update` replaces the Omeka S code with a newer release (the latest, unless you name a version)
It replaces `application/`, `vendor/` and the files in the root directory, and leaves `config/`,
`files/`, `logs/`, `modules/` and `themes/` alone. A new release can change the
database schema; `core:migrate` applies those changes, like the *Update database* button in the
admin interface.

```bash
--8<-- "docs/examples/core.sh:update"
```

!!! tip "Back up first"
    Take a database dump and a copy of the Omeka S directory before updating a production instance.

## Work outside the Omeka S directory

Every command that operates on an installation finds it by looking at the current directory and its
parents. From anywhere else, point to it with `--base-path`:

```bash
--8<-- "docs/examples/core.sh:base-path"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/core-download.md"

--8<-- "build/docs/commands/config-create-db-ini.md"

--8<-- "build/docs/commands/core-install.md"

--8<-- "build/docs/commands/core-status.md"

--8<-- "build/docs/commands/core-version.md"

--8<-- "build/docs/commands/core-update.md"

--8<-- "build/docs/commands/core-migrate.md"
