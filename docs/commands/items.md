# Items and item sets

The `item:*` and `item-set:*` commands list, search, export and import items and item sets. The
`media:*` commands add media to items.

Exports are *portable* JSON-LD: one resource per file, which you can import into another installation.
This is how you seed a demo or test installation with content that you curated on another one. See
[Export items](#export-items) for what the format keeps.

## Import an item set

`item-set:import` creates an item set from a JSON-LD file or URL that holds one item set:

```bash
--8<-- "docs/examples/items.sh:import-item-set"
```

??? example "`~/content/paintings.jsonld`"

    ```json
    --8<-- "docs/examples/files/items/paintings.jsonld"
    ```

Import the item sets before the items that belong to them.

## Import an item

`item:import` creates an item from a JSON-LD file or URL that holds one item. Add media with `--media`
(`-m`), as `ingester=source`, once per media:

```bash
--8<-- "docs/examples/items.sh:import"
```

??? example "`~/content/mona-lisa.jsonld`"

    ```json
    --8<-- "docs/examples/files/items/mona-lisa.jsonld"
    ```

```text
Item 'Mona Lisa' created (id 1).
```

The `upload` ingester takes a local path or a URL. The other ingesters take what Omeka S expects: a URL
for `url`, `iiif`, `iiif_presentation`, `oembed` and `youtube`, and HTML for `html`. The item and its
media are created together: if Omeka S rejects a media file (for example, an extension that is not
allowed), the item is not created either.

References in the file are resolved on the installation you import into:

- `o:item_set` and links to other items (`"o-ref:ref": {"dcterms:identifier": "obj-1"}`) name a property
  and a value. Import the records they point to first.
- `o:resource_class` is a term and `o:resource_template` a label.
- Properties are matched by vocabulary namespace, so a file may use another prefix than the
  installation does.

A reference that matches nothing, or more than one record, is skipped with a warning; the item is still
created.

```bash
--8<-- "docs/examples/items.sh:import-linked"
```

## Import without creating duplicates

Before creating a resource, `item:import` looks for it on the installation: by its `o-ref:id` (a property
and a value, written by `item:export`), else by its title. When it exists, the command changes nothing,
prints a notice and exits with code 0. So you can run the same import again:

```bash
--8<-- "docs/examples/items.sh:import-again"
```

```text
Item 'Mona Lisa' already exists (id 1); skipped.
```

`--dry-run` reports what would happen without creating anything. `--identify-by <term>` matches on
another property of the file, and `--json` prints the result (`created`, `exists`, `ambiguous` or
`would create`) as JSON.

## When an import fails

The command checks what Omeka S cannot handle on its own, and reports every problem of the file at once.
Nothing is created:

```bash
--8<-- "docs/examples/items.sh:missing-vocabulary"
```

```text
letter.jsonld: property "letters:recipient" uses vocabulary https://example.org/ns/letters#, which is not installed (vocabulary:import)
letter.jsonld: property "dcterms:date": data type "numeric:timestamp" is not available (install the NumericDataTypes module)
```

??? example "`~/content/letter.jsonld`"

    ```json
    --8<-- "docs/examples/files/items/letter.jsonld"
    ```

Install the vocabulary (see [Vocabularies](vocabularies.md)) or the module (see [Modules](modules.md)) and
import again. Omeka S checks the rest itself, such as the properties a resource template requires.

## List and search items

```bash
--8<-- "docs/examples/items.sh:list"
```

```text
+----+-------------------------+-------------------+----------+----------+-------+--------+
| Id | Title                   | Class             | Template | ItemSets | Media | Public |
+----+-------------------------+-------------------+----------+----------+-------+--------+
| 1  | Mona Lisa               | dctype:StillImage |          | 1        | 1     | yes    |
| 2  | Study for the Mona Lisa |                   |          | 0        | 0     | yes    |
+----+-------------------------+-------------------+----------+----------+-------+--------+
```

The command shows 50 items at a time; use `--limit` and `--page` for more. Filter by resource class,
resource template, item set, site, or exact property value (`--property`, `-p`, repeatable):

```bash
--8<-- "docs/examples/items.sh:list-filter"
```

`--public` and `--private` filter on visibility, and `--query` passes any other Omeka S API search
parameter, such as `--query "sort_by=title&sort_order=desc"`.

`item:search` searches the text of all properties, with the same filters:

```bash
--8<-- "docs/examples/items.sh:search"
```

`item-set:list` and `item-set:search` do the same for item sets. `--json` and `--csv` give the data for
scripts.

## Export items

`item:export` takes item ids, or the same filters as `item:list`. One item is printed:

```bash
--8<-- "docs/examples/items.sh:export"
```

```json
{
    "@context": {
        "dcterms": "http://purl.org/dc/terms/",
        "o": "http://omeka.org/s/vocabs/o#",
        "o-ref": "https://omeka-s-contrib.github.io/omeka-s-ref/ns#"
    },
    "@type": "o:Item",
    "o-ref:id": {
        "dcterms:identifier": "obj-2"
    },
    "o:title": "Study for the Mona Lisa",
    "o:is_public": true,
    "dcterms:title": [
        {
            "type": "literal",
            "is_public": true,
            "@value": "Study for the Mona Lisa"
        }
    ],
    "dcterms:identifier": [
        {
            "type": "literal",
            "is_public": true,
            "@value": "obj-2"
        }
    ],
    "dcterms:relation": [
        {
            "type": "resource:item",
            "is_public": true,
            "o-ref:ref": {
                "dcterms:identifier": "obj-1"
            }
        }
    ]
}
```

Several items go to a directory, one file each (`<id>-<title>.jsonld`); `--output` writes one item to a
file:

```bash
--8<-- "docs/examples/items.sh:export-dir"
```

The export is Omeka S's JSON-LD, made portable:

- The `@context` is written into the file.
- Everything that only means something on this installation is left out: ids, owner, dates,
  `property_id`, thumbnails.
- References use names instead of ids: the class term, the template label, the site slug.
- Item sets and linked items are written as `o-ref:ref`, and the item itself gets an `o-ref:id`, both
  with the property you choose with `--reference-by` (default `dcterms:title`). Choose a property whose
  values are unique, such as an identifier. A record without a value for it is reported, and its link
  is left out.

Media and data from modules (such as the Mapping module) are not exported.

`item-set:export` exports item sets the same way:

```bash
--8<-- "docs/examples/items.sh:export-item-sets"
```

## Add media to an item

`media:ingesters` lists the ways media can be added on the installation:

```bash
--8<-- "docs/examples/items.sh:ingesters"
```

```text
+-------------------+-------------------+
| Name              | Label             |
+-------------------+-------------------+
| upload            | Upload            |
| url               | URL               |
| html              | HTML              |
| iiif              | IIIF image        |
| iiif_presentation | IIIF presentation |
| oembed            | oEmbed            |
| youtube           | YouTube           |
+-------------------+-------------------+
```

`media:add` adds one media to an existing item, with an optional `--title` and `--alt-text`:

```bash
--8<-- "docs/examples/items.sh:media-add"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/item-list.md"

--8<-- "build/docs/commands/item-search.md"

--8<-- "build/docs/commands/item-import.md"

--8<-- "build/docs/commands/item-export.md"

--8<-- "build/docs/commands/item-set-list.md"

--8<-- "build/docs/commands/item-set-search.md"

--8<-- "build/docs/commands/item-set-import.md"

--8<-- "build/docs/commands/item-set-export.md"

--8<-- "build/docs/commands/media-add.md"

--8<-- "build/docs/commands/media-ingesters.md"
