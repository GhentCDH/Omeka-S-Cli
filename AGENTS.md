# AGENTS.md

## Purpose
- `Omeka-S-Cli` is a PHP CLI for operating Omeka S instances and managing module/theme assets.
- Main entrypoint is `bin/omeka-s-cli`; commands are aggregated in `src/Commands/Index.php`.

## Big-Picture Architecture
- Command layer: each domain has `src/Commands/<Domain>/Index.php` that returns instantiated command classes.
- Shared command behavior is centralized in `src/Commands/AbstractCommand.php` (global options, output formatting, Omeka path detection/bootstrap).
- Omeka bridge is `src/Omeka/*`: `OmekaInstance` bootstraps Omeka runtime, then `ModuleApi` / `ThemeApi` / `SiteApi` wrap Omeka services (`SiteApi` encodes the site pitfalls: partial updates, whole-list permission writes, `default_site`).
- Remote metadata layer is `src/Manager/*/Manager.php` + `src/Repository/**` (official `omeka.org` + Daniel-KM CSV for modules).
- Download layer is `src/Downloader/GitDownloader.php` and `src/Downloader/ZipDownloader.php`. `ZipDownloader` streams to disk through `ResourceFetcher::download()`; `ZipDownloader::extract()` is also used by `blueprint:bundle`.
- Repository results are cached via `src/Cache.php` into `$HOME/.cache/omeka-s-cli` using `src/Cache/FileCache.php`.
- Blueprint layer (declarative deploy): `blueprint:validate|deploy|export` in `src/Commands/Blueprint/`; core in `src/Blueprint/`: `BlueprintLoader` -> `BlueprintValidator` (opis/json-schema against the fetched schema) -> `BlueprintApplier`. The applier orchestrates existing commands (`module:download`, `theme:download`, `user:add`, `config:set`, ...) by name rather than calling Omeka services directly. `blueprint:bundle` (`src/Blueprint/Bundle/`) downloads every source into one directory and rewrites the blueprint with relative sources. `--self-contained` on validate/deploy enforces that. Where sources live is the `SourceFields` registry, shared by the loader, the check and the bundler; the Omeka S release ZIP is read from `x-omeka-s-cli.install.source` (`SourceFields::CORE_SOURCE`) until the shared format defines one. Add-on references resolve through `src/Downloader/AddonResolver.php`, which is shared with `module:download`/`theme:download`. `src/Helper/ModuleNamespace.php` reads the namespace a `Module.php` declares (shared by `blueprint:bundle` and `module:download`). Content phases `itemSets` then `items` (after `sites`) import the shared format's simple entries (mapped onto portable JSON-LD by `SimpleContentMapper`) and the `x-omeka-s-cli.itemSets|items` JSON-LD entries through `item-set:import`/`item:import --config`; `SourceFields` knows lists inside an object (dotted paths) and nested media fields (`media[].source`, `items[].media[].url`). The bundler unpacks each module/theme release ZIP to check it, using deploy's own folder search (`Path::findSubpath`), then copies the original ZIP unchanged; git sources are refused.
- Items and item sets (`item:*`, `item-set:*`, `media:*`): the core in `src/Resource/` is pure and talks to the instance only through the `Catalog` interface (`PayloadBuilder` imports portable or raw JSON-LD with its checks and `o-ref:ref`/`o-ref:id` references, `Exporter` writes the portable format, `QueryBuilder` turns filters into a search query); `OmekaCatalog`, `ResourceImporter` (duplicate lookup, one create with media) and `LocalFileIngester` (replaces the `upload` ingester in the CLI process, see `MediaIngesters`) are the Omeka side. Commands live in `src/Commands/Resource/` (one class per action, registered once per `ResourceType`) and `src/Commands/Media/`; `ItemImportConfig`/`MediaConfig` are the import entry shape shared with the blueprint.
- Settings export/import (`config:get|set|list|export|import`) is backed by `src/Settings/` (`SettingsExport`/`SettingsImport`, `SettingsScope`, `SettingType`, `SettingsSerializer`).
- Other command domains: `Vocabulary`, `CustomVocabulary`, `ResourceTemplates`, `Dummy` (Faker-based item generation), `User`, `Site`, `Config`, `Core`, `Cli`.

## Important Data Flows
- Module download/update (`src/Commands/Module/DownloadCommand.php`): parse user input with `src/Helper/ResourceUriParser.php` -> resolve candidate versions via manager/repositories -> filter by Omeka compatibility (`src/Helper/VersionCompatibility.php`) -> download/unpack -> install into Omeka `modules/`.
- Omeka-bound commands: locate Omeka base path automatically (or `--base-path`) and call `OmekaInstanceFactory::createInstance(...)`.

