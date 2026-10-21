# Examples for docs/commands/settings.md. Run by tests/docs-examples.sh on an installed Omeka S; the
# regions between the --8<-- markers are shown on the page.

# the page keeps its files in the home directory (~/settings, ~/modules.txt): use the scratch directory
export HOME="$SCRATCH_DIR"

# an exported file is JSONC: drop its first line, a comment, to read it with jq
settings_file() { tail -n +2 "$1"; }

# a site for the site settings, and the Common module for the module list
omeka-s-cli site:add "Collections" --slug collections --ignore-existing --quiet
omeka-s-cli module:download common --quiet
omeka-s-cli module:install Common --quiet

# --8<-- [start:get]
omeka-s-cli config:get installation_title
omeka-s-cli config:get pagination_per_page
# --8<-- [end:get]
omeka-s-cli config:get pagination_per_page | jq -e 'type == "number"'

# a missing setting is an error
! omeka-s-cli config:get no_such_setting || exit 1

# --8<-- [start:list]
omeka-s-cli config:list per_page
# --8<-- [end:list]
omeka-s-cli config:list per_page | jq -e 'has("pagination_per_page") and length == 1'

# --8<-- [start:list-keys]
omeka-s-cli config:list | jq -r 'keys[]'
# --8<-- [end:list-keys]
omeka-s-cli config:list | jq -r 'keys[]' | grep -qx administrator_email

# --8<-- [start:set]
omeka-s-cli config:set installation_title "Digital Collections"   # a string
omeka-s-cli config:set pagination_per_page 50                      # a number
omeka-s-cli config:set version_notifications false                 # a boolean
# --8<-- [end:set]
test "$(omeka-s-cli config:get installation_title)" = '"Digital Collections"'
test "$(omeka-s-cli config:get pagination_per_page)" = 50
test "$(omeka-s-cli config:get version_notifications)" = false

# --8<-- [start:set-string]
omeka-s-cli config:set installation_title '"2026"'
# --8<-- [end:set-string]
test "$(omeka-s-cli config:get installation_title)" = '"2026"'
omeka-s-cli config:set installation_title "Digital Collections" --quiet

# --8<-- [start:set-json]
extensions=$(omeka-s-cli config:get extension_whitelist | jq -c '. + ["heic"] | unique')
omeka-s-cli config:set extension_whitelist "$extensions"
# --8<-- [end:set-json]
omeka-s-cli config:get extension_whitelist | jq -e 'index("heic") and index("pdf")'

omeka-s-cli config:set docs_example_flag 1 --quiet
# --8<-- [start:delete]
omeka-s-cli config:set docs_example_flag null
# --8<-- [end:delete]
! omeka-s-cli config:get docs_example_flag || exit 1

# --8<-- [start:export]
omeka-s-cli config:export ~/settings-backup
ls ~/settings-backup
# --8<-- [end:export]
settings_file ~/settings-backup/core.setting.jsonc \
    | jq -e '.type == "setting" and .module == "core" and .settings.pagination_per_page == 50'

# --8<-- [start:export-filter]
omeka-s-cli config:export ~/settings-backup --scope setting --module core
# --8<-- [end:export-filter]

# the files the page shows, as a reader would have saved them
mkdir -p ~/settings
cp "$EXAMPLES_DIR"/files/settings/*.jsonc ~/settings/

# a time zone the import changes (core.sh installs with Europe/Brussels)
omeka-s-cli config:set time_zone UTC --quiet

# --8<-- [start:import-dry-run]
omeka-s-cli config:import ~/settings --dry-run
# --8<-- [end:import-dry-run]
test "$(omeka-s-cli config:get time_zone | jq -r .)" != Europe/Brussels

# --8<-- [start:import]
omeka-s-cli config:import ~/settings
# --8<-- [end:import]
test "$(omeka-s-cli config:get time_zone | jq -r .)" = Europe/Brussels
rm -rf "$SCRATCH_DIR/check" && omeka-s-cli config:export "$SCRATCH_DIR/check" --scope site_setting --quiet
settings_file "$SCRATCH_DIR/check/core.site_setting.collections.jsonc" \
    | jq -e '.site == "collections" and .settings.locale == "nl" and .settings.browse_attached_items == true'

# a site setting the file does not contain, which --prune removes
printf '{"type": "site_setting", "module": "core", "site": "collections", "settings": {"show_user_bar": 1}}' \
    > "$SCRATCH_DIR/extra.json"
omeka-s-cli config:import "$SCRATCH_DIR/extra.json" --quiet

# --8<-- [start:import-prune]
omeka-s-cli config:import ~/settings/core.site_setting.collections.jsonc --prune
# --8<-- [end:import-prune]
rm -rf "$SCRATCH_DIR/check" && omeka-s-cli config:export "$SCRATCH_DIR/check" --scope site_setting --quiet
settings_file "$SCRATCH_DIR/check/core.site_setting.collections.jsonc" \
    | jq -e '(.settings | has("show_user_bar") | not) and .settings.locale == "nl"'

# a file for a module that is not active is skipped with a warning, or fails the import with --strict
printf '{"type": "setting", "module": "Log", "settings": {"log_example": 1}}' > "$SCRATCH_DIR/Log.setting.json"
omeka-s-cli config:import "$SCRATCH_DIR/Log.setting.json" --quiet
! omeka-s-cli config:import "$SCRATCH_DIR/Log.setting.json" --strict --quiet || exit 1

# --8<-- [start:modules]
omeka-s-cli config:modules --installed
# --8<-- [end:modules]
omeka-s-cli config:modules --installed --json | jq -e '.[] | select(.id == "Common" and .state == "active")'

# --8<-- [start:modules-export]
omeka-s-cli config:modules --installed --json | jq -r '.[] | "\(.id):\(.version)"' > ~/modules.txt
# --8<-- [end:modules-export]
grep -qx "Common:[0-9.]*" ~/modules.txt

# --8<-- [start:modules-install]
for module in $(cat ~/modules.txt); do
    omeka-s-cli module:download "$module" --install
done
# --8<-- [end:modules-install]
omeka-s-cli module:list --json | jq -e '.[] | select(.id == "Common" and .state == "active")'

# --8<-- [start:themes]
omeka-s-cli config:themes --active --json
# --8<-- [end:themes]
omeka-s-cli config:themes --active --json | jq -e '.[] | select(.id == "default" and .active == true)'

# clean up what other pages do not expect
omeka-s-cli site:delete collections --ignore-not-found --quiet
