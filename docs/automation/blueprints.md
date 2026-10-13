# Install automation with blueprints

A blueprint is a JSON file that describes an Omeka S instance: the modules and themes it needs, the
files to place in it, the vocabularies and resource templates to import, the users, the sites and the
settings. `blueprint:deploy` reads it and runs the omeka-s-cli commands that bring an instance to that
state. With the database credentials on the command line, the same command also downloads and
installs Omeka S, so one command takes an empty server to a working site.

The format is the shared [Omeka S blueprint specification](https://omeka-s-contrib.github.io/omeka-s-blueprints/),
which documents every key. This page explains how omeka-s-cli applies a blueprint; the commands and
their options are on the [Blueprints commands page](../commands/blueprints.md).

## Write a blueprint

This blueprint, saved as `~/blueprints/site.blueprint.jsonc` like the other examples on this page,
sets up a small collection site:

```jsonc
--8<-- "docs/examples/files/blueprints/site.blueprint.jsonc"
```

Blueprints may contain `//` and `/* */` comments and trailing commas (*jsonc*). The `$schema` key
gives editors that understand JSON Schema completion and inline validation. Every key is optional.

A few things the specification leaves to the tool:

- A module given only by name gets the state `activate` (install and enable). `install` installs it
  without enabling it; `download` only puts the files in `modules/`.
- Without a `source`, a module or theme is looked up by name, as `module:download <name>` and
  `theme:download <name>` do. A `source` can be anything [`module:download`](../commands/modules.md)
  accepts: a zip release URL, a local zip (relative to the blueprint), a git URL or `gh:owner/repo`.
  `version` picks the catalog release or the git tag.
- A module or theme that is already in `modules/` or `themes/` is used as it is, unless `version`
  differs from the one on disk or you deploy with `--update`.
- A user without `password` cannot log in until a password is set (for example with *Forgot
  password*). `role` defaults to `author`.
- A site is identified by its `slug`, or by its title when it has none. Its `theme` must be listed in
  `themes`; `default` ships with Omeka S and always counts.
- `settings` is a map of global settings, or a list of maps and `$import` references merged in order
  (a later value wins).
- `items` and `itemSets` are validated, but not created yet. `meta`, `preferredVersions.php` and
  top-level `x-` keys are accepted and ignored.

Check the blueprint before you deploy it:

```bash
--8<-- "docs/examples/blueprints.sh:validate"
```

## Deploy from scratch

Point `--base-path` at an empty directory (or one that does not exist yet) and give the credentials of
an existing, empty database:

```bash
--8<-- "docs/examples/automation/blueprint-deploy.sh:deploy"
```

The deploy downloads Omeka S, writes `config/database.ini`, installs Omeka S, and then runs the other
phases. The database must exist and the user must be allowed to create tables in it: the deploy does
not create databases. See [the core phase](#the-core-phase) for the details.

!!! warning "Set the administrator"
    The administrator comes from `--admin-name`, `--admin-email` and `--admin-password`, else from
    `install.admin` in the blueprint. Without either, it is `admin@example.com` with the password
    `admin`. Always pass `--admin-password` from an environment variable or a secret store.

## Phases and order

A deploy runs these phases, in this order, and reports each one:

| Phase | Blueprint key | What it does |
| --- | --- | --- |
| `core` | `install`, `preferredVersions.omeka` | download, configure and install Omeka S ([`core:*`](../commands/core.md)) |
| `modules` | `modules` | `module:download`, then `module:install` or `module:enable` |
| `themes` | `themes` | `theme:download` |
| `files` | `files` | copy (or unzip) files into the Omeka S directory |
| `vocabularies` | `vocabularies` | `vocabulary:import` |
| `resourceTemplates` | `resourceTemplates` | `resource-template:import` (needs the Common module) |
| `users` | `users` | `user:add` |
| `sites` | `sites` | `site:add`, `site:update` (with `--update`), `site:set-permission` |
| `settings` | `settings` | `config:set` |

Vocabularies come before resource templates, so the properties a template uses exist. Users come
before sites, because a site permission names a user. Settings come last, so a module that writes its
defaults when it is installed cannot overwrite them. `files` destinations are relative to the Omeka S
directory (no absolute paths, no `..`), so a file can land inside a module that was just downloaded.

A module's services are only available in a process that starts with the module already active. The
deploy therefore installs every module in a new process of its own, in the order of the blueprint,
and runs the phases from `vocabularies` on in one more process. List a module after the modules it
depends on (Common first); if the order is wrong, Omeka S reports the missing dependency and the
deploy stops.

`--skip` leaves out phases, `--dry-run` lists the actions without performing them. See
[the commands page](../commands/blueprints.md#skip-phases).

## Re-run a deploy

A deploy can be repeated: what already exists is left alone. To apply a blueprint to an installed
instance without reinstalling it, skip the core phase and confirm with `--force`:

```bash
--8<-- "docs/examples/automation/blueprint-deploy.sh:sync"
```

Modules and themes on disk, existing vocabularies, resource templates, users and sites, and existing
permissions are skipped with a note. Settings and files are written every time. With `--update`, the
deploy also replaces modules, themes, vocabularies and resource templates, and updates sites and
permission roles. Users and permissions are never removed: a blueprint adds and updates, it does not
delete. See [Deploy again](../commands/blueprints.md#deploy-again).

## The core phase

The core phase runs whenever you do not skip it, and needs `--base-path`. It:

1. downloads Omeka S into `--base-path`, unless that directory already holds an Omeka S installation.
   The version is `preferredVersions.omeka` from the blueprint, else the latest release.
2. writes `config/database.ini` from the `--db-*` options, unless the file already holds credentials.
3. checks that the database is reachable. If Omeka S is already installed in it, the deploy stops,
   unless you pass `--force`: then it **drops all tables** of the database.
4. installs Omeka S with the `title`, `locale` and `timezone` from the blueprint's `install` (defaults
   `Omeka S`, `en_US`, `UTC`) and the administrator from the `--admin-*` options or `install.admin`.

So `--force` without `--skip core` resets an instance and deploys the blueprint on a clean database:

```bash
--8<-- "docs/examples/automation/blueprint-deploy.sh:reset"
```

!!! warning "A reset deletes all content"
    Items, media, sites, users and settings are gone after a reset. Back up the database first.

A reset keeps the existing `database.ini`, so the `--db-*` options are not needed (and are ignored).
The files in `modules/` and `themes/` stay; the module and theme phases install them again. Pass
`--admin-password` again: without it, the new administrator gets the password from
`install.admin.password`, else `admin`.

## Share lists between blueprints

Any list in a blueprint (`modules`, `themes`, `files`, `vocabularies`, `resourceTemplates`, `users`,
`sites`, and the `settings` list) may contain `{ "$import": "<file or URL>" }`. The entry is replaced by
the entries of the referenced list, in place. The site blueprint above imports this module list:

```jsonc
--8<-- "docs/examples/files/blueprints/shared/modules.jsonc"
```

and this settings file:

```jsonc
--8<-- "docs/examples/files/blueprints/shared/settings.json"
```

A shared list can be validated on its own with `--as`:

```bash
--8<-- "docs/examples/blueprints.sh:validate-partial"
```

References are resolved relative to the file that contains them, may be nested, and may not be
circular. An entry that appears twice in a list (the same module or theme `name`, file `destination`,
vocabulary `namespaceUri`, resource template `label`, user `email`, site `slug`) is merged into the
first one: the fields of the later entry replace those of the earlier one, and the entry keeps its
first position. That lets a blueprint import a shared list and change one field, without changing the
module order:

```jsonc
--8<-- "docs/examples/files/blueprints/library/library.blueprint.jsonc"
```

Here `Sharing` stays second in the list, with the state `download`. The merge is shallow: a field
given again (a site's `permissions`, for example) is replaced as a whole. When a merge changes a
value, `blueprint:validate` and `blueprint:deploy` print a warning, so an accidental duplicate does
not go unnoticed.

### Reference forms

The blueprint given on the command line, every `$import`, and the paths in `files`, `vocabularies`,
`resourceTemplates` (`source`) and in `modules` and `themes` (a local zip `source`) accept:

| Form | Example |
| --- | --- |
| Relative path | `./shared/modules.jsonc`, `../shared/modules.jsonc` |
| URL | `https://example.org/blueprints/site.blueprint.jsonc` |
| GitHub file URL | `https://github.com/owner/repo/blob/main/site.blueprint.jsonc` |
| GitHub short form | `gh:owner/repo@main:site.blueprint.jsonc` |
| GitLab file URL | `https://gitlab.com/group/project/-/blob/main/site.blueprint.jsonc` |
| GitLab short form | `gl:group/project@main:site.blueprint.jsonc` |

GitHub and GitLab file URLs are turned into raw download URLs; GitLab URLs are recognised by their
`/-/blob/` segment, so self-hosted GitLab works too. In the short forms, `@<branch, tag or commit>` is
optional (default: `HEAD`); `gl:` always means gitlab.com. A blueprint fetched from a URL resolves its
relative references against that URL, so a blueprint in a repository can import its neighbours. Only
public files can be fetched.

### Local references stay inside the root

A blueprint must not be able to read any file on the server it is deployed on. Inside a blueprint,
absolute paths and `file:` URLs are rejected, and a relative path must stay inside the blueprint root:
the directory of the blueprint given on the command line. The library blueprint above imports
`../shared/modules.jsonc`, outside its own directory, so it needs `--root`:

```bash
--8<-- "docs/examples/blueprints.sh:validate-root"
```

`--root` works the same on `blueprint:deploy`. The path on the command line is not checked: it may be
absolute.

## Capture an existing instance

`blueprint:export` writes a blueprint from a running instance, to reproduce it elsewhere or to start
a blueprint from. It covers modules, themes, vocabularies and sites (and, on request, site permissions
with their users), not settings or resource templates yet. See
[Export an instance](../commands/blueprints.md#export-an-instance).

## Keep secrets out of the blueprint

The database password and the administrator password are command-line options, so the blueprint can
go into version control. `install.admin.password` and the `password` of a user are stored in the
blueprint in clear text: a blueprint that contains them is a secret itself. Leave them out, and set
the passwords with `--admin-password` and [`user:update-password`](../commands/users.md) (or let users
use *Forgot password*).

## Omeka S Playground

The format started as the blueprint of the [Omeka S Playground](https://github.com/ateeducacion/omeka-s-playground),
and a Playground blueprint deploys with omeka-s-cli as it is. Keys that only one tool understands live
under a top-level `x-` key (`x-playground` for the Playground's landing page and login, for example);
omeka-s-cli ignores them. Compared with the original Playground format, the specification adds
`vocabularies`, `resourceTemplates`, `settings`, `files`, module and theme `version`, the module state
`download`, and `$import`.