## Project Conventions To Follow
- New commands should extend `src/Commands/AbstractCommand.php` (or a domain abstract such as `src/Commands/Module/AbstractModuleCommand.php`).
- Always register new commands in the domain `Index.php`; unregistered commands are invisible to the CLI.
- Prefer built-in output options (`optionJson`, `optionTable`, `optionCSV`, `optionEnv`) plus `outputFormatted()`.
- Non-fatal situations should use `WarningException` (handled in `src/Cli/Application.php` as warning + exit code 0).
- Output rule: errors always print (even under `--quiet`); `--quiet`/`--json` silence info and warnings, including benign `WarningException` notices. Exit code is the contract. A command that must skip a missing resource under `--ignore-not-found` throws `IgnoredNotFoundException` (a silent marker; the note is emitted verbosity-aware beforehand) so the call site needs no null check.
- Reuse existing commands for orchestration (example: `module:update` invokes `module:download` and `module:upgrade`).
- When a command takes a structured set of fields (CLI options, a JSON config file, and/or a blueprint section), model it as a config value object in `src/Helper/<Name>Config.php` instead of passing loose arrays. Examples: `VocabularyConfig`, `UserConfig`, `DatabaseConfig`. The pattern:
  - A private constructor plus named factories (`fromArray()`, `fromValues()`, `fromOmekaPath()`), or a public constructor for trivial cases (`UserConfig`). Validation lives there and throws `InvalidArgumentException` with a user-facing message.
  - Pure value object, no IO: callers resolve relative paths and fetch sources first (`DatabaseConfig::fromOmekaPath()` is the deliberate exception, since it reads `database.ini`).
  - Output methods for each consumer, e.g. `toImporterOptions()` (the Omeka API shape), `toArray()` (the canonical config shape, null/default-filtered, used for export and `create-import-config`), `getDsn()`, `writeIniFile()`.
  - One source of truth for every entry point, so field names, defaults and deprecated aliases stay consistent: `VocabularyConfig` backs all `vocabulary:*` commands (via `VocabularyImporterTrait`), which the blueprint path invokes; `DatabaseConfig` is shared by `config:create-db-ini` and `blueprint:deploy`; `UserConfig` carries the admin account for `blueprint:deploy`'s core install.

## External Integrations
- Omeka version API: `https://api.omeka.org/latest-version-s`.
- Official module catalog: `https://omeka.org/add-ons/json/s_module.json`.
- Official theme catalog: `https://omeka.org/add-ons/json/s_theme.json`.
- Daniel-KM module catalog CSV: `https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv`.

## Developer Workflows
- Install deps: `composer install`
- Run CLI: `php bin/omeka-s-cli --help`
- Lint/fix: `composer lint` / `composer fix`
- Build PHAR: `composer build` (fetches the blueprint schema, then `box compile`; configured by `box.json` + `scoper.inc.php`). Box lives in `vendor-bin/box` (bamarni/composer-bin-plugin, installed by `composer install`) since its deps conflict with Omeka's, so a local `composer install --no-dev` removes it (Box excludes dev packages from the PHAR anyway). CI installs `--no-dev` and gets the latest Box from `setup-php`.
- Optional container dev setup is defined in `compose.yaml` and `Dockerfile`.
- Dev container: compose services `app` (container `omeka-s-cli-app-1`, repo mounted at `/app/omeka-s-cli`, Omeka at `/var/www/omeka-s`) and `db` (MariaDB). Run the CLI there with `docker exec -u 1000:1000 -w /var/www/omeka-s omeka-s-cli-app-1 php /app/omeka-s-cli/bin/omeka-s-cli <cmd>`. Always pass `-u 1000:1000` (user `application`): `docker exec` defaults to root, which leaves root-owned files in the mounted repo (e.g. `build/`) and in `/var/www/omeka-s`; fix those with `docker exec omeka-s-cli-app-1 chown -R 1000:1000 /app/omeka-s-cli/build /var/www/omeka-s`.
- Design notes and roadmaps for in-progress features live in `plans/*.md` (not published). `docs/` is the documentation site (see Documentation).

