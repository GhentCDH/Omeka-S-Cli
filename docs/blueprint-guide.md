# Blueprint guide

A **blueprint** is a single file that describes how an Omeka S site should be set up: which modules
and themes it needs, which vocabularies and templates to load, which users to create and which
settings to apply. Instead of running many commands by hand, you write it down once and let the tool
do the work.

The file is written in JSON. You may add comments (`//`) and leave a comma after the last item — this
relaxed style is called *jsonc*.

## How to use it

```bash
# 1. check the file is correct (nothing is changed)
omeka-s-cli blueprint:validate my-site.blueprint.jsonc

# 2. see what would happen, without changing anything
omeka-s-cli blueprint:deploy my-site.blueprint.jsonc --dry-run

# 3. set up the site
omeka-s-cli blueprint:deploy my-site.blueprint.jsonc
```

Deploying is safe to repeat: things that already exist are left alone. Add `--update` to refresh
existing modules, themes and vocabularies to the newest version.

**Building a brand-new site.** Deploy can also download and install Omeka S itself, so one command
takes you from an empty server to a running site. Tell it where to install and give it the database
and admin details (these are passed as options, never written in the blueprint):

```bash
omeka-s-cli blueprint:deploy my-site.blueprint.jsonc \
    --base-path /var/www/omeka-s \
    --db-host localhost --db-name omeka --db-user omeka --db-password secret \
    --admin-email admin@example.com --admin-password secret
```

**Careful — this can erase data.** If the site is *already* installed, deploy stops and asks you to
add `--force`. With `--force` it **resets** the site: it wipes the database and reinstalls, keeping
the existing database connection details. To update an existing site *without* wiping it, add
`--skip core --force` — that leaves the installation alone and only applies the modules, settings and
so on.

## What you can put in the file

Each part below is optional. The tool handles them in this order.

- **`modules`** — the plugins to add. Each one has a `name` and a `state`:
  - `"download"` — only fetch the files, do not turn it on
  - `"install"` — fetch and install
  - `"activate"` — fetch, install and switch it on (this is the default)

  You can also pin a `version`.

- **`themes`** — the look of the site. Just list the theme names. `default` comes with Omeka, so
  mark it as `bundled` (nothing to download).

- **`vocabularies`** — sets of standard terms (like schema.org) to import. Give a short `prefix`, the
  `namespaceUri`, a `label` and the `url` of the vocabulary file.

- **`resourceTemplates`** — ready-made description forms. Point `source` at a template file.

- **`users`** — accounts to create, with an `email`, a `role` and a `password`.

- **`settings`** — site options, written as `"name": value` pairs (for example the site title).

### Sharing lists between files

If several sites use the same modules, put that list in its own file and pull it in with `$import`:

```jsonc
"modules": [
    { "name": "Common", "state": "activate" },
    { "$import": "./shared-modules.jsonc" }   // adds everything from that file here
]
```

## What is different from the original Playground blueprint

This format is based on the [Omeka S Playground](https://github.com/ateeducacion/omeka-s-playground)
blueprint and stays compatible with it — a Playground file still works here. The additions are:

| Added | Why |
|-------|-----|
| `vocabularies` | The original had no way to import vocabularies. |
| `resourceTemplates` | The original had no way to import description templates. |
| `settings` | Set site options (like the title) from the blueprint. |
| module/theme `version` | Pick a specific version, not always the latest. |
| module state `"download"` | Add a module's files without installing it. |
| `$import` references | Reuse a shared list of modules/themes/etc. across files. |

A few Playground fields are only meaningful in the web-browser version of Omeka (`phpConstants`,
`debug`, `login`, `landingPage`). They are allowed in the file but simply ignored here.

## Full example

```jsonc
{
    // Where the tool looks up the file format (optional, helps your editor).
    "$schema": "https://raw.githubusercontent.com/GhentCDH/Omeka-S-Cli/main/assets/blueprints/omeka-s-cli.blueprint-schema.json",

    "meta": {
        "title": "My archive",
        "description": "A small example site."
    },

    "modules": [
        { "name": "Common", "state": "activate" },       // needed by the template below
        { "name": "AdvancedSearch", "state": "activate", "version": "3.4.51" },
        { "name": "Log", "state": "download" }            // fetched, but left off
    ],

    "themes": [
        { "name": "default", "source": { "type": "bundled" } }
    ],

    "vocabularies": [
        {
            "prefix": "schema",
            "namespaceUri": "https://schema.org/",
            "label": "schema.org",
            "url": "https://schema.org/version/latest/schemaorg-current-https.rdf"
        }
    ],

    "resourceTemplates": [
        { "source": "./templates/book.json" }
    ],

    "users": [
        {
            "email": "editor@example.org",
            "username": "Editor",
            "role": "editor",
            "password": "change-me"
        }
    ],

    "settings": {
        "installation_title": "My archive"
    }
}
```

Save this as `my-site.blueprint.jsonc`, then run `omeka-s-cli blueprint:deploy my-site.blueprint.jsonc`.

---

For the complete list of every field and option, see [blueprint.md](blueprint.md).
