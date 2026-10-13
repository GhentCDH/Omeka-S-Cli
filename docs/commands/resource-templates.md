# Resource templates

The `resource-template:*` commands list, import, export and delete resource templates. The export is the
same JSON file as the *Export* button in the Omeka S admin interface, so templates can move between
installations or be kept in version control.

## Install the Common module

`resource-template:import` uses the [Common](https://github.com/Daniel-KM/Omeka-S-module-Common) module to
match the vocabularies, properties and data types of a template with those of the installation. Install
it first:

```bash
--8<-- "docs/examples/resource-templates.sh:common"
```

The other `resource-template:*` commands work without it. See [Modules](modules.md) for more on
`module:download`.

## List the resource templates

```bash
--8<-- "docs/examples/resource-templates.sh:list"
```

```text
+----+---------------+-------+---------------+-----------+-------+
| Id | Label         | Class | PropertyCount | ItemCount | Owner |
+----+---------------+-------+---------------+-----------+-------+
| 1  | Base Resource | N/A   | 20            | 0         | N/A   |
+----+---------------+-------+---------------+-----------+-------+
```

`--json` and `--csv` give the same data for scripts:

```bash
--8<-- "docs/examples/resource-templates.sh:list-json"
```

## Import a resource template

`resource-template:import` creates a resource template from an exported JSON file (a path or a URL):

```bash
--8<-- "docs/examples/resource-templates.sh:import"
```

??? example "`~/templates/letter.json`"

    ```json
    --8<-- "docs/examples/files/resource-templates/letter.json"
    ```

The template gets the label in the file, unless you give another one with `--label`. That way, one file
can create several templates:

```bash
--8<-- "docs/examples/resource-templates.sh:import-label"
```

Labels are unique: if a template with the label already exists, the command changes nothing, prints a
notice and exits with code 0.

## Update a resource template

Add `--update` to replace the template that has the same label with the content of the file:

```bash
--8<-- "docs/examples/resource-templates.sh:update"
```

To update a template with another label, give its id or label after the file. The template is updated
and gets the label of the file, or the one from `--label`:

```bash
--8<-- "docs/examples/resource-templates.sh:update-identifier"
```

!!! warning
    When you update a template by id or label, the label in the file replaces the template's label.
    If another template already has that label, the update fails. Pass `--label` with the template's
    current label to keep it.

## Handle missing dependencies

A template refers to its properties and resource class by vocabulary namespace URI and local name, and to
its data types by name. Before importing, the command checks that all of them exist in the installation.
If something is missing, it lists what and imports nothing:

```bash
--8<-- "docs/examples/resource-templates.sh:missing-dependencies"
```

```text
The resource template has missing dependencies:
- Vocabularies: https://example.org/archive#
Could not import the resource template due to missing dependencies.
```

Missing data types usually come from a module that is not installed, such as Numeric Data Types
(`numeric:timestamp`) or Value Suggest (`valuesuggest:...`). Custom vocabulary data types are matched by
the label of the custom vocabulary (see [Custom vocabularies](custom-vocabularies.md)).

The best fix is to install what is missing and import again. Here the template uses the vocabulary from
the [Vocabularies](vocabularies.md#use-a-configuration-file) page:

```bash
--8<-- "docs/examples/resource-templates.sh:fix-dependencies"
```

`--ignore-deps` imports the template anyway, without the resource class, properties and data types that
do not exist:

```bash
--8<-- "docs/examples/resource-templates.sh:ignore-deps"
```

Here the template keeps only `dcterms:title` and `dcterms:date`; the class and the two properties from
the missing vocabulary are left out.

## Export a resource template

`resource-template:export` takes the id or the label of a template. Without a file name it prints the
JSON:

```bash
--8<-- "docs/examples/resource-templates.sh:export-stdout"
```

With a file name it writes the JSON to that file, overwriting it if it exists:

```bash
--8<-- "docs/examples/resource-templates.sh:export-file"
```

To back up all templates, combine it with `resource-template:list`:

```bash
--8<-- "docs/examples/resource-templates.sh:export-all"
```

## Delete a resource template

`resource-template:delete` takes the id or the label of a template. With `--ignore-not-found`, a template
that does not exist is not an error:

```bash
--8<-- "docs/examples/resource-templates.sh:delete"
```

A template that is used by items is not deleted unless you add `--force`. The items keep their values;
they no longer have a resource template.

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/resource-template-list.md"

--8<-- "build/docs/commands/resource-template-import.md"

--8<-- "build/docs/commands/resource-template-export.md"

--8<-- "build/docs/commands/resource-template-delete.md"
