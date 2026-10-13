# Examples for docs/commands/modules.md. Run by tests/docs-examples.sh on an installed Omeka S; the
# regions between the --8<-- markers are shown on the page.

# start without the modules this script downloads (Common is left alone: other examples use it)
for module in Sharing CSSEditor Log; do
    omeka-s-cli module:delete "$module" --force --ignore-not-found --quiet
done

# --8<-- [start:search]
omeka-s-cli module:search sharing
# --8<-- [end:search]
omeka-s-cli module:search sharing --json | jq -e 'any(.[]; .id == "Sharing")' > /dev/null

# --8<-- [start:search-extended]
omeka-s-cli module:search mapping --extended
# --8<-- [end:search-extended]

# --8<-- [start:repositories]
omeka-s-cli module:repositories
omeka-s-cli module:search pdf --repository daniel-km
omeka-s-cli module:search --unregistered --json | jq length
# --8<-- [end:repositories]
omeka-s-cli module:repositories --json | jq -e 'map(.ID) == ["omeka.org", "daniel-km"]' > /dev/null

# --8<-- [start:search-incompatible]
omeka-s-cli module:search alttext
omeka-s-cli module:search alttext --include-incompatible
# --8<-- [end:search-incompatible]
omeka-s-cli module:search alttext --include-incompatible --json | jq -e 'any(.[]; .id == "AltText")' > /dev/null

# --8<-- [start:download]
omeka-s-cli module:download common
omeka-s-cli module:install Common
# --8<-- [end:download]
test -f modules/Common/config/module.ini
test "$(omeka-s-cli module:status Common --json | jq -r '.[0].state')" = active

# --8<-- [start:download-install]
omeka-s-cli module:download log --install
# --8<-- [end:download-install]
test "$(omeka-s-cli module:status Log --json | jq -r '.[0].state')" = active

# --8<-- [start:download-version]
omeka-s-cli module:download sharing:1.4.0 --install
# --8<-- [end:download-version]
test "$(omeka-s-cli module:status Sharing --json | jq -r '.[0].version')" = 1.4.0

# a pinned version that does not support the installed Omeka S version is refused
! omeka-s-cli module:download csseditor:1.3.0 || exit 1
test ! -d modules/CSSEditor
# --force overrides the compatibility check
omeka-s-cli module:download csseditor:1.3.0 --force --quiet
grep -q '^version *= *"1.3.0"' modules/CSSEditor/config/module.ini
omeka-s-cli module:delete CSSEditor --quiet

# --8<-- [start:download-zip-url]
omeka-s-cli module:download https://github.com/omeka-s-modules/CSSEditor/releases/download/v1.3.1/CSSEditor-1.3.1.zip
# --8<-- [end:download-zip-url]
test -f modules/CSSEditor/Module.php

# --8<-- [start:download-zip-file]
curl -sSL -o /tmp/CSSEditor-1.3.1.zip \
    https://github.com/omeka-s-modules/CSSEditor/releases/download/v1.3.1/CSSEditor-1.3.1.zip
omeka-s-cli module:download /tmp/CSSEditor-1.3.1.zip --force
# --8<-- [end:download-zip-file]
rm /tmp/CSSEditor-1.3.1.zip

# --8<-- [start:download-git]
omeka-s-cli module:download https://github.com/omeka-s-modules/CSSEditor.git#v1.3.1 --force
omeka-s-cli module:download gh:omeka-s-modules/CSSEditor#v1.3.1 --force
# --8<-- [end:download-git]
test -d modules/CSSEditor/.git

# --8<-- [start:download-backup]
omeka-s-cli module:download gh:omeka-s-modules/CSSEditor#v1.3.1 --force --backup
# --8<-- [end:download-backup]
ls -d ~/.omeka-s-cli/backups/modules/CSSEditor/*/config > /dev/null
rm -rf ~/.omeka-s-cli/backups/modules/CSSEditor
omeka-s-cli module:delete CSSEditor --quiet

# --8<-- [start:list]
omeka-s-cli module:list
# --8<-- [end:list]

# --8<-- [start:list-filter]
omeka-s-cli module:list --not-active
omeka-s-cli module:list --json | jq -r '.[] | select(.state != "active") | .id'
# --8<-- [end:list-filter]

# --8<-- [start:status]
omeka-s-cli module:status Sharing
omeka-s-cli module:status Sharing --json | jq -r '.[0].state'
# --8<-- [end:status]
! omeka-s-cli module:status NoSuchModule || exit 1

# --8<-- [start:enable-disable]
omeka-s-cli module:disable Sharing
omeka-s-cli module:enable Sharing
# --8<-- [end:enable-disable]
test "$(omeka-s-cli module:status Sharing --json | jq -r '.[0].state')" = active

# --8<-- [start:bulk-dry-run]
omeka-s-cli module:disable --all --dry-run
# --8<-- [end:bulk-dry-run]
test "$(omeka-s-cli module:status Sharing --json | jq -r '.[0].state')" = active

# --8<-- [start:outdated]
omeka-s-cli module:list --outdated
# --8<-- [end:outdated]
omeka-s-cli module:list --outdated --json | jq -e 'any(.[]; .id == "Sharing")' > /dev/null

# --8<-- [start:update]
omeka-s-cli module:update Sharing --upgrade
# --8<-- [end:update]
omeka-s-cli module:status Sharing --json | jq -e '.[0] | .state == "active" and .installedVersion == "1.5.0"' > /dev/null

# --8<-- [start:update-all]
omeka-s-cli module:update --all --upgrade --dry-run
# --8<-- [end:update-all]

# back to Sharing 1.4.0, to show the upgrade step on its own
omeka-s-cli module:delete Sharing --force --quiet
omeka-s-cli module:download sharing:1.4.0 --install --quiet

# --8<-- [start:upgrade]
omeka-s-cli module:update Sharing
omeka-s-cli module:list --needs-upgrade
omeka-s-cli module:upgrade --all
# --8<-- [end:upgrade]
omeka-s-cli module:status Sharing --json | jq -e '.[0] | .state == "active" and .installedVersion == "1.5.0"' > /dev/null

# --8<-- [start:uninstall]
omeka-s-cli module:uninstall Sharing
omeka-s-cli module:delete Sharing
# --8<-- [end:uninstall]
test ! -d modules/Sharing

# an installed module is not deleted without --force
! omeka-s-cli module:delete Log || exit 1
test -d modules/Log

# --8<-- [start:delete-force]
omeka-s-cli module:delete Log --force
# --8<-- [end:delete-force]
test ! -d modules/Log

# --8<-- [start:bulk-cleanup]
omeka-s-cli module:uninstall --not-active --dry-run
omeka-s-cli module:delete --not-installed --dry-run
# --8<-- [end:bulk-cleanup]

# --8<-- [start:ignore-not-found]
omeka-s-cli module:delete Sharing --force --ignore-not-found
# --8<-- [end:ignore-not-found]
! omeka-s-cli module:delete Sharing --force || exit 1
