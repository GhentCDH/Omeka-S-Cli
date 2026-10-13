# Examples for docs/commands/sites.md. Run by tests/docs-examples.sh on an installed Omeka S; the regions
# between the --8<-- markers are shown on the page.

# start clean, so the script can also run on an instance an earlier run left behind
for site in collections exhibitions intranet; do
    omeka-s-cli site:delete "$site" --ignore-not-found --quiet
done
# what the examples need: a theme and two users
omeka-s-cli theme:download freedom --force --quiet
omeka-s-cli user:add jane.doe@example.org "Jane Doe" editor --ignore-existing --quiet
omeka-s-cli user:add john.roe@example.org "John Roe" researcher --ignore-existing --quiet

# query the database directly, to check what the CLI does not show
sql() {
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N -e "$1"
}

# --8<-- [start:add]
omeka-s-cli site:add "Digital Collections" --slug collections --theme freedom --default
# --8<-- [end:add]
omeka-s-cli site:list --json | jq -e '.[] | select(.slug == "collections")
    | .theme == "freedom" and .is_public == true and .is_default == true and .owner == "admin@example.org"'

# --8<-- [start:add-private]
omeka-s-cli site:add "Staff Intranet" --slug intranet --private \
    --summary "Internal documentation for the cataloguing team" \
    --owner jane.doe@example.org --no-assign-new-items
# --8<-- [end:add-private]
omeka-s-cli site:list --json | jq -e '.[] | select(.slug == "intranet")
    | .is_public == false and .is_default == false and .owner == "jane.doe@example.org"'
test "$(sql "SELECT assign_new_items FROM site WHERE slug = 'intranet'")" = 0

# without --slug, the title identifies the site: a second add of the same title fails ...
omeka-s-cli site:add "Exhibitions"
test "$(omeka-s-cli site:list --json | jq -r '.[] | select(.title == "Exhibitions") | .slug')" = exhibitions
! omeka-s-cli site:add "Exhibitions" || exit 1

# --8<-- [start:ignore-existing]
omeka-s-cli site:add "Exhibitions" --ignore-existing
# --8<-- [end:ignore-existing]
test "$(omeka-s-cli site:list --json | jq '[.[] | select(.title == "Exhibitions")] | length')" = 1

# an unknown theme is refused
! omeka-s-cli site:add "Elsewhere" --theme no-such-theme || exit 1

# --8<-- [start:list]
omeka-s-cli site:list
# --8<-- [end:list]

# --8<-- [start:list-json]
omeka-s-cli site:list --json | jq -r '.[] | select(.is_default) | .slug'
# --8<-- [end:list-json]
test "$(omeka-s-cli site:list --json | jq -r '.[] | select(.is_default) | .slug')" = collections

# Omeka S gives every new site a "Welcome" page; an update must leave it alone
pages_before=$(sql "SELECT COUNT(*) FROM site_page p JOIN site s ON s.id = p.site_id WHERE s.slug = 'intranet'")
test "$pages_before" -ge 1

# --8<-- [start:update]
omeka-s-cli site:update intranet --title "Cataloguing Handbook" --public
# --8<-- [end:update]
omeka-s-cli site:list --json | jq -e '.[] | select(.slug == "intranet")
    | .title == "Cataloguing Handbook" and .is_public == true and .theme == "default" and .owner == "jane.doe@example.org"'
test "$(sql "SELECT summary FROM site WHERE slug = 'intranet'")" = "Internal documentation for the cataloguing team"
test "$(sql "SELECT COUNT(*) FROM site_page p JOIN site s ON s.id = p.site_id WHERE s.slug = 'intranet'")" = "$pages_before"

# --8<-- [start:update-default]
omeka-s-cli site:update exhibitions --slug exhibits --default
# --8<-- [end:update-default]
omeka-s-cli site:list --json | jq -e '[.[] | select(.is_default) | .slug] == ["exhibits"]'
omeka-s-cli site:update exhibits --slug exhibitions --quiet

# --8<-- [start:set-permission]
omeka-s-cli site:set-permission collections jane.doe@example.org editor
omeka-s-cli site:set-permission collections john.roe@example.org viewer
# --8<-- [end:set-permission]

# --8<-- [start:list-permissions]
omeka-s-cli site:list-permissions collections
# --8<-- [end:list-permissions]
omeka-s-cli site:list-permissions collections --json | jq -e 'map({(.email): .role}) | add
    == {"admin@example.org": "admin", "jane.doe@example.org": "editor", "john.roe@example.org": "viewer"}'

# --8<-- [start:change-permission]
omeka-s-cli site:set-permission collections jane.doe@example.org admin
# --8<-- [end:change-permission]
# only Jane's role changed; the other permissions are kept
omeka-s-cli site:list-permissions collections --json | jq -e 'map({(.email): .role}) | add
    == {"admin@example.org": "admin", "jane.doe@example.org": "admin", "john.roe@example.org": "viewer"}'
! omeka-s-cli site:set-permission collections john.roe@example.org owner || exit 1

# --8<-- [start:delete-permission]
omeka-s-cli site:delete-permission collections john.roe@example.org
# --8<-- [end:delete-permission]
omeka-s-cli site:list-permissions collections --json | jq -e 'map(.email) == ["admin@example.org", "jane.doe@example.org"]'
! omeka-s-cli site:delete-permission collections john.roe@example.org || exit 1
omeka-s-cli site:delete-permission collections john.roe@example.org --ignore-not-found

# --8<-- [start:delete]
omeka-s-cli site:delete exhibitions
omeka-s-cli site:delete intranet --ignore-not-found
# --8<-- [end:delete]
omeka-s-cli site:list --json | jq -e 'map(.slug) == ["collections"]'
! omeka-s-cli site:delete exhibitions || exit 1
omeka-s-cli site:delete exhibitions --ignore-not-found

# deleting the default site clears the default
omeka-s-cli site:update collections --default --quiet
omeka-s-cli site:delete collections --quiet
test -z "$(sql "SELECT value FROM setting WHERE id = 'default_site'")"

# clean up
omeka-s-cli user:delete jane.doe@example.org --quiet
omeka-s-cli user:delete john.roe@example.org --quiet
