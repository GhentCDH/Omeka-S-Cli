# Custom vocabularies

The `custom-vocabulary:*` commands manage the controlled lists of the
[Custom Vocab](https://omeka.org/s/modules/CustomVocab/) module: a fixed list of terms, a list of URIs
with labels, or the items of an item set. You export a vocabulary to a JSON file and import that file
into another installation, so the lists stay the same across environments.

## Install the Custom Vocab module

The commands need the Custom Vocab module to be installed and active; without it they fail with
*This action requires the 'CustomVocab' module*.

```bash
--8<-- "docs/examples/custom-vocabularies.sh:module"
```

See [modules](modules.md) for more about installing modules.

## Import a vocabulary

`custom-vocabulary:import` reads a JSON file (or a URL) in the format of the module's own export.
A vocabulary of plain terms lists them in `o:terms`:

```json
--8<-- "docs/examples/files/custom-vocabularies/conditions.json"
```

```bash
--8<-- "docs/examples/custom-vocabularies.sh:import-terms"
```

A vocabulary of URIs maps each URI to its label in `o:uris`:

```json
--8<-- "docs/examples/files/custom-vocabularies/languages.json"
```

```bash
--8<-- "docs/examples/custom-vocabularies.sh:import-uris"
```

Vocabularies are matched by label, case-insensitively. If a vocabulary with the same label exists,
the import is skipped with a warning (exit code 0); add `--update` to replace its terms, URIs and
language with those of the file:

```bash
--8<-- "docs/examples/custom-vocabularies.sh:import-update"
```

To update a specific vocabulary, name it (by ID or label) after the file; that implies `--update`.
`--label` sets the label, overriding the one in the file, so this renames the vocabulary:

```bash
--8<-- "docs/examples/custom-vocabularies.sh:import-identifier"
```

## Import an item set vocabulary

A vocabulary can also offer the items of an item set. Item set IDs differ between installations, so
the file refers to the item set by a property value, here its `dcterms:identifier`. The reference is
an `o-ref:ref`, the same [o-ref](https://omeka-s-contrib.github.io/omeka-s-ref/ns) convention that
[`item:import`](items.md) uses for item sets and linked items:

```json
--8<-- "docs/examples/files/custom-vocabularies/collections.json"
```

```bash
--8<-- "docs/examples/custom-vocabularies.sh:import-item-set"
```

The item set must exist before the import, and exactly one item set may have that value: the
import fails if none or several match. The property is matched by namespace through the file's
`@context`, so it does not matter which prefix your installation uses for Dublin Core.

Files in the format of version 0.19.0 and older (`"o:item_set": {"property": …, "value": …}`) are no
longer accepted: export the vocabulary again with the current version.

## List vocabularies

```bash
--8<-- "docs/examples/custom-vocabularies.sh:list"
```

```text
+----+------------+------+----------+-------+
| Id | Label      | Lang | Type     | Owner |
+----+------------+------+----------+-------+
| 1  | Condition  | en   | literal  | Admin |
| 2  | Language   | en   | uri      | Admin |
| 3  | Collection | en   | resource | Admin |
+----+------------+------+----------+-------+
Condition
Language
Collection
```

The type is `literal` for terms, `uri` for URIs and `resource` for an item set. `--json` and `--csv`
give the same columns in machine-readable form.

## Export a vocabulary

`custom-vocabulary:export` prints the vocabulary as JSON, or writes it to the file you name. The
output is the import format, so you can import it on another installation:

```bash
--8<-- "docs/examples/custom-vocabularies.sh:export"
```

An item set vocabulary is exported with an `o-ref:ref` to its item set, by default by its
`dcterms:identifier`, and an `@context` that declares the prefixes. `o:title` is informational; the
import ignores it.

```bash
--8<-- "docs/examples/custom-vocabularies.sh:export-item-set"
```

```text
{
    "@context": {
        "dcterms": "http://purl.org/dc/terms/",
        "o": "http://omeka.org/s/vocabs/o#",
        "o-ref": "https://omeka-s-contrib.github.io/omeka-s-ref/ns#"
    },
    "o:label": "Collection",
    "o:lang": "en",
    "o:item_set": {
        "o-ref:ref": {
            "dcterms:identifier": "urn:example:item-set:photographs"
        },
        "o:title": "Photographs"
    }
}
```

If your item sets are identified by another property, choose it with `--item-set-property`. The
export fails when the item set has no value for that property.

```bash
--8<-- "docs/examples/custom-vocabularies.sh:export-item-set-property"
```

## Delete a vocabulary

`custom-vocabulary:delete` takes an ID or a label. It does not ask for confirmation.

```bash
--8<-- "docs/examples/custom-vocabularies.sh:delete"
```

A vocabulary that does not exist is an error, unless you pass `--ignore-not-found`; then the command
only notes it and exits with 0, which makes the deletion safe to repeat:

```bash
--8<-- "docs/examples/custom-vocabularies.sh:delete-ignore"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/custom-vocabulary-list.md"

--8<-- "build/docs/commands/custom-vocabulary-import.md"

--8<-- "build/docs/commands/custom-vocabulary-export.md"

--8<-- "build/docs/commands/custom-vocabulary-delete.md"
