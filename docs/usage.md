# Usage

This page explains how `omeka-s-cli` works in general: how to get help, how it finds the Omeka S
installation, how much it prints, and what it returns to scripts. The commands themselves are described
per domain, starting with [Core](commands/core.md); the options every command accepts are listed in
the [global options](reference/global-options.md).

## Get help

Without arguments, `omeka-s-cli` lists all commands. `--help` after a command shows its arguments,
options and examples, and `--version` prints the version of `omeka-s-cli`:

```bash
--8<-- "docs/examples/usage.sh:help"
```

Command names have the form `<domain>:<action>`: `module:download`, `user:add`, `config:set`. The
domains are `core`, `module`, `theme`, `user`, `site`, `config`, `vocabulary`, `custom-vocabulary`,
`resource-template`, `code-snippet`, `dummy` and `blueprint`, each with its own page in this documentation; `self-update`
is the only command without a domain. A mistyped command name fails with exit code 127 and suggests
the closest one:

```text
Command module:lst not found
Did you mean module:list?
```

## Find the Omeka S installation

Most commands work on an Omeka S installation. They look for it in the current directory and then in
its parents, so any directory inside the installation works:

```bash
--8<-- "docs/examples/usage.sh:subdirectory"
```

A directory counts as an Omeka S installation when it contains `bootstrap.php`,
`application/config/application.config.php` and the `modules`, `themes` and `files` directories.

From anywhere else, pass the path with `--base-path` (`-b`). In scripts, keep it in a variable, here
`$OMEKA_DIR`:

```bash
--8<-- "docs/examples/usage.sh:base-path"
```

