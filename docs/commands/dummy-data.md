# Dummy data

The `dummy:*` commands fill an Omeka S installation with generated items and item sets, so you can
try out a theme, a module or the search on a realistic amount of content. A JSON config file
decides which properties each resource gets and how their values are generated (with
[Faker](https://fakerphp.org)); without one, every resource gets a title and a description.

!!! warning "Not for production"
    There is no command to remove the generated resources again. Use a test installation.

## Create items

`dummy:create-items` creates one item, or the number you give with `--number` (`-n`):

```bash
--8<-- "docs/examples/dummy-data.sh:items"
```

```text
Created 10 items.
```

Without a config file, each item gets a `dcterms:title` of 2 to 5 words and a `dcterms:description`
of 5 to 15 words, all lorem ipsum. The resources are created one at a time through the Omeka S API;
for more than 100 the command reports its progress every 100.

!!! note "Not in the full-text search"
    The resources are created without triggering the API events, which keeps generation fast but
    also means Omeka S does not add them to its full-text search index and modules that act on new
    resources do not see them. To make them searchable, check *Index full-text search* in the admin
    settings and save.

## Create item sets

`dummy:create-item-sets` works the same way, with the same default title and description:

```bash
--8<-- "docs/examples/dummy-data.sh:item-sets"
```

## Use a config file

Pass a config file (a path or a URL) with `--config` (`-c`) to choose the properties and values:

```json
--8<-- "docs/examples/files/dummy/items.json"
```

```bash
--8<-- "docs/examples/dummy-data.sh:items-config"
```

Each item in this example gets a title, a Flemish-sounding creator name, a date between 1850 and
1950, two subjects (one from each list), a CC0 rights statement, a random resource class and a
random visibility. The properties must exist in the installation, so import their vocabulary first
(see [vocabularies](vocabularies.md)).

An item set config has the same format, except that it may not contain `o:item_set`:

```json
--8<-- "docs/examples/files/dummy/item-sets.json"
```

```bash
--8<-- "docs/examples/dummy-data.sh:item-sets-config"
```

`"o:is_public": false` has no `generator`: a value like that is passed to Omeka S as is, so every
item set in this example is private.

## Add items to item sets

The `item_set` generator puts each item in a random selection of the existing item sets, and the
`resource` generator links to existing resources. Create the item sets first:

```json
--8<-- "docs/examples/files/dummy/populate-item-sets.json"
```

```bash
--8<-- "docs/examples/dummy-data.sh:populate"
```

Each item lands in one or two item sets, and its `dcterms:relation` points to a random existing
item. The command fails if there are fewer item sets than `min`. Both generators choose from the
resources that exist when the command starts, so items never link to items created in the same run.

## Config file reference

The config file is a JSON object. Each key is either a property term (`dcterms:title`) or an Omeka
S field of the resource (`o:is_public`, `o:resource_class`, `o:item_set`).

- A **property** takes a list of generator configs. Each one adds one value, so a list of two
  generators gives the property two values. Generated values are always public.
- An **`o:` field** takes a single generator config.
- Every generator config is an object with a `generator` key and the options of that generator.
- An `o:` field without a `generator` key is passed to Omeka S unchanged, for example
  `"o:is_public": false` or `"o:resource_template": {"o:id": 1}`. For a property, this does not
  work: to give every resource the same value, use the `literal` generator in `values` mode with a
  single value.

| Generator | Use on | Generates |
| --- | --- | --- |
| `literal` | properties | A text value; the `mode` option decides what kind. |
| `uri` | properties | A URI with a label. |
| `resource` | properties | A link to an existing item or item set. |
| `boolean` | `o:` fields | `true` or `false` at random, for example for `o:is_public`. No options. |
| `resource_class` | `o:resource_class` | A resource class picked at random from a list. |
| `item_set` | `o:item_set` (items only) | A random selection of existing item sets. |

### `literal`

`mode` selects what to generate (default `words`). Every mode accepts `locale`, a Faker locale
such as `nl_BE` or `fr_FR` (default `en_US`), which affects names, addresses and the like.

| Mode | Generates | Options |
| --- | --- | --- |
| `values` | A value picked at random from a list. | `values` (list of strings, required) |
| `range` | An integer between `min` and `max`, inclusive. | `min`, `max` (integers, required) |
| `words` | Lorem ipsum words, separated by spaces. | `min` (default 3), `max` (default 5): the number of words |
| `sentences` | Lorem ipsum sentences. | `min` (default 2), `max` (default 4) |
| `paragraphs` | Lorem ipsum paragraphs, separated by newlines. | `min` (default 1), `max` (default 3) |
| `text` | Lorem ipsum text. | `maxNbChars` (default 200) |
| `realText` | Text from a real book instead of lorem ipsum. | `maxNbChars` (default 200) |
| `name` | A full name. | `gender` (`male` or `female`, optional) |
| `firstName` | A first name. | `gender` (optional) |
| `lastName` | A last name. | `gender` (optional) |
| `title` | A title such as `Dr.` or `Ms.` | `gender` (optional) |
| `address` | A full address, over two lines. | |
| `streetAddress` | A street address. | |
| `postcode` | A postal code. | |
| `city` | A city name. | |
| `state` | A state or province. | |
| `country` | A country name. | |
| `latitude` | A latitude in decimal degrees. | |
| `longitude` | A longitude in decimal degrees. | |
| `date` | A date in a year range. | `min`, `max` (years, required); `format`: `Y` (default), `Y-m` or `Y-m-d` |
| `year` | A year. | `min` (default 1900), `max` (default the current year) |
| `time` | A time of day. | `format` (a PHP date format, default `H:i:s`) |
| `century` | A century in Roman numerals, such as `XIX`. | |
| `email` | An email address at `example.com`, `example.org` or `example.net`. | |
| `url` | A URL. | |
| `slug` | A slug of lowercase words joined by hyphens. | |
| `uuid` | A UUID. | |
| `md5` | An MD5 hash. | |
| `languageCode` | A two-letter language code, such as `fr`. | |
| `semver` | A version number, such as `2.4.1`. | |

### `uri`

| Option | Description |
| --- | --- |
| `values` | Optional list of URIs to pick from at random. Each entry is an object with `id` (the URI, required) and `label` (optional). |

Without `values`, it generates `https://example.com/<slug>` with the words of the slug as label.

### `resource`

| Option | Description |
| --- | --- |
| `resourceType` | `items`, `item_sets` or `any` (default). |
| `values` | Optional list of resource IDs to pick from. Without it, any existing resource of `resourceType`. With `values`, set `resourceType` to `items` or `item_sets`. |

The command fails if no resource of that type exists.

### `item_set`

| Option | Description |
| --- | --- |
| `min` | Minimum number of item sets per item (integer, required, at least 1). |
| `max` | Maximum number of item sets per item (integer, required). |
| `values` | Optional list of item set IDs to pick from. Without it, all existing item sets. |

### `resource_class`

| Option | Description |
| --- | --- |
| `values` | Resource class terms to pick from, such as `["dctype:Text", "bibo:Book"]` (required). The vocabulary of each class must be installed. |

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/dummy-create-items.md"

--8<-- "build/docs/commands/dummy-create-item-sets.md"
