# Modules

The `module:*` commands find modules in the online catalogs, download them into the `modules/`
directory of an Omeka S installation, and take them through their life cycle: install, enable,
disable, update, upgrade, uninstall and delete. Commands that take a module id accept it in any case
(`sharing` finds `Sharing`).

## Find a module

`module:search` looks for the query in the id, name, description and tags of every module in the
catalogs:

```bash
--8<-- "docs/examples/modules.sh:search"
```

```text
+-------------+---------------+------------------------+
| Id          | LatestVersion | OmekaVersionConstraint |
+-------------+---------------+------------------------+
| ShareMeBaby | 1.2.4         | ^3.0.0 || ^4.0.0       |
| Sharing     | 1.5.0         | ^4.0.0                 |
+-------------+---------------+------------------------+
```

The `Id` column is what you pass to `module:download`. Add `--extended` for the URL, description, owner
and the catalog a module comes from:

```bash
--8<-- "docs/examples/modules.sh:search-extended"
```

Without a query, `module:search` lists every module. The catalogs are downloaded on first use and cached
for 24 hours in `~/.cache/omeka-s-cli`; `--refresh` fetches them again.

### Catalogs

Modules come from two catalogs, which `module:repositories` lists:

- `omeka.org`: the [official add-ons directory](https://omeka.org/s/modules/), with the full release
  history of each module.
- `daniel-km`: the [module list maintained by Daniel Berthereau](https://github.com/Daniel-KM/UpgradeToOmekaS),
  which also covers modules that are not registered on omeka.org, but only knows their latest release.

When a module is in both, the omeka.org entry wins. `--repository` limits a search to one catalog, and
`--unregistered` shows only the modules that are missing from omeka.org:

```bash
--8<-- "docs/examples/modules.sh:repositories"
```

### Compatibility with your Omeka S version

Run inside an Omeka S installation, `module:search` hides the modules that have no release compatible
with the installed Omeka S version. Outside an installation it lists everything. To see the hidden
ones, add `--include-incompatible`:

```bash
--8<-- "docs/examples/modules.sh:search-incompatible"
```

```text
No modules found for query 'alttext'
+---------+---------------+------------------------+
| Id      | LatestVersion | OmekaVersionConstraint |
+---------+---------------+------------------------+
| AltText | 1.3.0         | ^3.1.0                 |
+---------+---------------+------------------------+
```

## Download and install a module

`module:download` puts a module in the `modules/` directory; `module:install` installs it in Omeka S,
which also enables it:

```bash
--8<-- "docs/examples/modules.sh:download"
```

Without a version, `module:download` picks the most recent release whose Omeka S constraint matches the
installed Omeka S version. If the latest release is too new for your installation, it warns and takes
the newest one that fits.

Both steps are safe to re-run: a module that is already downloaded, or already installed, gives a
warning and exit code 0. To download a module again, over the existing copy, add `--force`.

With `--install`, `module:download` installs the module right after downloading it:

```bash
--8<-- "docs/examples/modules.sh:download-install"
```

The Log module depends on Common: install the dependencies of a module first. The CLI does not resolve
them for you: installing a module whose dependency is missing fails with an error. Note that
`--install` is skipped when the module was already downloaded.

### Pin a version

Add the version after a colon to download a specific release:

```bash
--8<-- "docs/examples/modules.sh:download-version"
```

Pinning works for modules in the omeka.org catalog. The daniel-km catalog only knows the latest
release, so for a module that is only listed there, download an older version from its release zip or
git repository instead (see below).

A pinned version must be compatible with the installed Omeka S version, or the download fails. On
Omeka S 4.2, `module:download csseditor:1.3.0` (a release for Omeka S 3) gives:

```text
Cannot use module 'CSSEditor' version '1.3.0': incompatible with Omeka S 4.2.1.
```

`--force` skips this check. It also lets you download a release that has no Omeka S constraint in the
catalog: the automatic selection treats such releases as incompatible, so pin their version.

### Download from a zip file or a git repository

The argument of `module:download` is a module URI. Besides a catalog id, it accepts a release zip, on
the web or on disk, and a git repository:

| Syntax | Example |
| --- | --- |
| `<id>` | `common` |
| `<id>:<version>` | `sharing:1.4.0` |
| `<zip-url>` | `https://github.com/omeka-s-modules/CSSEditor/releases/download/v1.3.1/CSSEditor-1.3.1.zip` |
| `<zip-file>` | `/tmp/CSSEditor-1.3.1.zip`, `./CSSEditor-1.3.1.zip` |
| `<git-url>[#<ref>]` | `https://github.com/omeka-s-modules/CSSEditor.git#v1.3.1`, `git@github.com:owner/repo.git` |
| `gh:<owner>/<repo>[#<ref>]` | `gh:omeka-s-modules/CSSEditor#v1.3.1` |

A release zip from the web:

```bash
--8<-- "docs/examples/modules.sh:download-zip-url"
```

A zip file on disk (a relative path is relative to the current directory):

```bash
--8<-- "docs/examples/modules.sh:download-zip-file"
```

A git repository, at a tag, branch or commit given after `#` (the default branch without it). `gh:` is
short for a repository on GitHub:

```bash
--8<-- "docs/examples/modules.sh:download-git"
```

`git` must be installed for these. The clone keeps its `.git` directory. With a zip or git source, the
module directory is named after the namespace in the module's `Module.php`, and the version is not
checked against your Omeka S version.

If the downloaded module has a `composer.lock` but no `vendor/` directory, as is common for a git
checkout, `module:download` runs `composer install --no-dev` in it. That needs `composer` on the `PATH`.

### Keep the previous copy

When `--force` replaces a module, the old directory is deleted. Add `--backup` to move it to
`~/.omeka-s-cli/backups/modules/<module>/<date-time>/` instead:

```bash
--8<-- "docs/examples/modules.sh:download-backup"
```

## List modules and check their status

`module:list` shows every module in the `modules/` directory, with its state, the version on disk, the
version recorded in the database, and the latest version in the catalogs:

```bash
--8<-- "docs/examples/modules.sh:list"
```

```text
+---------+---------+--------+---------+------------------+---------------+-----------------+-------------------+
| Id      | Name    | State  | Version | InstalledVersion | LatestVersion | UpdateAvailable | Author            |
+---------+---------+--------+---------+------------------+---------------+-----------------+-------------------+
| Common  | Common  | active | 3.4.93  | 3.4.93           | 3.4.93        | no              | Daniel Berthereau |
| Log     | Log     | active | 3.4.41  | 3.4.41           | 3.4.41        | no              | Daniel Berthereau |
| Sharing | Sharing | active | 1.4.0   | 1.4.0            | 1.5.0         | yes             | Omeka Team        |
+---------+---------+--------+---------+------------------+---------------+-----------------+-------------------+
```

The state is the one Omeka S reports: `active`, `not_active` (installed, but disabled),
`not_installed`, `needs_upgrade` (the files are newer than the installed version), or an error state
such as `invalid_omeka_version` or `invalid_ini`. `--extended` adds the path of each module.

`--active`, `--not-active`, `--not-installed` and `--needs-upgrade` filter on the state (combine them to
show several), `--outdated` on the update column. For anything else, filter the `--json` output with
`jq`:

```bash
--8<-- "docs/examples/modules.sh:list-filter"
```

`module:status` shows the same row for one module, and fails if the module is not in `modules/`:

```bash
--8<-- "docs/examples/modules.sh:status"
```

## Enable and disable modules

A disabled module stays installed, with its data, but Omeka S does not load it:

```bash
--8<-- "docs/examples/modules.sh:enable-disable"
```

Enabling an active module, or disabling a disabled one, gives a warning and exit code 0.

### Act on all modules at once

`module:install`, `module:enable`, `module:disable` and `module:upgrade` accept `--all` instead of a
module id. They act on every module in the matching state, in dependency order: dependencies are
installed, enabled and upgraded before the modules that need them, and disabled after them. The
commands that change modules, except `module:download`, also take `--dry-run`, which reports what they
would do without doing it:

```bash
--8<-- "docs/examples/modules.sh:bulk-dry-run"
```

```text
Dry run, no changes are made.
3 module(s) would be disabled: Sharing, Log, Common.
```

A module that fails does not stop the others.

!!! warning "Install dependencies separately"
    `module:install --all` can fail to install a module whose dependency is installed in the same run
    (for example Common and Log, downloaded together). Install the dependency first, then the rest.

## Update modules

`module:list --outdated` shows the modules for which the catalogs have a newer release:

```bash
--8<-- "docs/examples/modules.sh:outdated"
```

```text
+---------+---------+--------+---------+------------------+---------------+-----------------+------------+
| Id      | Name    | State  | Version | InstalledVersion | LatestVersion | UpdateAvailable | Author     |
+---------+---------+--------+---------+------------------+---------------+-----------------+------------+
| Sharing | Sharing | active | 1.4.0   | 1.4.0            | 1.5.0         | yes             | Omeka Team |
+---------+---------+--------+---------+------------------+---------------+-----------------+------------+
```

`module:update` downloads the newest compatible release over the installed copy (it runs
`module:download --force`). With `--upgrade` it then runs the module's database upgrade, like the
*Upgrade* button in the admin interface:

```bash
--8<-- "docs/examples/modules.sh:update"
```

You can also pass a version (`module:update sharing:1.5.0`). `--all` updates every module that
`--outdated` lists; check the list with `--dry-run` first:

```bash
--8<-- "docs/examples/modules.sh:update-all"
```

!!! note
    The `LatestVersion` column is the latest release in the catalog, even when that release does not
    support your Omeka S version. `module:update` still only downloads a compatible release.

### Upgrade

Without `--upgrade`, an updated module is left in the `needs_upgrade` state, and Omeka S does not load
it until it is upgraded. `module:upgrade` runs the pending upgrades; this is also the step to take after
replacing module files yourself, or after a [core update](core.md#update-omeka-s):

```bash
--8<-- "docs/examples/modules.sh:upgrade"
```

!!! tip "Back up first"
    A module upgrade can change the database. Take a dump before upgrading a production instance.

## Uninstall and delete modules

`module:uninstall` runs the module's uninstaller, which usually removes its tables and settings: the
module's data is lost. `module:delete` then removes its directory:

```bash
--8<-- "docs/examples/modules.sh:uninstall"
```

`module:delete` refuses an installed module. With `--force` it uninstalls the module first, then
deletes it:

```bash
--8<-- "docs/examples/modules.sh:delete-force"
```

To clean up in bulk, `module:uninstall --not-active` uninstalls every disabled module (`--all` every
installed one), and `module:delete --not-installed` removes every module that is not installed. A module
in the `needs_upgrade` state cannot be uninstalled: `module:uninstall --all` skips it with a warning.

```bash
--8<-- "docs/examples/modules.sh:bulk-cleanup"
```

### Missing modules

`module:uninstall`, `module:disable` and `module:delete` fail when the module does not exist. With
`--ignore-not-found` they print a note and exit 0, so a cleanup step can run more than once:

```bash
--8<-- "docs/examples/modules.sh:ignore-not-found"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/module-search.md"

--8<-- "build/docs/commands/module-repositories.md"

--8<-- "build/docs/commands/module-download.md"

--8<-- "build/docs/commands/module-install.md"

--8<-- "build/docs/commands/module-list.md"

--8<-- "build/docs/commands/module-status.md"

--8<-- "build/docs/commands/module-enable.md"

--8<-- "build/docs/commands/module-disable.md"

--8<-- "build/docs/commands/module-update.md"

--8<-- "build/docs/commands/module-upgrade.md"

--8<-- "build/docs/commands/module-uninstall.md"

--8<-- "build/docs/commands/module-delete.md"
