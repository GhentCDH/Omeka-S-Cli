# Sites

The `site:*` commands create, update and delete the public sites of an Omeka S installation, and
manage which users may work on them. They do not manage a site's pages, navigation or item pool; do
that in the admin interface.

A command that acts on an existing site takes its **ID or slug** (`1` or `collections`). A slug may
consist of digits only; such a value is tried as both. If it is the ID of one site and the slug of
another, the command stops with an error and asks you to name the site another way. Users are given by
**ID or email address**, as with the [`user:*` commands](users.md).

## Add a site

`site:add` takes the title of the site. Set the slug, the part of the URL after `/s/`, with
`--slug`; it may contain letters, digits, `_` and `-`.

```bash
--8<-- "docs/examples/sites.sh:add"
```

Without `--theme` the site uses the `default` theme that ships with Omeka S. Any other theme must
be downloaded first, with [`theme:download`](themes.md); an unknown theme is refused. `--default` makes
the new site the default site: the site that visitors see at the root URL of the installation.

By default a site is public, its owner is the first active global administrator, and new items are
added to it automatically. Each of these can be changed:

```bash
--8<-- "docs/examples/sites.sh:add-private"
```

`--private` hides the site from visitors; only its owner, users with a
[permission](#manage-who-can-work-on-a-site) on it and users with the `global_admin` or `site_admin` role can see it.
`--owner` makes another user the owner and gives that user the `admin` role on the site, instead of the
administrator. `--no-assign-new-items` stops newly created items from being added to the site.

## Add sites in a script that runs more than once

`site:add` refuses a site that already exists. With `--slug`, that is a site with the same slug.
Without it, Omeka S derives the slug from the title (`Exhibitions` becomes `exhibitions`), and a site
with the same title (ignoring case) counts as existing. `--ignore-existing` turns the error into a
warning with exit code 0:

```bash
--8<-- "docs/examples/sites.sh:ignore-existing"
```

The existing site is not changed. Use [`site:update`](#change-a-site) to bring it in line.

## List sites

```bash
--8<-- "docs/examples/sites.sh:list"
```

```text
Found 3 site(s).
+----+-------------+---------------------+---------+-----------+------------+----------------------+
| Id | Slug        | Title               | Theme   | Is Public | Is Default | Owner                |
+----+-------------+---------------------+---------+-----------+------------+----------------------+
| 1  | collections | Digital Collections | freedom | yes       | yes        | admin@example.org    |
| 2  | intranet    | Staff Intranet      | default | no        | no         | jane.doe@example.org |
| 3  | exhibitions | Exhibitions         | default | yes       | no         | admin@example.org    |
+----+-------------+---------------------+---------+-----------+------------+----------------------+
```

`--csv` and `--json` give the same data in a form other tools can read. For example, the slug of the
default site:

```bash
--8<-- "docs/examples/sites.sh:list-json"
```

## Change a site

`site:update` changes only what you pass: here the title and the visibility. The summary, theme,
owner, pages, navigation and permissions of the site stay as they are.

```bash
--8<-- "docs/examples/sites.sh:update"
```

The options are `--title`, `--slug`, `--summary` (an empty value clears it), `--theme`,
`--public`/`--private` and `--assign-new-items`/`--stop-assigning-new-items`. `--default` makes the site
the default site:

```bash
--8<-- "docs/examples/sites.sh:update-default"
```

Changing the slug changes the URLs of the site and all its pages; links to the old URLs break.
Without any option, `site:update` does nothing. Add `--json` to print the updated site.

## Manage who can work on a site

Besides their global role, users can have a role on a site: `viewer` (may see a private site),
`editor` (may also add, edit and delete its pages) or `admin` (may also change its settings, navigation and permissions).
`site:set-permission` gives a user a role on a site:

```bash
--8<-- "docs/examples/sites.sh:set-permission"
```

`site:list-permissions` shows who has which role:

```bash
--8<-- "docs/examples/sites.sh:list-permissions"
```

```text
Found 3 permission(s).
+---------+----------------------+--------------+--------+
| User Id | Email                | Display Name | Role   |
+---------+----------------------+--------------+--------+
| 1       | admin@example.org    | Admin        | admin  |
| 2       | jane.doe@example.org | Jane Doe     | editor |
| 3       | john.roe@example.org | John Roe     | viewer |
+---------+----------------------+--------------+--------+
```

For a user who already has a permission, `site:set-permission` changes the role. Giving a user the
role they already have only prints a warning, so the command is safe to re-run.

```bash
--8<-- "docs/examples/sites.sh:change-permission"
```

`site:delete-permission` takes a user's permission away:

```bash
--8<-- "docs/examples/sites.sh:delete-permission"
```

!!! note "One permission at a time"
    Omeka S stores the permissions of a site as one list, and its API replaces the whole list on
    every write. The `site:*-permission` commands read the current list, change the entry of the one
    user, and write the list back, so the permissions of other users are kept.

## Delete a site

`site:delete` deletes a site with its pages. The items and item sets in it are kept. If it was the
default site, the installation no longer has one.

```bash
--8<-- "docs/examples/sites.sh:delete"
```

Deleting a site that does not exist is an error; with `--ignore-not-found` the command prints a note and
exits with 0. `site:delete-permission` and `site:update` accept the same option.

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/site-add.md"

--8<-- "build/docs/commands/site-list.md"

--8<-- "build/docs/commands/site-update.md"

--8<-- "build/docs/commands/site-delete.md"

--8<-- "build/docs/commands/site-set-permission.md"

--8<-- "build/docs/commands/site-list-permissions.md"

--8<-- "build/docs/commands/site-delete-permission.md"
