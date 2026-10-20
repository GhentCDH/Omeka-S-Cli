# Blueprints

A blueprint is a JSON file that declares what an Omeka S instance should contain: modules, themes,
files, vocabularies, resource templates, users, sites and settings. `blueprint:validate` checks a
blueprint, `blueprint:deploy` applies it, and `blueprint:export` writes one from a running instance.

This page covers the commands on an instance that is already installed. The format, the deploy phases
and building an instance from nothing are explained in
[Install automation with blueprints](../automation/blueprints.md).

The examples run in the Omeka S directory and use the blueprints in `~/blueprints`, mainly
[`site.blueprint.jsonc`](../automation/blueprints.md#write-a-blueprint): two modules, a vocabulary, a
resource template, an editor, a site and a few settings.

## Validate a blueprint

`blueprint:validate` checks a blueprint against the
[blueprint schema](https://omeka-s-contrib.github.io/omeka-s-blueprints/), after resolving its
`$import` references. It changes nothing, and it does not need an Omeka S instance.

```bash
--8<-- "docs/examples/blueprints.sh:validate"
```

The validation is strict: an unknown key is an error, which catches typos. Besides the schema, it
checks that every theme a site uses is declared in `themes` (`default` always is), that site slugs are
valid, and that site permissions name declared users. This blueprint has a typo (`stat`) and uses a
theme it does not declare:

```jsonc
--8<-- "docs/examples/files/blueprints/invalid.blueprint.jsonc"
```

The command lists every problem and exits non-zero, so a script can stop before it deploys:

```bash
--8<-- "docs/examples/blueprints.sh:validate-invalid"
```

```text
  - /modules/0: The data (object) must match the type: string
  - /modules/0: Additional object properties are not allowed: stat
  - /modules/0: The required properties ($import) are missing
  - site 'Archive': theme 'freedom' is not declared in themes.
Blueprint validation failed with 4 error(s).
```

A module entry may be a name or an object, so a typo in an object shows up as several errors for the
same entry; look for the *Additional object properties* line. For CI, `--json` prints the result as an
object (the exit code is the same):

```bash
--8<-- "docs/examples/blueprints.sh:validate-json"
```

```text
{
    "valid": true,
    "errors": []
}
```

The schema is downloaded from the specification site and cached for 24 hours. Without network access,
the copy bundled in the PHAR is used. `--refresh` downloads it again right away.

## Validate a partial list

A list that other blueprints pull in with `$import` (a shared module list, a settings file) can be
validated on its own. `--as` names the key the list belongs to: `modules`, `themes`, `files`,
`vocabularies`, `resourceTemplates`, `settings`, `users`, `sites`, `items` or `itemSets`.

```bash
--8<-- "docs/examples/blueprints.sh:validate-partial"
```

## Allow references outside the blueprint directory

The files a blueprint references with a relative path must stay inside the blueprint's own directory.
A blueprint that imports `../shared/modules.jsonc` is rejected:

```text
Reference '../shared/modules.jsonc' in '.../library/library.blueprint.jsonc' resolves outside the blueprint root .../library; use --root to widen it.
```

`--root` widens that directory:

```bash
--8<-- "docs/examples/blueprints.sh:validate-root"
```

`blueprint:deploy` takes the same `--root` option. See
[Local references stay inside the root](../automation/blueprints.md#local-references-stay-inside-the-root).

## Preview a deploy

`--dry-run` loads and validates the blueprint and lists what the deploy would do, without changing
anything. On an installed instance, add `--skip core` as you would for the real deploy; otherwise the
dry run also lists the core phase, which would reset the instance:

```bash
--8<-- "docs/examples/blueprints.sh:dry-run"
```

```text
Phase 1/2: Install core, modules, themes and files
──────────────────────────────────────────────────

Modules
would download module 'Common' (Common)
would download module 'Sharing' (Sharing)
would install module 'Common'
would enable module 'Common'
would install module 'Sharing'
would enable module 'Sharing'

Themes
default: already present, nothing to download
...
Sites
would create site 'collections'
would grant 'editor@example.org' the editor role on site 'collections'

Settings
would set 'installation_title'
would set 'administrator_email'
would set 'pagination_per_page'
Dry run complete.
```

A dry run checks which modules and themes are on disk and which sites exist. It does not check which
modules are installed, or which vocabularies, resource templates and users exist, so it can list
actions that the real deploy skips.

## Deploy onto an existing instance

To apply a blueprint to an instance that is already installed, skip the core phase and confirm with
`--force`:

```bash
--8<-- "docs/examples/blueprints.sh:deploy"
```

Without `--force`, the deploy refuses to touch an installed instance. Without `--skip core`, the core
phase runs and **resets the instance**: it drops all database tables and installs Omeka S again (see
[the core phase](../automation/blueprints.md#the-core-phase)).

The deploy runs the phases in a fixed order and installs each module in a separate process, so a
module that depends on another one finds it active. See
[Phases and order](../automation/blueprints.md#phases-and-order).

## Deploy again

A deploy can be repeated. Modules and themes already on disk, existing vocabularies, resource
templates, users and sites, and permissions a user already has are left alone, each with a note.
Settings and `files` are written on every deploy.

```text
Vocabularies
  Collection: Use --update to update the existing vocabulary.

Resource templates
The resource template with label 'Book' already exists. Use --update to force update.

Users
User with email 'editor@example.org' already exists.

Sites
site 'collections' already exists, skipping (use --update to update it).
```

`--update` replaces what exists with what the blueprint declares: it downloads the modules and themes
again (installed modules are not upgraded; use [`module:upgrade`](modules.md) for that), updates
vocabularies and resource templates, brings sites (title, summary, theme, visibility, default site)
and permission roles in line with the blueprint:

```bash
--8<-- "docs/examples/blueprints.sh:update"
```

Existing users are never changed, and permissions are never removed.

## Skip phases

`--skip` takes a comma-separated list of phases: `core`, `modules`, `themes`, `files`,
`vocabularies`, `resourceTemplates`, `users`, `sites`, `itemSets`, `items`, `settings`. For example, to re-apply the
configuration on an instance whose modules you manage separately:

```bash
--8<-- "docs/examples/blueprints.sh:skip"
```

A skipped phase is reported as `skipped (--skip)`; a phase the blueprint does not declare as
`nothing to do`.

## Export an instance

`blueprint:export` reads the instance and writes a blueprint that describes it, to a file or, without
a file name, to standard output. Validate the result before you deploy it elsewhere:

```bash
--8<-- "docs/examples/blueprints.sh:export"
```

The export contains the Omeka S version (as `preferredVersions.omeka`), the modules with their
version and state, the themes, the vocabularies other than `dcterms` and `dctype`, and the sites.
Settings, resource templates and users are not exported yet.

Omeka S does not record where a vocabulary was imported from. The export looks the vocabulary up in a
public index; when that fails, it writes an empty `source` and lists the vocabulary in the header
comment. This includes the `foaf` and `bibo` vocabularies Omeka S installs itself. Fill in the
source, or remove the entry, before you deploy the blueprint.

`--include site-permissions` adds each site's permissions, and a `users` entry (never with a
password) for every user they name:

```bash
--8<-- "docs/examples/blueprints.sh:export-permissions"
```

```text
// Generated by omeka-s-cli blueprint:export on 2026-10-12T15:48:06+00:00.
// First cut: modules, themes, vocabularies and sites; site permissions and the users they name with
// --include site-permissions (settings, other users and resource templates are not exported yet).
// These vocabularies have no source - add a "source" before deploying: bibo, foaf, coll.
{
    "preferredVersions": {
        "omeka": "4.2.1"
    },
    "modules": [
        {
            "name": "Common",
            "state": "activate",
            "version": "3.4.93"
        },
...
    "users": [
        {
            "email": "admin@example.org",
            "username": "Site Admin",
            "role": "global_admin",
            "isActive": true
        },
        {
            "email": "editor@example.org",
            "username": "Collection Editor",
            "role": "editor",
            "isActive": true
        }
    ],
    "sites": [
        {
            "title": "Collections",
            "slug": "collections",
            "setAsDefault": true,
            "permissions": [
                {
                    "user": "admin@example.org",
                    "role": "admin"
                },
                {
                    "user": "editor@example.org",
                    "role": "editor"
                }
            ]
        }
    ]
}
```

The deploy makes the first global administrator the owner of every site it creates, with the admin
role on it, so that user appears in the permissions too.

## Bundle a blueprint

Download everything a blueprint needs into one directory, with a blueprint that only refers to files
inside it: the Omeka S release, modules, themes, vocabularies, resource templates, files, and the item
and item-set JSON-LD files and media files (`items/`, `item-sets/`, `media/`). See [Bundle a blueprint for offline deploys](../automation/blueprints.md#bundle-a-blueprint-for-offline-deploys).

```bash
--8<-- "docs/examples/blueprints.sh:bundle"
```

## Check that a blueprint is self-contained

`--self-contained` also rejects every source outside the blueprint's directory: catalog names, git
repositories, URLs, and a missing `x-omeka-s-cli.install.source`. `blueprint:deploy` accepts the same
flag.

```bash
--8<-- "docs/examples/blueprints.sh:validate-self-contained"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/blueprint-validate.md"

--8<-- "build/docs/commands/blueprint-bundle.md"

--8<-- "build/docs/commands/blueprint-deploy.md"

--8<-- "build/docs/commands/blueprint-export.md"
