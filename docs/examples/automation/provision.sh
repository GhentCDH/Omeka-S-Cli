#!/usr/bin/env bash
# Provision an Omeka S instance: download and install the core, add modules, a theme, users, settings
# and a site. Every step checks the current state first, so the script is safe to re-run.
#
# Usage: DB_PASSWORD=... ADMIN_PASSWORD=... ./provision.sh
set -euo pipefail

# ── configuration ────────────────────────────────────────────────────────────
OMEKA_DIR="${OMEKA_DIR:-/var/www/omeka-s}"
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-omeka}"
DB_USER="${DB_USER:-omeka}"
: "${DB_PASSWORD:?set DB_PASSWORD}"
: "${ADMIN_PASSWORD:?set ADMIN_PASSWORD}"

OMEKA_VERSION="4.2.1"
MODULES=("Common:3.4.93" "Log")     # in dependency order; pin a version with <id>:<version>
THEME="freedom"

# ── core ─────────────────────────────────────────────────────────────────────
mkdir -p "$OMEKA_DIR"
if [[ ! -d "$OMEKA_DIR/application" ]]; then
    omeka-s-cli core:download "$OMEKA_DIR" "$OMEKA_VERSION"
fi
cd "$OMEKA_DIR"

omeka-s-cli config:create-db-ini --host "$DB_HOST" --dbname "$DB_NAME" \
    --username "$DB_USER" --password "$DB_PASSWORD"

if ! omeka-s-cli core:status --is-installed; then
    omeka-s-cli core:install --title "Digital Collections" \
        --admin-name "Site Admin" --admin-email admin@example.org \
        --admin-password "$ADMIN_PASSWORD" --time-zone Europe/Brussels
fi
if omeka-s-cli core:status --needs-migration; then
    omeka-s-cli core:migrate
fi

# ── modules and theme ────────────────────────────────────────────────────────
# a module that is already downloaded is left alone, and installing an installed module is a no-op
for module in "${MODULES[@]}"; do
    omeka-s-cli module:download "$module"
    omeka-s-cli module:install "${module%%:*}"
done
omeka-s-cli theme:download "$THEME"

# ── users, settings and site ─────────────────────────────────────────────────
omeka-s-cli user:add editor@example.org "Erin Editor" editor --ignore-existing

omeka-s-cli config:set installation_title "Digital Collections"
omeka-s-cli config:set administrator_email admin@example.org

omeka-s-cli site:add "Collections" --slug collections --theme "$THEME" --default --ignore-existing
omeka-s-cli site:set-permission collections editor@example.org editor
