# Checks for docs/examples/automation/provision.sh (shown in full on docs/automation/bash.md, so it
# cannot hold checks itself). Runs after it on the same instance: re-runs it to prove it is safe to
# re-run, then checks the result.

bash "$EXAMPLES_DIR/automation/provision.sh"

# --8<-- [start:checks]
omeka-s-cli core:status --is-ready
omeka-s-cli user:exists editor@example.org
omeka-s-cli module:list --json | jq -e 'map(select(.state == "active") | .id) | index("Log")'
omeka-s-cli site:list --json | jq -e '.[] | select(.slug == "collections")'
# --8<-- [end:checks]

test "$(omeka-s-cli core:status)" = installed
omeka-s-cli module:list --json | jq -e '[.[] | select(.state == "active") | .id] | index("Common") and index("Log")'
omeka-s-cli theme:list --json | jq -e 'map(.id) | index("freedom")'
omeka-s-cli user:exists editor@example.org
test "$(omeka-s-cli config:get installation_title)" = '"Digital Collections"'
omeka-s-cli site:list --json | jq -e '.[] | select(.slug == "collections")'
omeka-s-cli site:list-permissions collections --json | jq -e '.[] | select(.email == "editor@example.org" and .role == "editor")'
