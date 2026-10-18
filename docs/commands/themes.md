# Themes

The `theme:*` commands find themes in the omeka.org catalog, download them into the `themes/` directory
of an Omeka S installation, and list, check and delete them. A theme needs no installation step: once it
is in `themes/`, you can assign it to a site.

## Find a theme

`theme:search` lists the themes in the [omeka.org theme directory](https://omeka.org/s/themes/), or
those whose id, name, owner or tags contain the query:

```bash
--8<-- "docs/examples/themes.sh:search"
```

`--extended` adds the URL, owner and catalog of each theme:

```bash
--8<-- "docs/examples/themes.sh:search-query"
```

```text
+---------+---------------+------------------------+-------------------------------------------+----------------+------------+
| Id      | LatestVersion | OmekaVersionConstraint | Url                                       | Owner          | Repository |
+---------+---------------+------------------------+-------------------------------------------+----------------+------------+
| freedom | 1.1.0         | ^4.2.0                 | https://github.com/omeka-s-themes/freedom | omeka-s-themes | Omeka.org  |
+---------+---------------+------------------------+-------------------------------------------+----------------+------------+
```

As with modules, a search inside an Omeka S installation hides the themes that have no release for the
installed Omeka S version (`--include-incompatible` shows them), and the catalog is cached for 24 hours
(`--refresh` fetches it again).

## Download a theme

`theme:download` takes the same kind of URI as [`module:download`](modules.md#download-from-a-zip-file-or-a-git-repository):
a catalog id, optionally with a version, a release zip (URL or file), or a git repository.

```bash
--8<-- "docs/examples/themes.sh:download"
```

Without a version, it picks the most recent release that supports the installed Omeka S version. Add a
version to pin a release; a pinned release that does not support your Omeka S version is refused unless
you add `--force`. A theme that is already there gives a warning and exit code 0; `--force` replaces it:

```bash
--8<-- "docs/examples/themes.sh:download-version"
```

From a git repository, at a tag, branch or commit, or from a release zip:

```bash
--8<-- "docs/examples/themes.sh:download-git"
```

```bash
--8<-- "docs/examples/themes.sh:download-zip"
```

!!! warning "Directory name"
    A theme downloaded by catalog id goes in a directory named after that id (`themes/cozy`). A theme
    downloaded from a zip or git repository goes in a directory named after the `name` in its
    `theme.ini` (`themes/Cozy`). The directory name is the theme id Omeka S stores for a site, so pick
    one way of downloading a theme and stick to it: mixing them leaves two copies of the same theme.

## Choose the theme folder

The folder is the theme's ID in Omeka S; sites refer to it. A theme from the catalog keeps the
catalog's folder. A ZIP or git theme gets its `theme.ini` name, unless you pick the folder with
`--dir`:

```bash
--8<-- "docs/examples/themes.sh:download-dir"
```

## Use a theme

Choose the theme of a site when you create it, or change it later with
[`site:update`](sites.md):

```bash
--8<-- "docs/examples/themes.sh:use"
```

## List themes and check their status

`theme:list` shows every theme in the `themes/` directory, with its version and the latest version in the
catalog:

```bash
--8<-- "docs/examples/themes.sh:list"
```

```text
+---------+---------+--------+---------+---------------+-----------------+------------+
| Id      | Name    | State  | Version | LatestVersion | UpdateAvailable | Author     |
+---------+---------+--------+---------+---------------+-----------------+------------+
| freedom | Freedom | active | 1.0.7   | 1.1.0         | yes             | Omeka Team |
| Cozy    | Cozy    | active | 1.6.3   | 1.6.3         | no              | Omeka Team |
| default | Default | active | 1.9.3   | 1.9.3         | no              | Omeka Team |
+---------+---------+--------+---------+---------------+-----------------+------------+
```

The state is the one Omeka S reports: `active` means the theme is valid and can be used, not that a
site uses it. Other states, such as `invalid_ini` or `invalid_omeka_version`, point to a theme that
Omeka S cannot use. `--extended` adds the path of each theme, `--json` and `--csv` change the format.

`theme:status` shows the same row for one theme, and fails if it is not in `themes/`:

```bash
--8<-- "docs/examples/themes.sh:status"
```

## Update a theme

`theme:list --outdated` shows the themes for which the catalog has a newer release:

```bash
--8<-- "docs/examples/themes.sh:outdated"
```

There is no update command for themes: download the theme again with `--force`. Add `--backup` to move
the old copy to `~/.omeka-s-cli/backups/themes/<theme>/<date-time>/` instead of deleting it, which
keeps any changes you made to its files:

```bash
--8<-- "docs/examples/themes.sh:update"
```

```text
Selected 'freedom' v1.1.0 (compatible with Omeka S 4.2.1).
Download https://github.com/omeka-s-themes/freedom/releases/download/v1.1.0/freedom-v1.1.0.zip ... done
Theme 'freedom' v1.1.0 downloaded.
```

## Delete a theme

`theme:delete` removes a theme's directory. It refuses a theme that a site uses: with the site above,
`theme:delete freedom` fails with

```text
The theme is currently active on a site. Use the --force flag to proceed.
```

Give those sites another theme first. `--force` deletes the theme anyway, and leaves the sites with a
theme that no longer exists. With `--ignore-not-found`, deleting a theme that is not there prints a note
and exits 0:

```bash
--8<-- "docs/examples/themes.sh:delete"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/theme-search.md"

--8<-- "build/docs/commands/theme-download.md"

--8<-- "build/docs/commands/theme-list.md"

--8<-- "build/docs/commands/theme-status.md"

--8<-- "build/docs/commands/theme-delete.md"
