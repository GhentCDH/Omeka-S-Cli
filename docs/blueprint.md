# Blueprints

A **blueprint** is a declarative JSON (or [jsonc](#jsonc)) file describing an Omeka S environment:
the modules, themes, vocabularies, resource templates, users and settings that should be present.
`blueprint:deploy` reads it and drives the existing CLI commands to bring an instance to that state.

The format is a backward-compatible **superset** of the
[Omeka S Playground](https://github.com/ateeducacion/omeka-s-playground) blueprint, so a Playground
blueprint is accepted as-is (runtime-only keys such as `phpConstants`, `debug`, `login` and
`landingPage` are ignored — they concern the in-browser runtime, not a real instance). The
extensions we add are proposed upstream; see
[blueprint-schema-proposal.md](blueprint-schema-proposal.md).

The JSON schema is bundled at
[`assets/blueprints/omeka-s-cli.blueprint-schema.json`](../assets/blueprints/omeka-s-cli.blueprint-schema.json).
Point your editor at it with a `$schema` key for completion and inline validation.

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
  (`modules`, `themes`, `vocabularies`, `resourceTemplates`, `settings`, `users`, `items`,
  `itemSets`) instead of a full blueprint.
- **`blueprint:deploy`** validates, then runs the phases in order. `--dry-run` prints the ordered
  actions without changing anything. `--update` re-downloads/updates resources that already exist.
  `--skip` takes a comma-separated list of phases to skip. `--force` is required to act on an
  instance that is already installed (see [the core phase](#the-core-phase)). The `--db-*` and
  `--admin-*` flags feed the core phase; secrets are only ever passed as flags, never stored in the
  blueprint.

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

The first cut exports **modules** (name + version + state), **themes** (name + version; `default` is
marked `bundled`) and **vocabularies**, as jsonc with a header comment.

- Omeka's built-in vocabularies (`dcterms`, `dctype`) are skipped.
- Omeka does not store where a vocabulary's RDF was imported from, so the source is resolved
  **best-effort** against the GhentCDH vocabulary index. A vocabulary that can't be resolved is
  written with an empty `"url": ""` and listed in the header comment — fill in a `url` or `file`
  before deploying it.

Not yet exported: settings, users, resource templates (and the `--split`/`--output-dir`/
`--resolve-urls` output options). See [blueprint-roadmap.md](blueprint-roadmap.md).

## Phases (deploy order)

| Phase | Blueprint key | What it does |
|-------|---------------|--------------|
| core | (bootstrap) | `core:download` → write `database.ini` → `core:install` (see below) |
| modules | `modules` | `module:download` (+ `module:install` / `module:enable`) |
| themes | `themes` | `theme:download` |
| vocabularies | `vocabularies` | `vocabulary:import` |
| resource templates | `resourceTemplates` | `resource-template:import` |
| users | `users` | `user:add` |
| settings | `settings` | `config:set` |

Vocabularies run before resource templates so the properties/classes a template references already
exist, and settings run last so a module writing its defaults at install time cannot overwrite them.
When the blueprint installs or activates modules, deploy crosses a process boundary after the module
phase — a module's services only become available at the next Omeka bootstrap — so the
module-dependent phases run in a fresh process automatically.

## The core phase

The `core` phase can build a whole instance from nothing, so a single `blueprint:deploy` takes you
from a bare machine to a running site. It:

1. **Downloads the core** into `--base-path` if that location is not already an Omeka S install
   (version from `preferredVersions.omeka`, else the latest).
2. **Writes `config/database.ini`** from the `--db-*` flags when the instance has no credentials yet.
   An existing, real `database.ini` is kept, so a reset reuses the instance's own credentials. The
   database is created if it does not exist.
3. **Installs the core** (`core:install`) with the admin account from `--admin-*` and the
   title/locale/timezone from the blueprint's `siteOptions`.

Safety and reset:

- Deploying onto an **already-installed** instance requires **`--force`** (deploy refuses otherwise).
- With `--force`, the core phase **resets** the instance: it drops all database tables and reinstalls
  the schema. The downloaded `modules/` and `themes/` files are left in place; the module/theme
  phases then reconcile their versions and states.
- To **sync** a blueprint onto an existing site without reinstalling, skip the core phase:
  `--skip core --force`.

`--base-path` tells the core phase where the instance lives (or should be created). Secrets
(`--db-password`, `--admin-password`) are passed as flags only — they never live in the blueprint.

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
    { "name": "Foo", "source": { "type": "url",
        "url": "https://example.org/Foo-1.0.0.zip" } }, // from a zip release
    { "$import": "./modules.extra.jsonc" }
]
```

- **`name`** (required in object form) — the module id (its directory name).
- **`state`** — `download` (place files only), `install`, or `activate` (install **and** enable).
  Defaults to `activate`.
- **`version`** — pin a specific release (resolved as `module:download name:version`).
- **`source`** — `{ "type": "bundled" }` (ships with core, skipped), `{ "type": "url", "url": … }`
  (a zip release or git URL), or `{ "type": "omeka.org", "slug": …, "version": … }`.

Modules are installed and enabled in the order you list them, so declare a module **before** the
ones that depend on it (e.g. `Common` first). If the order is wrong, Omeka reports a clear
dependency error.

### `themes`

Same shape as modules, minus `state` (themes have no install step; they are activated per site). A
`bundled` source is skipped.

```jsonc
"themes": [
    { "name": "default", "source": { "type": "bundled" } },
    { "name": "freedom", "version": "1.0.7" }
]
```

### `vocabularies`

Each entry mirrors the `vocabulary:import` inputs: identifying fields plus exactly one RDF source
(`url` or `file`).

```jsonc
"vocabularies": [
    {
        "prefix": "schema",
        "namespaceUri": "https://schema.org/",
        "label": "schema.org",
        "url": "https://schema.org/version/latest/schemaorg-current-https.rdf"
    }
]
```

Optional: `comment`, `format`, `lang`. A relative `file` is resolved against the blueprint's
location.

### `resourceTemplates`

```jsonc
"resourceTemplates": [
    { "source": "../resource-template/base_resource.json", "label": "My Template", "ignoreDeps": false }
]
```

`source` (required) is a path or URL to a resource-template JSON export. A relative path is resolved
against the blueprint's location. Requires the `Common` module to be active.

### `users`

```jsonc
"users": [
    { "email": "editor@example.org", "username": "Editor", "role": "editor", "password": "secret", "isActive": true }
]
```

`email` is required; `role` defaults to `author`. Creating a user is idempotent (an existing email is
left untouched). Valid roles: `global_admin`, `site_admin`, `editor`, `reviewer`, `author`,
`researcher`.

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

### Runtime-only keys (ignored)

`phpConstants`, `debug`, `login`, `landingPage`, and `preferredVersions.php` are accepted (so
Playground blueprints validate) but not acted on. `preferredVersions.omeka` and `siteOptions` are
read where relevant.

### Not yet applied

`items`, `itemSets`, `site`/`sites` are part of the schema and are validated, but applying them
(creating sites and content) is a later milestone.

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
Within a resolved list, entries sharing a natural identity (module/theme `name`, vocabulary `prefix`,
resource-template `label`, user `email`, item/item-set `title`) collapse to the **last** occurrence,
so a later inline entry overrides an imported one.

## jsonc

Blueprints may use `//` and `/* */` comments and trailing commas. Comments are stripped before
parsing, so the meaning is identical to plain JSON.