## Testing
- Unit tests: `vendor/bin/phpunit` (config `phpunit.xml`, tests under `tests/`). These run against unscoped source.
- One file / one test: `vendor/bin/phpunit tests/Helper/SlugTest.php`, `vendor/bin/phpunit --filter testMethodName`. If the host has no PHP, run inside the container: `docker exec -u 1000:1000 -w /app/omeka-s-cli omeka-s-cli-app-1 vendor/bin/phpunit ...`.
- `phpunit.xml` is strict (`requireCoverageMetadata`, `failOnRisky`, `failOnWarning`, `beStrictAboutOutputDuringTests`): every test class needs `#[CoversClass(...)]`, and any stray output fails the run. Test namespace is `Tests\<Dir>` (e.g. `Tests\Blueprint`).
- `tests/bootstrap.php` downloads the blueprint JSON schema on first run (needs network once per checkout; the schema is not committed).
- Integration tests: `tests/integration.sh` drives the CLI against a live Omeka in the dev container; select subsets with `--section <name>` (and `--skip <name>`). Sections (case-insensitive): Setup, Core, Modules, Themes, Users, Sites, Vocabularies, "Custom vocabularies", "Resource templates", Items, Configuration, "Dummy data", Blueprints.
- IMPORTANT: run integration tests against a freshly built PHAR — `box compile`, then `tests/integration.sh --phar`. Scoping (see Packaging Notes) only takes effect in the PHAR, so a source-only run can pass while the shipped PHAR fails.

## Packaging Notes
- PHAR scoping excludes `OSC`, `Omeka`, and `Laminas` namespaces (`scoper.inc.php`, prefix `_OmekaSCli`) because Omeka provides these at runtime. Every other vendored library (e.g. `Doctrine`) IS prefixed.
- Consequence: an `OSC\` class must not type-hint an Omeka-supplied object from a prefixed namespace. The `Omeka\Connection` service is a `Doctrine\DBAL\Connection`; in the PHAR the hint becomes `_OmekaSCli\Doctrine\DBAL\Connection` and rejects Omeka's unprefixed runtime instance (TypeError). Leave such properties/params untyped, as the commands do (e.g. `$connection = $serviceManager->get('Omeka\Connection')`). This only surfaces in a `--phar` run.
- `composer.json` lint/fix scripts ignore several command index files; do not use those files as strict style references.

## Source Of Existing AI Conventions
- One required convention glob search matched only `README.md`; no existing repo AI policy files were found.

## Documentation
- The documentation site (GitHub Pages) is built with [Zensical](https://zensical.org) from `docs/` (config `zensical.toml`, version pinned in `requirements-docs.txt`), published by `.github/workflows/docs.yml`. Design notes and plans live in `plans/`, not `docs/`.
- Pages: `docs/installation.md`, `docs/usage.md`, one page per command domain in `docs/commands/<domain>.md`, install automation in `docs/automation/`.
- Every command shown on a page is tested: examples live in runnable bash scripts in `docs/examples/` (`<domain>.sh`), and pages embed regions of them with snippet includes, never typed commands.
  - Mark a region in the script with `# --8<-- [start:name]` / `# --8<-- [end:name]`; embed it in a ```` ```bash ```` block as `--8<-- "docs/examples/modules.sh:name"`.
  - Lines outside the regions are hidden setup and checks (`jq -e`, `grep -q`, `test`); a script stops at the first failing command (`set -euo pipefail`). Expected failures: `! omeka-s-cli ... || exit 1` (`set -e` ignores a command negated with `!`, so the `|| exit 1` is what fails the script).
  - Scripts call `omeka-s-cli` (the runner puts the binary under test on the PATH), start in the Omeka S directory, and may use `$SCRATCH_DIR` (a private temp dir), `$REPO_DIR`, `$DB_PASSWORD`, `$ADMIN_PASSWORD`. Each must pass on its own on a freshly installed instance: set up prerequisites (modules, users) idempotently in hidden lines.
  - Sample output goes in ```` ```text ```` blocks (illustrative, not asserted). A ```` ```bash ```` block containing a typed `omeka-s-cli` command fails the lint.
  - Data files a page shows (blueprints, JSON configs) are included from files that a script uses, so they are tested too.
- The command reference is generated from the command definitions: `composer docs:reference` (`scripts/generate-command-reference.php`) writes `build/docs/commands/<name>.md` (`:` → `-`) and `build/docs/global-options.md`. Each domain page ends with `## Reference` including the fragments of its commands; the script fails if a registered command is not included by any page. So a new command needs: its reference include on a page, a section with a tested example, and (for a new script) an entry in `SCRIPTS` in `tests/docs-examples.sh`.
- Run the examples in the dev container against a fresh PHAR: `box compile`, then `docker exec -u 1000:1000 omeka-s-cli-app-1 /app/omeka-s-cli/tests/docs-examples.sh` (wipes `/var/www/omeka-s`). `--script <name>` runs one script, `--instance <name>` uses a separate instance (`/var/www/omeka-s-<name>`, database `omeka_<name>`), `--lint-only` only checks the pages, `-v` traces.
- Preview: `composer docs:serve` (needs `uv`); build: `composer docs:build` (strict, like CI).
- Audience: sysadmins comfortable on the Linux command line. Task-oriented sections (one H2 per task), short prose, real values (`example.org` emails), secrets from environment variables.