Without `--base-path`, outside an installation, a command fails with *Could not find a valid Omeka S
context.* A `--base-path` that does not exist or is not an installation is always an error. Commands
that need the database also fail with *Omeka S is not installed.* until
[`core:install`](commands/core.md#install-omeka-s) has run.

## Run as the web server user

`omeka-s-cli` reads and writes the Omeka S directory with the rights of the user that runs it. Modules
and themes it downloads, and files it creates (for example the media of
[dummy items](commands/dummy-data.md)), belong to that user. If you run it as `root` while the web
server runs as `www-data`, the web server may not be able to update or remove those files later.

Run it as the user that owns the Omeka S files, usually the web server user: prefix the command with
`sudo -u www-data`, or switch to that user first. If files were created by the wrong user, give them
back to the owner of the installation with `chown -R`.

The user also needs to read `config/database.ini`, and to write to its cache directory (see
[below](#cache)).

## Choose how much is printed

Every message has a level, and a command prints the messages up to its verbosity:

| Level | Name | Prints |
| --- | --- | --- |
| 0 | quiet | errors only |
| 1 | outcomes | also warnings and results, such as *Module 'Common' v3.4.93 installed.* |
| 2 | detail | also details: the selected version, download URLs, ... (the default) |
| 3 | debug | also debug messages, such as where the installation was found |

`-v` (`--verbose`) raises the level by one, so it shows the debug messages:

```bash
--8<-- "docs/examples/usage.sh:verbose"
```

```text
Omeka S found at /var/www/omeka-s
4.2.1
```

`--verbosity <level>` sets the level directly; `--quiet` (`-q`) is level 0. Errors are always
printed, even with `--quiet`:

```bash
--8<-- "docs/examples/usage.sh:verbosity"
```

```text
Module 'Common' already exists in '/var/www/omeka-s/modules/Common'. Use the --force option download anyway.
```

Errors go to standard error, everything else (including warnings) to standard output. Messages are coloured, also when the
output is redirected to a file; set the `NO_COLOR` environment variable to turn the colours off:

```bash
--8<-- "docs/examples/usage.sh:no-color"
```

## Choose an output format

Commands that list or report something print a table by default. Many of them also offer `--json`,
`--csv` or `--table`; the *Reference* section of each command page shows which:

```bash
--8<-- "docs/examples/usage.sh:formats"
```

```text
+----+--------------+-------------------+-----------+--------------+
| Id | Display Name | Email             | Is Active | Role         |
+----+--------------+-------------------+-----------+--------------+
| 1  | Admin        | admin@example.org | yes       | global_admin |
+----+--------------+-------------------+-----------+--------------+
id,display_name,email,is_active,role
1,Admin,admin@example.org,yes,global_admin
[
    {
        "id": 1,
        "display_name": "Admin",
        "email": "admin@example.org",
        "is_active": true,
        "role": "global_admin"
    }
]
```

In the table and CSV formats, `true`/`false` become `yes`/`no` and a missing value becomes `unknown`;
JSON keeps the real values. `--json` and `--csv` also silence the info and warning messages, so the
output is safe to parse. For scripts, use `--json` and [jq](https://jqlang.org/):

```bash
--8<-- "docs/examples/usage.sh:jq"
```

`config:modules` and `config:themes` also have `--env`, see
[Settings](commands/settings.md#list-the-installed-modules-and-themes).

## Exit codes and warnings

A command exits with 0 when it succeeds and with a non-zero code when it fails (255 for an error, 127
for an unknown command), so scripts can test it or stop on it with `set -e`.

When there is nothing to do because the work is already done, a command prints a warning and still
exits with 0. Downloading a module that is already there, for example:

```bash
--8<-- "docs/examples/usage.sh:warning"
```

```text
Selected 'Common' v3.4.93 (compatible with Omeka S 4.2.1).
Module 'Common' already exists in '/var/www/omeka-s/modules/Common'. Use the --force option download anyway.
exit code: 0
```

A missing resource, on the other hand, is an error:

```bash
--8<-- "docs/examples/usage.sh:error"
```

```text
User not found: nobody@example.org
exit code: 255
```

Commands that delete or change a named resource (`user:delete`, `module:uninstall`, `site:delete`,
...) accept `--ignore-not-found`: a missing resource is then a warning, and the command exits with 0.
That makes a clean-up step safe to repeat:

```bash
--8<-- "docs/examples/usage.sh:ignore-not-found"
```

```text
User not found: nobody@example.org. Nothing to do.
```

The other way round, `user:add` and `site:add` accept `--ignore-existing`, see [Users](commands/users.md)
and [Sites](commands/sites.md).

## Preview changes

Commands that change modules (`module:install`, `module:uninstall`, `module:enable`, `module:disable`,
`module:upgrade`, `module:update`, `module:delete`), `config:import` and `blueprint:deploy` accept
`--dry-run`. They report what they would do, and change nothing:

```bash
--8<-- "docs/examples/usage.sh:dry-run"
```

```text
Dry run, no changes are made.
Module 'Common' would be installed.
```

## Debug a failure

`--debug` adds the PHP stack trace to an error message, which helps when you report a problem:

```bash
--8<-- "docs/examples/usage.sh:debug"
```

```text
User not found: nobody@example.org
#0 phar:///app/omeka-s-cli/bin/omeka-s-cli.phar/src/Commands/User/DeleteCommand.php(17): OSC\Commands\User\AbstractUserCommand->requireUser('nobody@example....', Object(Omeka\Api\Manager), false)
#1 ...
```

Combine it with `-v` to also see the debug messages that lead up to the error.

## Cache

`omeka-s-cli` caches the catalogs it downloads (the omeka.org module and theme lists, the Daniel-KM
module list, the LOV vocabulary list) and the blueprint schema, in `~/.cache/omeka-s-cli` of the user that runs it
(the system temporary directory when `HOME` is not set). Cached data is used for 24 hours.

To fetch fresh data before that, for example right after a module release, pass `--refresh` to
`module:search` or `theme:search` (and to `blueprint:validate` for the schema):

```bash
--8<-- "docs/examples/usage.sh:refresh"
```

Removing the cache directory is safe; it is filled again when needed:

```bash
--8<-- "docs/examples/usage.sh:clear-cache"
```
