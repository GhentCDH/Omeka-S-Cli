# Vocabularies

The `vocabulary:*` commands import, update, list and delete the RDF vocabularies that provide the
classes and properties of an Omeka S installation. A vocabulary can come from a local file, a URL, a
JSON configuration file, or a repository such as [Linked Open Vocabularies](https://lov.linkeddata.es/)
(LOV).

## List the vocabularies

`vocabulary:list` shows the installed vocabularies with their prefix, namespace URI and the number of
properties and classes:

```bash
--8<-- "docs/examples/vocabularies.sh:list"
```

```text
+----+------------------------+---------+--------------------------------+---------------+---------------------+--------+
| Id | Label                  | Prefix  | NamespaceUri                   | PropertyCount | ResourcesClassCount | Owner  |
+----+------------------------+---------+--------------------------------+---------------+---------------------+--------+
| 1  | Dublin Core            | dcterms | http://purl.org/dc/terms/      | 55            | 22                  | system |
| 2  | Dublin Core Type       | dctype  | http://purl.org/dc/dcmitype/   | 0             | 12                  | system |
| 3  | Bibliographic Ontology | bibo    | http://purl.org/ontology/bibo/ | 67            | 58                  | system |
| 4  | Friend of a Friend     | foaf    | http://xmlns.com/foaf/0.1/     | 62            | 13                  | system |
+----+------------------------+---------+--------------------------------+---------------+---------------------+--------+
```

These four come with every Omeka S installation. Add `--json` for output a script can process:

```bash
--8<-- "docs/examples/vocabularies.sh:list-json"
```

## Import a vocabulary

`vocabulary:import` reads an RDF file and creates the vocabulary. `--source` takes a local path or a URL;
`--namespace-uri`, `--prefix` and `--label` are required:

```bash
--8<-- "docs/examples/vocabularies.sh:import-file"
```

The namespace URI must match the one the classes and properties in the file use: only the terms in that
namespace are imported, and the import fails if there are none. `--format` is `rdfxml`, `turtle`,
`ntriples`, `jsonld` or `auto` (the default, which guesses the format). The same command imports from a
URL:

```bash
--8<-- "docs/examples/vocabularies.sh:import-url"
```

Use `--comment` to describe the vocabulary. `--lang` picks the language of the labels and comments when
the RDF has several, and `--label-property` / `--comment-property` name the RDF properties to read
them from (by default `rdfs:comment` for comments, and `skos:prefLabel`, `rdfs:label` or a few other
common label properties for labels).

If a vocabulary with the same namespace URI already exists, the command changes nothing, prints a notice
and exits with code 0. To apply changes, see [Update a vocabulary](#update-a-vocabulary).

!!! note
    `--url` and `--file` are deprecated aliases of `--source`; they still work, with a warning.

## Use a configuration file

The options of an import can live in a JSON file, so the import is easy to repeat or to keep in version
control. `vocabulary:create-import-config` takes the same options as `vocabulary:import` and writes them
as a configuration file (without `--output`, it prints the JSON):

```bash
--8<-- "docs/examples/vocabularies.sh:create-config"
```

```json
--8<-- "docs/examples/files/vocabularies/archive.json"
```

`source`, `label`, `namespaceUri` and `prefix` are required; `comment`, `format`, `lang`, `labelProperty`
and `commentProperty` are optional and match the command options. A relative `source` is resolved
against the location of the configuration file, so the file and the RDF can be moved together. Import it
with `--config`:

```bash
--8<-- "docs/examples/vocabularies.sh:import-config"
```

`--config` also accepts a URL; a relative `source` then resolves against that URL.

## Update a vocabulary

When the RDF of an installed vocabulary changes, re-import it with `--update`. The existing vocabulary is
found by its namespace URI:

```bash
--8<-- "docs/examples/vocabularies.sh:update"
```

An update adds new classes and properties and updates the labels and comments of the existing ones. It
does not remove classes or properties that are no longer in the RDF, and it keeps the label, prefix and
comment of the vocabulary itself.

## Find and import from a repository

`vocabulary:search` searches the vocabularies of the known repositories (currently only LOV, with id
`lov`) by id, label, prefix or namespace URI. Without a query it lists them all; `--repository` limits the
search to one repository:

```bash
--8<-- "docs/examples/vocabularies.sh:search"
```

`vocabulary:import-from-repo` imports a result by its id, `<repository>:<prefix>`. The label, prefix,
namespace URI and download URL come from the repository:

```bash
--8<-- "docs/examples/vocabularies.sh:import-from-repo"
```

```text
Resolved to vocabulary 'The Event Ontology' (ns: http://purl.org/NET/c4dm/event.owl#, prefix: event)
Importing vocabulary from https://lov.linkeddata.es/dataset/lov/vocabs/event/versions/2007-10-25.n3 ... done
Successfully created vocabulary 'The Event Ontology'.
```

To match on the prefix or the namespace URI instead of the id, add `--prefix` or `--namespace-uri`.
`--update` updates a vocabulary that is already installed, as in [Update a
vocabulary](#update-a-vocabulary):

```bash
--8<-- "docs/examples/vocabularies.sh:import-from-repo-prefix"
```

The repository data is cached for 24 hours in `~/.cache/omeka-s-cli`; `vocabulary:search --refresh`
fetches it again.

## Delete a vocabulary

`vocabulary:delete` takes the prefix or the id of a vocabulary. With `--ignore-not-found`, a vocabulary
that does not exist is not an error:

```bash
--8<-- "docs/examples/vocabularies.sh:delete"
```

!!! warning
    Deleting a vocabulary also deletes its classes and properties, and with them every value that uses
    one of its properties. The vocabularies that come with Omeka S cannot be deleted.

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/vocabulary-list.md"

--8<-- "build/docs/commands/vocabulary-import.md"

--8<-- "build/docs/commands/vocabulary-create-import-config.md"

--8<-- "build/docs/commands/vocabulary-search.md"

--8<-- "build/docs/commands/vocabulary-import-from-repo.md"

--8<-- "build/docs/commands/vocabulary-delete.md"
