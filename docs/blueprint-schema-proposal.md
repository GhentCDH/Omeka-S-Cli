# Blueprint schema — proposed extensions for CLI deployment

This note describes the extensions `omeka-s-cli` adds to the
[Omeka S Playground](https://github.com/ateeducacion/omeka-s-playground) blueprint schema so a
blueprint can drive a **CLI deployment of a real Omeka S instance** (not only the in-browser WASM
runtime). It is written to be proposed back to the Playground maintainers.

Design goals:

- **Backward compatible.** Every addition is optional; a stock Playground blueprint validates
  unchanged against the extended schema. The `omeka-s-cli` tool keeps (and ignores) the runtime-only
  keys `phpConstants`, `debug`, `login`, `landingPage` so Playground blueprints round-trip.
- **Declarative.** The blueprint stays a description of desired state (not a list of steps), which is
  what makes both *apply* and a future *export* natural.

## A. New top-level keys (purely additive — no conflict)

| Key | Shape | Purpose |
|-----|-------|---------|
| `vocabularies` | `[{ prefix, namespaceUri, label, url\|file, comment?, format?, lang? }]` | Import RDF vocabularies. |
| `resourceTemplates` | `[{ source, label?, ignoreDeps? }]` | Import resource-template JSON exports. |
| `settings` | map, **or** a list of maps/`$import` refs | Global settings (`setting` table); the list form allows merging per-module settings exports. |

## B. Changes to existing definitions (additive, but they touch defined shapes)

1. **`modules[].state` — add `download`.** The enum becomes `download | install | activate`.
   `download` means "place the module files but do not install it" — a state a CLI can represent and
   the current enum cannot.

2. **`modules[].version` / `themes[].version` — optional string.** Added as a sibling of
   `name`/`source`/`state`, so it does not disturb the existing `source` `oneOf`
   (`bundled | url | omeka.org`). With an `omeka.org` source the version pins the release; with a
   `url` source the zip already pins it (the field is then advisory / useful for export). If a bare
   sibling `version` is undesirable, an equivalent is `source: { type: "omeka.org", slug, version }`.

3. **Reference entries — `{ "$import": <uri> }`.** Every asset-list item type (currently
   `string | object`) is widened to also allow a reference object that points at an external list
   (itself a valid list for that key), spliced in place. This lets several blueprints share one
   module/theme/vocabulary list without a separate top-level container, and keeps the reference under
   the key it extends. A reference is always an object, so it is unambiguous against the existing
   `modules: ["Name", …]` string form.

## C. Schema built from partials

The schema is organised so each asset list is a reusable definition under `$defs`
(`moduleList`, `themeList`, `vocabularyList`, `resourceTemplateList`, `settings`, `userList`,
`itemList`, `itemSetList`), and thin standalone partial schemas (`assets/blueprints/partials/*.json`)
reference those defs. A bare `modules.json` therefore validates on its own — useful for the shared
lists that `$import` pulls in, and for editor tooling.

## D. Out of scope (kept but ignored by the CLI)

`phpConstants`, `debug`, `login` (autologin), `landingPage`, `preferredVersions.php` — these concern
the browser/WASM runtime or container bootstrap, not a CLI deployment. They remain valid so Playground
blueprints are accepted; the CLI simply does not act on them.
