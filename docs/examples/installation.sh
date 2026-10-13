# Examples for docs/installation.md. Run by tests/docs-examples.sh; needs no Omeka S instance.
# Installs the latest published release to /usr/local/bin, like a reader would, and removes it again.

# the examples are run as root in the dev container, which has no sudo
if ! command -v sudo > /dev/null; then sudo() { "$@"; }; fi
cd "$SCRATCH_DIR"

# --8<-- [start:requirements]
php --version
php -m | grep -E -i '^(pdo_mysql|zip)$'
# --8<-- [end:requirements]

# --8<-- [start:install]
curl -fsSL -o omeka-s-cli https://github.com/GhentCDH/Omeka-S-Cli/releases/latest/download/omeka-s-cli.phar
sudo install -m 0755 omeka-s-cli /usr/local/bin/omeka-s-cli
# --8<-- [end:install]

# the runner puts the binary under test first on the PATH: check the one just installed
# --8<-- [start:version]
/usr/local/bin/omeka-s-cli --version
# --8<-- [end:version]

# --8<-- [start:self-update]
sudo /usr/local/bin/omeka-s-cli self-update
# --8<-- [end:self-update]

sudo rm /usr/local/bin/omeka-s-cli
