# AGENTS.md

## Purpose
- `Omeka-S-Cli` is a PHP CLI for operating Omeka S instances and managing module/theme assets.
- Main entrypoint is `bin/omeka-s-cli`; commands are aggregated in `src/Commands/Index.php`.

## Big-Picture Architecture
- Command layer: each domain has `src/Commands/<Domain>/Index.php` that returns instantiated command classes.
- Shared command behavior is centralized in `src/Commands/AbstractCommand.php` (global options, output formatting, Omeka path detection/bootstrap).
- Omeka bridge is `src/Omeka/*`: `OmekaInstance` bootstraps Omeka runtime, then `ModuleApi` / `ThemeApi` wrap Omeka services.
- Remote metadata layer is `src/Manager/*/Manager.php` + `src/Repository/**` (official `omeka.org` + Daniel-KM CSV for modules).
- Download layer is `src/Downloader/GitDownloader.php` and `src/Downloader/ZipDownloader.php`.
- Repository results are cached via `src/Cache.php` into `$HOME/.cache/omeka-s-cli` using `src/Cache/FileCache.php`.

## Important Data Flows
- Module download/update (`src/Commands/Module/DownloadCommand.php`): parse user input with `src/Helper/ResourceUriParser.php` -> resolve candidate versions via manager/repositories -> filter by Omeka compatibility (`src/Helper/VersionCompatibility.php`) -> download/unpack -> install into Omeka `modules/`.
- Omeka-bound commands: locate Omeka base path automatically (or `--base-path`) and call `OmekaInstanceFactory::createInstance(...)`.

## Project Conventions To Follow
- New commands should extend `src/Commands/AbstractCommand.php` (or a domain abstract such as `src/Commands/Module/AbstractModuleCommand.php`).
- Always register new commands in the domain `Index.php`; unregistered commands are invisible to the CLI.
- Prefer built-in output options (`optionJson`, `optionTable`, `optionCSV`, `optionEnv`) plus `outputFormatted()`.
- Non-fatal situations should use `WarningException` (handled in `src/Cli/Application.php` as warning + exit code 0).
- Reuse existing commands for orchestration (example: `module:update` invokes `module:download` and `module:upgrade`).

## External Integrations
- Omeka version API: `https://api.omeka.org/latest-version-s`.
- Official module catalog: `https://omeka.org/add-ons/json/s_module.json`.
- Official theme catalog: `https://omeka.org/add-ons/json/s_theme.json`.
- Daniel-KM module catalog CSV: `https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv`.

## Developer Workflows
- Install deps: `composer install`
- Run CLI: `php bin/omeka-s-cli --help`
- Lint/fix: `composer lint` / `composer fix`
- Build PHAR: `box compile` (configured by `box.json` + `scoper.inc.php`)
- Optional container dev setup is defined in `compose.yml` and `Dockerfile`.

## Packaging Notes
- PHAR scoping excludes `OSC`, `Omeka`, and `Laminas` namespaces (`scoper.inc.php`) because Omeka provides these at runtime.
- `composer.json` lint/fix scripts ignore several command index files; do not use those files as strict style references.

## Source Of Existing AI Conventions
- One required convention glob search matched only `README.md`; no existing repo AI policy files were found.
