# Settings

The `config:*` commands read and change the settings Omeka S stores in its database: the global
settings (the *Settings* page of the admin interface), the settings of each site, and the settings of
each user. `config:get`, `config:set` and `config:list` work on single global settings;
`config:export` and `config:import` move all three kinds to and from files, so you can keep them in
version control or copy them to another instance. `config:modules` and `config:themes` list the
installed modules and themes, to rebuild the same setup elsewhere.

The database connection itself (`config/database.ini`) is set up with `config:create-db-ini`, described
on the [Core](core.md#configure-the-database-connection) page.

## Read a setting

`config:get` prints the value of a global setting as JSON: a string comes in double quotes, a list as
a JSON array.

```bash
--8<-- "docs/examples/settings.sh:get"
```

```text
"Omeka S"
25
```

A setting that does not exist is an error (non-zero exit code). Pipe the output through `jq -r .` when
you need the bare string; it also undoes the escaped slashes (`Europe\/Brussels`) in the JSON output.

`config:list` prints all global settings as one JSON object. With an argument, it only shows the
settings whose name contains that text:

```bash
--8<-- "docs/examples/settings.sh:list"
```

```text
{
    "pagination_per_page": 25
}
```

The names of all global settings:

```bash
--8<-- "docs/examples/settings.sh:list-keys"
```

## Change a setting

`config:set` writes a global setting. The value is read as JSON when it is valid JSON, and as a plain
string otherwise:

```bash
--8<-- "docs/examples/settings.sh:set"
```

So `50` is stored as a number and `true`/`false` as booleans. To store a string that would be valid
JSON, such as a year or a number, pass it as a JSON string:

```bash
--8<-- "docs/examples/settings.sh:set-string"
```

Lists and objects are JSON too. `config:set` replaces the whole value, so to add one item to a list,
read it first:

```bash
--8<-- "docs/examples/settings.sh:set-json"
```

Setting a value to `null` deletes the setting:

```bash
--8<-- "docs/examples/settings.sh:delete"
```

!!! note
    `config:set` does not check the name or the value: a typo creates a new setting, and a value of the
    wrong type is stored as given. Compare with `config:get` or `config:list` afterwards.

## Export settings to files

`config:export` writes the settings to JSONC files (JSON that allows comments and trailing commas) in
a directory, which it creates when needed:

```bash
--8<-- "docs/examples/settings.sh:export"
```

It writes one file per module and per kind of setting, named `<module>.<type>.jsonc` for global
settings and `<module>.<type>.<site or user>.jsonc` for site and user settings, e.g.
`core.site_setting.collections.jsonc` (site slug) or `core.user_setting.admin-example-org.jsonc`
(user email). A setting belongs to a module when its name starts with the module id in lower case and
an underscore (`log_` for the Log module); everything else goes to `core`. Files with the same name
are overwritten; other files in the directory are left alone.

Each file also records where it came from: the Omeka S version (for `core`) or the module version, the
site slug or user email, and the export time. The import uses these to check the target instance.

Narrow the export down with `--scope` (`setting`, `site_setting` or `user_setting`), `--module` (a
module id, or `core`), `--site <id>` or `--user <id>`:

```bash
--8<-- "docs/examples/settings.sh:export-filter"
```

When nothing matches, the command warns and writes no files.

## Write a settings file

A settings file needs only `type`, `module` and `settings`; a site or user file also names its target
by `site` (slug) or `user` (email). The two files below set three global settings and three settings
of the site with the slug `collections`:

```jsonc title="settings/core.setting.jsonc"
--8<-- "docs/examples/files/settings/core.setting.jsonc"
```

```jsonc title="settings/core.site_setting.collections.jsonc"
--8<-- "docs/examples/files/settings/core.site_setting.collections.jsonc"
```

## Import settings

`config:import` reads a file, a URL, or a directory (all `*.jsonc` and `*.json` files in it, in name
order). Preview first with `--dry-run`, which checks the files and the targets but writes nothing; add
`-v` to see every key:

```bash
--8<-- "docs/examples/settings.sh:import-dry-run"
```

```text
Dry run, no changes are made.
Previewing settings import; no changes will be written.
Would import 2 of 2 settings file(s).
```

Then import:

```bash
--8<-- "docs/examples/settings.sh:import"
```

The import only writes the keys in the files; other settings keep their value. Site and user files
are matched by site slug and user email, not by the numeric id in the file, so an export from one
instance imports into another. `--scope` and `--module` import only part of the files.

A file is skipped with a warning when its module is not active, or its site or user does not exist on
this instance. A different Omeka S or module version than the one recorded in the file gives a warning
too. With `--strict`, each of these is an error that stops the import.

All files are imported in one database transaction: when one fails, none of the changes are kept.
Pass `--no-transaction` to write every setting immediately instead.

The core `version` setting is never imported, because Omeka S uses it to decide on database
migrations; `--force` imports it anyway.

### Remove settings that are not in the file

With `--prune`, the import also deletes the settings of the same module and kind (and the same site or
user) that the file does not contain, so the instance ends up with exactly the settings in the file:

```bash
--8<-- "docs/examples/settings.sh:import-prune"
```

!!! warning "Prune with a complete file"
    Pruning with a hand-written `core.setting.jsonc` deletes every core setting it does not list, such
    as the administrator email and the allowed file types. Prune with a file from `config:export`, and
    check with `--dry-run -v` first: it lists every key it would delete.

## List the installed modules and themes

`config:modules` lists the downloaded modules with their version and state; `--installed` keeps only
the `active` and `not_active` (installed but disabled) ones:

```bash
--8<-- "docs/examples/settings.sh:modules"
```

```text
+--------+---------+--------+
| Id     | Version | State  |
+--------+---------+--------+
| Common | 3.4.93  | active |
+--------+---------+--------+
```

To set up the same modules on another instance, save the list in the `<id>:<version>` form that
`module:download` accepts:

```bash
--8<-- "docs/examples/settings.sh:modules-export"
```

and download and install each of them on the other instance (see [Modules](modules.md)):

```bash
--8<-- "docs/examples/settings.sh:modules-install"
```

A module that is already there is skipped with a warning, so the loop can run again.

`config:themes` does the same for themes; `--active` lists only the themes that a site uses:

```bash
--8<-- "docs/examples/settings.sh:themes"
```

```text
[
    {
        "id": "default",
        "version": "1.9.3",
        "active": true
    }
]
```

Both commands also have `--env`, which prints the list on one line (`Common:3.4.93 Log:3.4.41`) for
reading; in scripts, use `--json` as above.

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/config-get.md"

--8<-- "build/docs/commands/config-list.md"

--8<-- "build/docs/commands/config-set.md"

--8<-- "build/docs/commands/config-export.md"

--8<-- "build/docs/commands/config-import.md"

--8<-- "build/docs/commands/config-modules.md"

--8<-- "build/docs/commands/config-themes.md"
