# Install automation

omeka-s-cli is built to set up and maintain Omeka S instances without clicking through the admin
interface: for a new server, a test environment that is rebuilt every night, or a fleet of
similar instances. There are two ways to automate it.

[Bash scripts](bash.md)
:   Call the commands one by one from a shell script, a CI job, a Dockerfile or a configuration
    management tool. Full control over the order and the conditions; you make each step safe to
    re-run yourself.

[Blueprints](blueprints.md)
:   Describe the desired state in one JSON file (modules, themes, vocabularies, resource templates,
    users, sites, settings) and let `blueprint:deploy` work out the steps. Re-running it only adds what
    is missing. Blueprints follow a [shared specification](https://omeka-s-contrib.github.io/omeka-s-blueprints/),
    so the same file works with other tools.

Both use the same commands underneath, and they combine well: a script can install the core and
then deploy a blueprint, or deploy a blueprint and then run commands that blueprints do not cover
(dummy data, API keys).
