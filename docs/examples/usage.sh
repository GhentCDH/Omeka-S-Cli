# Examples for docs/usage.md. Run by tests/docs-examples.sh on an installed Omeka S; the regions between
# the --8<-- markers are shown on the page.

# the cache examples remove ~/.cache/omeka-s-cli: give this script a cache of its own
export HOME="$SCRATCH_DIR"

# a downloaded module and no user nobody@example.org, for the warning and error examples
omeka-s-cli module:download common --quiet
omeka-s-cli user:delete nobody@example.org --ignore-not-found --quiet

# --8<-- [start:help]
omeka-s-cli
omeka-s-cli module:download --help
omeka-s-cli --version
# --8<-- [end:help]
omeka-s-cli | grep -q 'module:download'
omeka-s-cli module:download --help | grep -q -- '--install'

# a mistyped command name fails with exit code 127, and suggests the closest one
status=0; omeka-s-cli module:lst > "$SCRATCH_DIR/typo.txt" 2>&1 || status=$?
test "$status" -eq 127
grep -q 'Did you mean module:list?' "$SCRATCH_DIR/typo.txt"

# --8<-- [start:subdirectory]
cd modules
omeka-s-cli core:version
# --8<-- [end:subdirectory]
cd "$OMEKA_DIR"

# --8<-- [start:base-path]
cd /tmp
omeka-s-cli user:list --base-path "$OMEKA_DIR"
# --8<-- [end:base-path]
# outside an installation, without --base-path, the command fails
! omeka-s-cli user:list || exit 1
cd "$OMEKA_DIR"

# --8<-- [start:verbose]
omeka-s-cli core:version -v
# --8<-- [end:verbose]
omeka-s-cli core:version -v | grep -q "Omeka S found at $OMEKA_DIR"

# --8<-- [start:warning]
omeka-s-cli module:download common
echo "exit code: $?"
# --8<-- [end:warning]
omeka-s-cli module:download common | grep -q 'already exists'

# --8<-- [start:verbosity]
omeka-s-cli module:download common --verbosity 1
omeka-s-cli module:download common --quiet
# --8<-- [end:verbosity]
! omeka-s-cli module:download common --verbosity 1 | grep -q 'Selected' || exit 1
test -z "$(omeka-s-cli module:download common --quiet 2>&1)"

# --8<-- [start:error]
omeka-s-cli user:delete nobody@example.org --quiet || echo "exit code: $?"
# --8<-- [end:error]
test "$(NO_COLOR=1 omeka-s-cli user:delete nobody@example.org --quiet 2>&1)" = 'User not found: nobody@example.org'
! omeka-s-cli user:delete nobody@example.org --quiet 2> /dev/null || exit 1

# --8<-- [start:ignore-not-found]
omeka-s-cli user:delete nobody@example.org --ignore-not-found
# --8<-- [end:ignore-not-found]
test -z "$(omeka-s-cli user:delete nobody@example.org --ignore-not-found --quiet 2>&1)"

# --8<-- [start:formats]
omeka-s-cli user:list --table
omeka-s-cli user:list --csv
omeka-s-cli user:list --json
# --8<-- [end:formats]
omeka-s-cli user:list --csv | head -1 | grep -qx 'id,display_name,email,is_active,role'

# --8<-- [start:jq]
omeka-s-cli module:list --json | jq -r '.[] | select(.state == "active") | .id'
# --8<-- [end:jq]
omeka-s-cli module:list --json | jq -e 'type == "array"'

# --8<-- [start:no-color]
NO_COLOR=1 omeka-s-cli module:download common > download.log
# --8<-- [end:no-color]
! grep -q $'\e' download.log || exit 1
rm download.log

state_before=$(omeka-s-cli module:list --json | jq -r '.[] | select(.id == "Common") | .state')
# --8<-- [start:dry-run]
omeka-s-cli module:install Common --dry-run
# --8<-- [end:dry-run]
test "$(omeka-s-cli module:list --json | jq -r '.[] | select(.id == "Common") | .state')" = "$state_before"

# --8<-- [start:debug]
omeka-s-cli user:delete nobody@example.org --debug || true
# --8<-- [end:debug]
(NO_COLOR=1 omeka-s-cli user:delete nobody@example.org --debug 2>&1 || true) | grep -q '^#0 '

# --8<-- [start:refresh]
omeka-s-cli module:search mapping --refresh
# --8<-- [end:refresh]
test -d ~/.cache/omeka-s-cli

# --8<-- [start:clear-cache]
rm -rf ~/.cache/omeka-s-cli
# --8<-- [end:clear-cache]
omeka-s-cli module:search mapping --json | jq -e 'length > 0'
