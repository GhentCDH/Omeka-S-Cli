# Development

How to run omeka-s-cli from source, build the PHAR, run the tests and work on this documentation.
The source is on [GitHub](https://github.com/GhentCDH/Omeka-S-Cli).

## Run from source

```bash
git clone https://github.com/GhentCDH/Omeka-S-Cli.git
cd Omeka-S-Cli
composer install
php bin/omeka-s-cli --help
```

## Dev container

`compose.yaml` defines a PHP + Apache container (`omeka-s-cli-app-1`, the repository mounted at
`/app/omeka-s-cli`, an Omeka S at `/var/www/omeka-s`) and a MariaDB container (`db`, user and
password `omeka`, root password `root`):

```bash
docker compose up -d --build
docker exec -w /var/www/omeka-s omeka-s-cli-app-1 php /app/omeka-s-cli/bin/omeka-s-cli core:status
```

## Build the PHAR

The PHAR is built with [Box](https://github.com/box-project/box). `composer install` installs Box in
an isolated `vendor-bin/box` tree, because its dependencies conflict with those of Omeka S.

```bash
composer build
```

This downloads the blueprint schema (bundled as the offline fallback) and writes
`bin/omeka-s-cli.phar`. All libraries except Omeka S and Laminas are prefixed in the PHAR (see
`scoper.inc.php`), so always test the PHAR before a release, not only the source.

## Tests

| Suite | Command | Notes |
| --- | --- | --- |
| Unit tests | `vendor/bin/phpunit` | runs against the source |
| Integration tests | `tests/integration.sh --phar` | in the dev container; wipes `/var/www/omeka-s` |
| Documentation examples | `tests/docs-examples.sh` | in the dev container; wipes `/var/www/omeka-s` |

```bash
composer build
docker exec omeka-s-cli-app-1 /app/omeka-s-cli/tests/integration.sh --phar
docker exec omeka-s-cli-app-1 /app/omeka-s-cli/tests/docs-examples.sh
```

## Documentation

This site is built with [Zensical](https://zensical.org) from the `docs/` directory and published to
GitHub Pages by the `docs` workflow on every push to `main`.

- **Every command on the site is tested.** The examples are bash scripts in `docs/examples/`; the
  pages embed regions of them (`--8<-- "docs/examples/modules.sh:download"`), and
  `tests/docs-examples.sh` runs the scripts against a real Omeka S. The script refuses pages with
  commands typed directly in a code block.
- **The command reference is generated** from the command definitions by
  `scripts/generate-command-reference.php` (`composer docs:reference`), into `build/docs/`. It fails
  when a command is not documented on any page.

Preview the site while you edit (needs [uv](https://docs.astral.sh/uv/)), or build it like CI does:

```bash
composer docs:serve
composer docs:build
```

Without PHP on the host, generate the reference in the dev container and run Zensical on the host:

```bash
docker exec -w /app/omeka-s-cli omeka-s-cli-app-1 composer docs:reference
uvx --with-requirements requirements-docs.txt zensical serve
```

The conventions for writing pages and examples are in `AGENTS.md`, section *Documentation*.
