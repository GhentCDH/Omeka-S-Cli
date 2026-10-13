# Examples for docs/commands/themes.md. Run by tests/docs-examples.sh on an installed Omeka S; the
# regions between the --8<-- markers are shown on the page.

# start without the site and the themes this script adds
omeka-s-cli site:delete exhibits --ignore-not-found --quiet
for theme in freedom Cozy; do
    omeka-s-cli theme:delete "$theme" --force --ignore-not-found --quiet
done

# --8<-- [start:search]
omeka-s-cli theme:search
# --8<-- [end:search]
omeka-s-cli theme:search --json | jq -e 'any(.[]; .id == "freedom")' > /dev/null

# --8<-- [start:search-query]
omeka-s-cli theme:search free --extended
# --8<-- [end:search-query]

# --8<-- [start:download]
omeka-s-cli theme:download freedom
# --8<-- [end:download]
test -f themes/freedom/config/theme.ini

# --8<-- [start:download-version]
omeka-s-cli theme:download freedom:1.0.7 --force
# --8<-- [end:download-version]
test "$(omeka-s-cli theme:status freedom --json | jq -r '.[0].version')" = 1.0.7

# --8<-- [start:download-git]
omeka-s-cli theme:download gh:omeka-s-themes/cozy#v1.6.3
# --8<-- [end:download-git]
test -f themes/Cozy/config/theme.ini

# --8<-- [start:download-zip]
omeka-s-cli theme:download https://github.com/omeka-s-themes/cozy/releases/download/v1.6.3/cozy-1.6.3.zip --force
# --8<-- [end:download-zip]

# --8<-- [start:list]
omeka-s-cli theme:list
# --8<-- [end:list]

# --8<-- [start:outdated]
omeka-s-cli theme:list --outdated
omeka-s-cli theme:list --outdated --json | jq -r '.[].id'
# --8<-- [end:outdated]
omeka-s-cli theme:list --outdated --json | jq -e 'any(.[]; .id == "freedom")' > /dev/null

# --8<-- [start:update]
omeka-s-cli theme:download freedom --force --backup
# --8<-- [end:update]
omeka-s-cli theme:status freedom --json | jq -e '.[0].updateAvailable == false' > /dev/null
ls -d ~/.omeka-s-cli/backups/themes/freedom/*/config > /dev/null
rm -rf ~/.omeka-s-cli/backups/themes/freedom

# --8<-- [start:status]
omeka-s-cli theme:status freedom
# --8<-- [end:status]
! omeka-s-cli theme:status no-such-theme || exit 1

# --8<-- [start:use]
omeka-s-cli site:add "Exhibits" --slug exhibits --theme freedom
# --8<-- [end:use]

# a theme that a site uses is not deleted without --force
! omeka-s-cli theme:delete freedom || exit 1
test -d themes/freedom
omeka-s-cli site:delete exhibits --quiet

# --8<-- [start:delete]
omeka-s-cli theme:delete freedom
omeka-s-cli theme:delete Cozy --ignore-not-found
# --8<-- [end:delete]
test ! -d themes/freedom
test ! -d themes/Cozy
