# Bash scripts

Every omeka-s-cli command is a plain shell command with a meaningful exit code, so provisioning an
Omeka S instance is a matter of calling them in the right order. This page builds a complete
provisioning script that you can adapt.

## A provisioning script

The script below takes a server with PHP, a database and omeka-s-cli from nothing to a running site:
it downloads and installs Omeka S, adds two modules and a theme, creates a user, sets a few settings
and creates a site with a permission for that user.

```bash title="provision.sh"
--8<-- "docs/examples/automation/provision.sh"
```

Run it as the user that owns the web root, with the secrets in the environment, for example
`sudo -u www-data DB_PASSWORD=... ADMIN_PASSWORD=... ./provision.sh`.

## Make every step safe to re-run

A provisioning script runs again and again: after a failure halfway, when you add a module to the
list, or on every deployment. The script above can run any number of times, because each step either
checks first or is harmless when there is nothing to do:

| Step | How it is safe to repeat |
| --- | --- |
| `core:download` | only runs when the directory has no Omeka S yet |
| `config:create-db-ini` | rewrites the same file with the same values |
| `core:install` | guarded by `core:status --is-installed` |
| `core:migrate` | guarded by `core:status --needs-migration` |
| `module:download`, `theme:download` | an existing module or theme is left alone (a warning, exit code 0) |
| `module:install` | installing an installed module does nothing |
| `user:add`, `site:add` | `--ignore-existing` skips an existing user or site |
| `config:set`, `site:set-permission` | set the value, whatever it was |

!!! note "Changing a pinned version"
    `module:download` does not replace a module that is already there, even when you pin a different
    version. To move to another version, use [`module:update`](../commands/modules.md#update-modules), or
    `module:download <id>:<version> --force` followed by `module:upgrade <id>`.

To remove things idempotently, the delete commands take `--ignore-not-found`, which skips a resource
that is already gone instead of failing.

## Check the result

The status commands answer through their exit code or as JSON, which makes checks in scripts and
CI jobs short. A few checks for the instance the script built:

```bash
--8<-- "docs/examples/automation/provision-check.sh:checks"
```

## Next steps

- Each command group has its own page with the details, under *Commands* in the navigation.
- To describe the instance in a file instead of a script, see [Blueprints](blueprints.md).
