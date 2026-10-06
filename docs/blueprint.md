# Blueprints

A **blueprint** is a declarative JSON (or [jsonc](#jsonc)) file describing an Omeka S environment:
the modules, themes, files, vocabularies, resource templates, users and settings that should be present.
`blueprint:deploy` reads it and drives the existing CLI commands to bring an instance to that state.

The format is the shared [Omeka S blueprint](https://github.com/omeka-s-contrib/omeka-s-blueprints)
specification (v0), also used by the [Omeka S Playground](https://github.com/ateeducacion/omeka-s-playground).
Implementation-specific settings live under top-level `x-` keys (`x-playground`, `x-omeka-s-cli`), which
other consumers ignore, so a Playground blueprint deploys as-is.

`blueprint:validate` / `blueprint:deploy` download the [`v0` schema](https://omeka-s-contrib.github.io/omeka-s-blueprints/schema/v0/blueprint-schema.json) (the latest `v0.x.y`
release, which never gets breaking changes) and cache it for 24h under `~/.cache/omeka-s-cli`. When
the network is unavailable they fall back to a local copy — which is not committed but is downloaded
on demand (`../scripts/fetch-blueprint-schema.php`) and bundled into the PHAR at build time. Use
`blueprint:validate <source> --refresh` to bypass the cache and re-download immediately.

Point your editor at the same URL with a `$schema` key for completion and inline validation.

Validation is **strict**: an unknown key on a known object is rejected, which catches typos (e.g.
`stat` instead of `state`). Genuine free-form maps — `settings`, `user.settings` and `x-` extensions
— stay open.

## Commands

```
blueprint:validate <source> [--as <type>] [--json]
blueprint:deploy   <source> [--dry-run] [--update] [--force] [--skip <phases>]
                            [--base-path <path>]
                            [--db-host <h>] [--db-port <p>] [--db-name <n>] [--db-user <u>] [--db-password <pw>]
                            [--admin-name <n>] [--admin-email <e>] [--admin-password <pw>]
blueprint:export   [output]
```

- **`blueprint:validate`** checks a blueprint against the schema and runs referential checks (an item
  referencing an undeclared item set, a site permission referencing an undeclared user). Exits
  non-zero on failure. `--as <type>` validates a standalone [partial](#partials-and-import) list
  (`modules`, `themes`, `files`, `vocabularies`, `resourceTemplates`, `settings`, `users`, `items`,
  `itemSets`) instead of a full blueprint.
- **`blueprint:deploy`** validates, then runs the phases in order. `--dry-run` prints the ordered
  actions without changing anything. `--update` re-downloads/updates resources that already exist.
  `--skip` takes a comma-separated list of phases to skip. `--force` is required to act on an
  instance that is already installed (see [the core phase](#the-core-phase)). The `--db-*` and
  `--admin-*` flags feed the core phase; the `--admin-*` flags override the blueprint's
  `install.admin`.

- **`blueprint:export`** reads the live instance and writes a blueprint capturing it. With an
  `[output]` path it writes a file, otherwise it prints to stdout. See [Export](#export).

Deploy is **idempotent**: a resource that already exists is skipped (with a note) unless `--update`
is given. Both `validate` and `deploy` accept a local path or a URL as `<source>`.

## Export

`blueprint:export` is the inverse of deploy — capture a running instance so it can be reproduced
elsewhere.

```bash
blueprint:export ./snapshot.blueprint.jsonc   # write a file
blueprint:export                              # or print to stdout
```

The first cut exports **modules** (name + version + state), **themes** (name + version; `default`
without a version, since it ships with the core) and **vocabularies**, as jsonc with a header comment.

- Omeka's built-in vocabularies (`dcterms`, `dctype`) are skipped.
- Omeka does not store where a vocabulary's RDF was imported from, so the source is resolved
  **best-effort** against the GhentCDH vocabulary index. A vocabulary that can't be resolved is
  written with an empty `"source": ""` and listed in the header comment — fill in its `source`
  before deploying it.

Not yet exported: settings, users, resource templates (and the `--split`/`--output-dir`/
`--resolve-urls` output options). See [blueprint-roadmap.md](blueprint-roadmap.md).

## Phases (deploy order)

| Phase | Blueprint key | What it does |
|-------|---------------|--------------|
| core | (bootstrap) | `core:download` → write `database.ini` → `core:install` (see below) |
| modules | `modules` | `module:download` (+ `module:install` / `module:enable`) |
| themes | `themes` | `theme:download` |
| files | `files` | copy (or extract) files into the Omeka S root |
| vocabularies | `vocabularies` | `vocabulary:import` |
| resource templates | `resourceTemplates` | `resource-template:import` |
| users | `users` | `user:add` |
| settings | `settings` | `config:set` |

Vocabularies run before resource templates so the properties/classes a template references already
exist, and settings run last so a module writing its defaults at install time cannot overwrite them.

A module's services only register at an Omeka bootstrap where that module is active, so deploy runs in
several processes automatically: each module is installed/enabled in its **own** fresh process (in
blueprint order, dependencies first) so a module that depends on another — e.g. on `Common` — sees it
already active; then the module-dependent phases (vocabularies onward) run in one more fresh process.
Deploy prints what each stage does and why it reloads (`Modules ready — reloading Omeka ...`).

Per-phase status lines distinguish the cases: `• Vocabularies` (running), `• Users: nothing to do`
(declared nothing), and `• Settings: skipped (--skip)` (you skipped it). Phases handled in another
stage are silent, not reported as skipped.

## The core phase

The `core` phase can build a whole instance from nothing, so a single `blueprint:deploy` takes you
from a bare machine to a running site. It:

1. **Downloads the core** into `--base-path` if that location is not already an Omeka S install
   (version from `preferredVersions.omeka`, else the latest).
2. **Writes `config/database.ini`** from the `--db-*` flags when the instance has no credentials yet.
   An existing, real `database.ini` is kept, so a reset reuses the instance's own credentials. The
   database is created if it does not exist.
3. **Installs the core** (`core:install`) with the title/locale/timezone from the blueprint's
   `install`, and the admin account from the `--admin-*` flags, else `install.admin`.

Safety and reset:

- Deploying onto an **already-installed** instance requires **`--force`** (deploy refuses otherwise).
- With `--force`, the core phase **resets** the instance: it drops all database tables and reinstalls
  the schema. The downloaded `modules/` and `themes/` files are left in place; the module/theme
  phases then reconcile their versions and states.
- To **sync** a blueprint onto an existing site without reinstalling, skip the core phase:
  `--skip core --force`.

`--base-path` tells the core phase where the instance lives (or should be created). The database
password is only passed as a flag. The admin password may come from `install.admin.password`, but
prefer `--admin-password` so the blueprint holds no secret.

```bash
# from scratch: download + install the core, then deploy everything
blueprint:deploy ./site.blueprint.jsonc --base-path /var/www/omeka-s \
    --db-host db --db-name omeka --db-user omeka --db-password secret \
    --admin-email admin@example.com --admin-password secret

# reset an existing instance and redeploy (keeps its database.ini credentials)
blueprint:deploy ./site.blueprint.jsonc --base-path /var/www/omeka-s --force

# sync config onto an existing site, without touching the core
blueprint:deploy ./site.blueprint.jsonc --skip core --force
```

## Blueprint keys

### `modules`

A list of modules. Each entry is a **name string**, an **object**, or an [`$import`](#partials-and-import)
reference.

```jsonc
"modules": [
    "AdvancedSearch",                                   // by name, defaults to state "activate"
    { "name": "Common", "state": "activate" },
    { "name": "Log", "state": "download" },             // downloaded but not installed
    { "name": "AdvancedSearch", "version": "3.4.51" },  // pin a version
    { "name": "Foo", "source": "https://example.org/Foo-1.0.0.zip" },  // from a zip release
    { "name": "Bar", "source": "gh:owner/Bar", "version": "1.2.0" },  // a git tag
    { "$import": "./modules.extra.jsonc" }
]
```

- **`name`** (required in object form) — the module id (its directory name).
- **`state`** — `download` (place files only), `install`, or `activate` (install **and** enable).
  Defaults to `activate`.
- **`source`** — where to get the module: a zip release URL, a git repository URL
  (`https://…/repo.git`, `git@host:owner/repo.git`) or `gh:owner/repo` — anything `module:download`
  accepts. Without a source, a module already in `modules/` is used as is; otherwise `name` is
  resolved through the omeka.org catalogs.
- **`version`** — the release to use: `module:download name:version` without a source, or the tag
  (`#version`) of a git source. A zip URL already pins the release, so it wins.

Modules are installed and enabled in the order you list them, so declare a module **before** the
ones that depend on it (e.g. `Common` first). If the order is wrong, Omeka reports a clear
dependency error.

### `themes`

Same shape as modules, minus `state` (themes have no install step; they are activated per site). The
`default` theme ships with the core, so it is already present and nothing is downloaded.

```jsonc
"themes": [
    "default",
    { "name": "freedom", "version": "1.0.7" }
]
```

### `files`

Files placed in the Omeka S installation after modules and themes, e.g. a module config file or an
extra asset archive.

```jsonc
"files": [
    { "source": "./cleanurl.config.php", "destination": "config/cleanurl.config.php" },
    { "source": "https://example.org/extra.zip", "destination": "modules/Foo/asset/extra", "extract": true }
]
```

- **`source`** — path or URL; a relative path is resolved against the file that declares the entry
  (the blueprint, or an `$import`ed list).
- **`destination`** — relative to the Omeka S root. Absolute paths and `..` segments are rejected.
- **`extract`** — treat `source` as a zip and extract it into `destination` (a single top-level
  directory in the archive is stripped). Defaults to `false`: the file is copied.

Files are written on every deploy, overwriting what is there.

### `vocabularies`

Each entry mirrors the `vocabulary:import` inputs: identifying fields plus the RDF `source` (a path
or URL).

```jsonc
"vocabularies": [
    {
        "prefix": "schema",
        "namespaceUri": "https://schema.org/",
        "label": "schema.org",
        "source": "https://schema.org/version/latest/schemaorg-current-https.rdf"
    }
]
```

Optional: `comment`, `format`, `lang`, and `labelProperty` / `commentProperty` (RDF properties to use
for labels/comments). A relative `source` is resolved against the file that declares the entry.

### `resourceTemplates`

```jsonc
"resourceTemplates": [
    { "source": "../resource-template/base_resource.json", "label": "My Template", "ignoreDeps": false }
]
```

`source` (required) is a path or URL to a resource-template JSON export. A relative path is resolved
against the file that declares the entry. Requires the `Common` module to be active.

### `users`

```jsonc
"users": [
    { "email": "editor@example.org", "username": "Editor", "role": "editor", "password": "secret", "isActive": true }
]
```

`email` is required; `role` defaults to `author`. Creating a user is idempotent (an existing email is
left untouched). Valid roles: `global_admin`, `site_admin`, `editor`, `reviewer`, `author`,
`researcher`, and any role added by an active module (e.g. `guest`).

> **Security note.** `password` is stored **in clear text** in the blueprint file. Unlike the core
> phase's `--db-password` / `--admin-password` (which are passed as flags and never written to the
> blueprint), a user `password` has no external-reference or secrets mechanism yet. Treat any
> blueprint containing user passwords as a secret in its own right — keep it out of shared version
> control, or omit `password` and set it out of band.

### `settings`

Global settings (the `setting` table). Either an inline map, or a **list** of maps/references merged
in order — handy for pulling in per-module settings exports.

```jsonc
"settings": { "installation_title": "My Archive" }
```

```jsonc
"settings": [
    { "installation_title": "My Archive" },
    { "$import": "./settings.advanced-search.json" }
]
```

### `install`

```jsonc
"install": {
    "title": "My Archive", "locale": "en_US", "timezone": "UTC",
    "admin": { "name": "Admin", "email": "admin@example.org" }
}
```

Used by [the core phase](#the-core-phase) only; ignored with `--skip core`.

### Ignored keys

`meta`, `preferredVersions.php` and `x-` extensions other than `x-omeka-s-cli` (e.g. `x-playground`)
are accepted but not acted on. `preferredVersions.omeka` is read by the core phase.

### Not yet applied

`items`, `itemSets` and `sites` are part of the schema and are validated, but applying them (creating
sites and content) is a later milestone.

## Partials and `$import`

Any asset list may contain a reference entry `{ "$import": "<file-or-url>" }`, which is replaced in
place by the items of the referenced list. This keeps a shared list under the key it extends:

```jsonc
// modules.extra.jsonc — a standalone module list, valid on its own:
//   blueprint:validate modules.extra.jsonc --as modules
[
    { "name": "Log", "state": "download" }
]
```

References resolve relative to the file that contains them, may nest, and are rejected if circular.
Within a resolved list, entries sharing a natural identity (module/theme `name`, file `destination`,
vocabulary `prefix`, resource-template `label`, user `email`, item/item-set `title`) collapse to the
**last** occurrence,
so a later inline entry overrides an imported one — this is what makes *layering* work (import a
shared base list, then override a single entry locally). When an override actually changes a value,
`deploy` and `validate` print an advisory warning so an *accidental* duplicate is still noticed;
re-declaring an identical value is silent.

### Reference forms

The blueprint source (the argument to `blueprint:deploy` / `blueprint:validate`), every `$import`,
and asset paths (`files[].source`, `vocabularies[].source`, `resourceTemplates[].source`) all accept
the same reference forms:

| Form | Example |
| --- | --- |
| Local path | `./modules.extra.jsonc`, `/abs/path/site.blueprint.jsonc` |
| Any URL | `https://example.org/site.blueprint.jsonc` |
| GitHub raw URL | `https://raw.githubusercontent.com/owner/repo/main/site.blueprint.jsonc` |
| GitHub browser URL | `https://github.com/owner/repo/blob/main/site.blueprint.jsonc` |
| GitHub short scheme | `gh:owner/repo@main:site.blueprint.jsonc` |
| GitLab browser URL | `https://gitlab.com/group/project/-/blob/main/site.blueprint.jsonc` |
| GitLab short scheme | `gl:group/project@main:site.blueprint.jsonc` |

Browser "blob" URLs are converted to raw-download URLs automatically. GitLab URLs are recognized by
their `/-/blob/` path segment, so **self-hosted GitLab** instances work too. In the short schemes the
`@<ref>` part (branch, tag, or commit) is optional and defaults to `HEAD`; the GitLab short scheme
targets `gitlab.com` (for other hosts use a full browser or raw URL).

Because references resolve relative to the file that contains them, a blueprint fetched from a repo
can reference its neighbours by filename only (`modules.extra.jsonc`) or by a repo-relative path
(`../shared/base.jsonc`) — the parent (`..`) segments are normalized and stay within the repo. Only
public files are supported (no auth tokens yet).

## jsonc

Blueprints may use `//` and `/* */` comments and trailing commas. Comments are stripped before
parsing, so the meaning is identical to plain JSON.
