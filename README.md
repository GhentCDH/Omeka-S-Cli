# Omeka-S-Cli

Omeka-S-Cli is a command line tool to install, configure and maintain [Omeka S](https://omeka.org/s/)
instances: download and update the core, manage modules, themes, users, sites, vocabularies, resource
templates and settings, and deploy a complete instance from a declarative blueprint.

**Documentation: <https://ghentcdh.github.io/Omeka-S-Cli/>**

## Installation

Requires PHP 8.1 or newer with the `pdo_mysql` and `zip` extensions, and Omeka S 4 or newer.

```bash
curl -fsSL -o omeka-s-cli https://github.com/GhentCDH/Omeka-S-Cli/releases/latest/download/omeka-s-cli.phar
sudo install -m 0755 omeka-s-cli /usr/local/bin/omeka-s-cli
omeka-s-cli --help
```

See the [documentation](https://ghentcdh.github.io/Omeka-S-Cli/) for the
[installation](https://ghentcdh.github.io/Omeka-S-Cli/installation/), the
[commands](https://ghentcdh.github.io/Omeka-S-Cli/usage/) and
[install automation](https://ghentcdh.github.io/Omeka-S-Cli/automation/) with bash scripts or
blueprints.

## Development

```bash
composer install
composer build    # builds bin/omeka-s-cli.phar
```

See [Development](https://ghentcdh.github.io/Omeka-S-Cli/development/) for the dev container, the
tests and how to work on the documentation (sources in [docs/](docs/)).

## Credits

Developed at the [Ghent Centre for Digital Humanities](https://www.ghentcdh.ugent.be/), Ghent University.
Maintained by Frederic Lamsens, with thanks to all
[contributors](https://github.com/GhentCDH/Omeka-S-Cli/graphs/contributors).

Built with:

- [adhocore/cli](https://github.com/adhocore/php-cli) — CLI framework
- [fakerphp/faker](https://github.com/FakerPHP/Faker) — fake data generation

Code copied from third-party sources:

- `resource-template:import` — `flagValid()` and `checkMissingDependencies()` methods copied from
  [Common module](https://gitlab.com/Daniel-KM/Omeka-S-module-Common)
  by [Daniel Berthereau](https://gitlab.com/Daniel-KM), © 2020–2025

Inspired by:

- [Omeka S Cli](https://github.com/Libnamic/omeka-s-cli/) by Libnamic
- [Omeka CLI](https://github.com/biblibre/omeka-cli) by biblibre
- [Omeka S Module Settings](https://github.com/mcoonen/omekas-module-settings) by Maarten Coonen
- [Omeka S Playground](https://ateeducacion.github.io/omeka-s-playground/docs/) by Ernesto Serrano
