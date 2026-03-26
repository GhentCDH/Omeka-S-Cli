# Dummy resource generator

## Commands

| Command | Argument | Option | Description |
|---|---|---|---|
| `dummy:create-items` | `<total>` (int) | `--config <file>` | Create dummy items |
| `dummy:create-item-sets` | `<total>` (int) | `--config <file>` | Create dummy item sets |

If `--config` is omitted, a built-in default config is used (dcterms:title + dcterms:description).

## Config file

A JSON object where each key is a property term (`dcterms:title`, `o:item_set`, etc.) and the value is a generator config.

- **Value properties** (`dcterms:*`, `foaf:*`): use an **array** of generator configs — supports generating multiple values per property.
- **Metadata properties** (`o:*`): use a **single** generator config object.
- An entry without a `"generator"` key is treated as fixed data and passed through as-is.

**Example**

```json
{
  "o:is_public":      { "generator": "boolean" },
  "o:resource_class": { "generator": "resource_class", "values": ["dctype:Text"] },
  "o:item_set":       { "generator": "item_set", "min": 1, "max": 3 },
  "dcterms:title":    [{ "generator": "literal", "mode": "words", "min": 2, "max": 5 }],
  "dcterms:relation": [{ "generator": "uri" }]
}
```

See [`examples/dummy/item.json`](../examples/dummy/item.json) for a full working example.

## Generators

### Value generators

Omeka S comes with several commonly-used data types. We provide generators for each of the built-in types.

| Generator | Description                                                              |
|---|--------------------------------------------------------------------------|
| `literal` | Text or numeric value. Controlled by a `mode` option (default: `words`). |
| `uri` | URI with optional label.                                                 |
| `resource` | Reference to another item or item set.                                   |

### Metadata generators

Used for Omeka object properties. Currently we only support an `item_set` and a `resource_class` generator. The `boolean` generator is more of a general-purpose generator.

| Generator | Property | Description |
|---|---|---|
| `boolean` | `o:is_public` | Randomly true or false. No options. |
| `item_set` | `o:item_set` | Assigns random item sets. |
| `resource_class` | `o:resource_class` | Assigns a random resource class from a list. |

---

## Literal generator

Default mode (when `"mode"` is omitted): `words`.

All modes accept an optional `locale` option (string, default `"en_US"`) to change the language/region of generated values.

### Fixed values or ranges

| Mode | Description | Options |
|---|---|---|
| `values` | Picks a random value from a fixed list. | `values` (string[], required) |
| `range` | Random integer between min and max (inclusive). | `min` (int, required)<br>`max` (int, required) |

### Text

| Mode | Description | Options |
|---|---|---|
| `words` | Random lowercase words joined with spaces. | `min` (int, default 3)<br>`max` (int, default 5) |
| `sentences` | Random sentences joined with spaces. | `min` (int, default 2)<br>`max` (int, default 4) |
| `paragraphs` | Random paragraphs joined with newlines. | `min` (int, default 1)<br>`max` (int, default 3) |
| `text` | Random text up to a character limit. | `maxNbChars` (int, default 200) |
| `realText` | Realistic text derived from real sources, up to a character limit. | `maxNbChars` (int, default 200) |

### Person

| Mode | Description | Options |
|---|---|---|
| `name` | Full name (first + last). | `gender` (string, optional): `"male"` or `"female"` |
| `firstName` | First name only. | `gender` (string, optional) |
| `lastName` | Last name only. | `gender` (string, optional) |
| `title` | Honorific title (e.g. Dr., Mr., Ms.). | `gender` (string, optional) |

### Address

| Mode | Description | Options |
|---|---|---|
| `city` | City name. | — |
| `country` | Country name. | — |
| `state` | State or province name. | — |
| `address` | Full multi-line address. | — |
| `streetAddress` | Street address line only. | — |
| `postcode` | Postal/zip code. | — |
| `longitude` | Decimal longitude coordinate. | — |
| `latitude` | Decimal latitude coordinate. | — |

### Date / Time

| Mode | Description | Options |
|---|---|---|
| `date` | Random date within a year range. | `min` (int, required) — minimum year<br>`max` (int, required) — maximum year<br>`format` (string, default `"Y"`) — `"Y"`, `"Y-m"`, or `"Y-m-d"` |
| `time` | Random time string. | `format` (string, default `"H:i:s"`) — any PHP time format |
| `year` | Random year as a string. | `min` (int, default 1900)<br>`max` (int, default current year) |
| `century` | Roman numeral century (e.g. XIX, XXI). | — |

### Internet

| Mode | Description | Options |
|---|---|---|
| `email` | Safe email address (example.com domain). | — |
| `url` | Random URL. | — |
| `slug` | Hyphenated URL slug. | — |

### Misc

| Mode | Description | Options |
|---|---|---|
| `uuid` | UUID v4 string. | — |
| `md5` | MD5 hash string. | — |
| `languageCode` | ISO 639-1 language code (e.g. `en`, `fr`). | — |
| `semver` | Semantic version string (e.g. `1.4.2`). | — |

---

## uri generator

Without `values`: generates a random `https://example.com/{slug}` URI with a random label.

| Option | Type | Description |
|---|---|---|
| `values` | object[], optional | List of predefined URIs to pick from randomly. Each entry: `id` (string, required), `label` (string, optional). |

## resource generator

| Option | Type | Description |
|---|---|---|
| `resourceType` | string, default `"any"` | Which resources to link to: `"items"`, `"item_sets"`, or `"any"`. |
| `values` | int[], optional | Explicit list of resource IDs. If omitted, uses all existing resources of the given type. |

## tem_set generator

| Option | Type | Description |
|---|---|---|
| `min` | int | Minimum number of item sets to assign. |
| `max` | int | Maximum number of item sets to assign. |
| `values` | int[], optional | Explicit list of item set IDs to pick from. If omitted, uses all existing item sets. |

## resource_class generator

| Option | Type | Description |
|---|---|---|
| `values` | string[], required | Resource class terms to pick from, e.g. `["dctype:Text", "dctype:Image", "bibo:Book"]`. |
