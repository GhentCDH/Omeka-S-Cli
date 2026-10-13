# Examples for docs/automation/blueprints.md. Run by tests/docs-examples.sh on an empty /var/www/omeka-s
# and an empty database omeka; the regions between the --8<-- markers are shown on the page. The
# blueprints are the files in docs/examples/files/blueprints, reached as ~/blueprints.

# a private home: ~/blueprints is the example directory, the CLI's download cache is shared
CACHE_DIR="$HOME/.cache/omeka-s-cli"
mkdir -p "$CACHE_DIR"
export HOME="$SCRATCH_DIR"
mkdir -p ~/.cache
ln -sfn "$CACHE_DIR" ~/.cache/omeka-s-cli
ln -sfn "$EXAMPLES_DIR/files/blueprints" ~/blueprints

# --8<-- [start:deploy]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc \
    --base-path /var/www/omeka-s \
    --db-host db --db-name omeka --db-user omeka --db-password "$DB_PASSWORD" \
    --admin-email admin@example.org --admin-password "$ADMIN_PASSWORD"
# --8<-- [end:deploy]
cd /var/www/omeka-s
omeka-s-cli core:status --is-ready
grep -q 'dbname = "omeka"' config/database.ini
omeka-s-cli user:list --json | jq -e 'any(.[]; .email == "admin@example.org" and .role == "global_admin")'
omeka-s-cli module:list --json | jq -e '[.[] | select(.state == "active") | .id] | index("Common") and index("Sharing")'
omeka-s-cli site:list-permissions collections --json \
    | jq -e 'any(.[]; .email == "editor@example.org" and .role == "editor")'
test "$(omeka-s-cli config:get installation_title | jq -r .)" = "Digital Collections"
test "$(omeka-s-cli config:get time_zone | jq -r .)" = Europe/Brussels

# --8<-- [start:sync]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --base-path /var/www/omeka-s --skip core --force
# --8<-- [end:sync]
test "$(omeka-s-cli site:list --json | jq length)" = 1
test "$(omeka-s-cli resource-template:list --json | jq '[.[] | select(.label == "Book")] | length')" = 1

# a user added by hand is gone after a reset
omeka-s-cli user:add reviewer@example.org "Temporary Reviewer" reviewer --quiet
# --8<-- [start:reset]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --base-path /var/www/omeka-s --force \
    --admin-password "$ADMIN_PASSWORD"
# --8<-- [end:reset]
omeka-s-cli core:status --is-ready
omeka-s-cli user:list --json | jq -e 'all(.[]; .email != "reviewer@example.org")'
omeka-s-cli site:list --json | jq -e 'any(.[]; .slug == "collections")'
