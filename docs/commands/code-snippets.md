# Code snippets

The `code-snippet:*` commands manage the custom PHP snippets of the
[CodeSnippets](https://github.com/ateeducacion/omeka-s-CodeSnippets) module. You export snippets to a
JSON file and import that file into another installation.

Snippets are identified by their numeric ID, since names are not unique. Stored snippets do not run
while omeka-s-cli runs.

## Install the CodeSnippets module

The commands need the CodeSnippets module (1.0.1 or newer) to be installed and active; without it they
fail.

```bash
--8<-- "docs/examples/code-snippets.sh:module"
```

See [modules](modules.md) for more about installing modules.

## Import snippets

`code-snippet:import` reads a file (or a URL) in the module's portable export format:

```json
--8<-- "docs/examples/files/code-snippets/snippets.json"
```

```bash
--8<-- "docs/examples/code-snippets.sh:import"
```

An import always creates new snippets. It is atomic: if one snippet fails (for example an active
snippet with invalid PHP), none is created.

## List and show snippets

```bash
--8<-- "docs/examples/code-snippets.sh:list"
```

`--json` and `--csv` give the list in machine-readable form. `code-snippet:show` prints one snippet,
including its code:

```bash
--8<-- "docs/examples/code-snippets.sh:show"
```

## Activate or deactivate a snippet

Activating a snippet checks its PHP syntax first; a snippet with invalid code is not activated.

```bash
--8<-- "docs/examples/code-snippets.sh:activate"
```

## Export snippets

`code-snippet:export` prints all snippets as JSON, or only the one whose ID you give. `--output`
writes to a file. The output is the import format:

```bash
--8<-- "docs/examples/code-snippets.sh:export"
```

## Delete a snippet

`code-snippet:delete` does not ask for confirmation.

```bash
--8<-- "docs/examples/code-snippets.sh:delete"
```

A snippet that does not exist is an error, unless you pass `--ignore-not-found`; then the command only
notes it and exits with 0:

```bash
--8<-- "docs/examples/code-snippets.sh:delete-ignore"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/code-snippet-list.md"

--8<-- "build/docs/commands/code-snippet-show.md"

--8<-- "build/docs/commands/code-snippet-import.md"

--8<-- "build/docs/commands/code-snippet-export.md"

--8<-- "build/docs/commands/code-snippet-activate.md"

--8<-- "build/docs/commands/code-snippet-deactivate.md"

--8<-- "build/docs/commands/code-snippet-delete.md"
