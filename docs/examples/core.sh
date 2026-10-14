# Examples for docs/commands/core.md. Run by tests/docs-examples.sh on an empty /var/www/omeka-s and an
# empty database; the regions between the --8<-- markers are shown on the page.

# --8<-- [start:download]
omeka-s-cli core:download /var/www/omeka-s
# --8<-- [end:download]
test -f /var/www/omeka-s/application/Module.php

# --8<-- [start:download-version]
omeka-s-cli core:download /opt/omeka-s-4.1.1 4.1.1 --create-destination
# --8<-- [end:download-version]
rm -rf /opt/omeka-s-4.1.1

# --8<-- [start:database-ini]
cd /var/www/omeka-s
omeka-s-cli config:create-db-ini --host db --dbname omeka --username omeka --password "$DB_PASSWORD"
# --8<-- [end:database-ini]
grep -q 'dbname = "omeka"' config/database.ini

# --8<-- [start:install]
omeka-s-cli core:install \
    --title "Digital Collections" \
    --admin-name "Site Admin" \
    --admin-email admin@example.org \
    --admin-password "$ADMIN_PASSWORD" \
    --time-zone Europe/Brussels \
    --locale en_US
# --8<-- [end:install]

# --8<-- [start:status]
omeka-s-cli core:status
omeka-s-cli core:version
# --8<-- [end:status]
test "$(omeka-s-cli core:status)" = installed

# --8<-- [start:is-installed]
if ! omeka-s-cli core:status --is-installed; then
    omeka-s-cli core:install --admin-email admin@example.org --admin-password "$ADMIN_PASSWORD"
fi
# --8<-- [end:is-installed]

# --8<-- [start:json]
omeka-s-cli core:status --json | jq -r '.needsMigration'
# --8<-- [end:json]
test "$(omeka-s-cli core:status --json | jq -r '.needsMigration')" = false

# --8<-- [start:update]
omeka-s-cli core:update
omeka-s-cli core:migrate
# --8<-- [end:update]
omeka-s-cli core:status --is-ready

# --8<-- [start:base-path]
cd /tmp
omeka-s-cli core:version --base-path /var/www/omeka-s
# --8<-- [end:base-path]

# a release ZIP that is already here (back in the Omeka S directory: the last example left it)
cd "$OMEKA_DIR"
CORE_VERSION=$(omeka-s-cli core:version)
CORE_ZIP="$SCRATCH_DIR/omeka-s-$CORE_VERSION.zip"
curl -fsSL -o "$CORE_ZIP" "https://github.com/omeka/omeka-s/releases/download/v$CORE_VERSION/omeka-s-$CORE_VERSION.zip"

# --8<-- [start:download-source]
omeka-s-cli core:download "$SCRATCH_DIR/omeka-s" --source "$CORE_ZIP" --create-destination
# --8<-- [end:download-source]
test -f "$SCRATCH_DIR/omeka-s/application/Module.php"
