# Examples for docs/commands/code-snippets.md. Run by tests/docs-examples.sh in the Omeka S
# directory of an installed instance; the regions between the --8<-- markers are shown on the page.

# the file the page shows, in the Omeka S directory where the commands run
cp "$EXAMPLES_DIR/files/code-snippets/snippets.json" .
trap 'rm -f "$OMEKA_DIR/snippets.json"' EXIT

# --8<-- [start:module]
omeka-s-cli module:download https://github.com/ateeducacion/omeka-s-CodeSnippets/releases/download/1.0.1/CodeSnippets-1.0.1.zip --install
# --8<-- [end:module]
omeka-s-cli module:install CodeSnippets --quiet
omeka-s-cli module:list --json | jq -e '.[] | select(.id == "CodeSnippets" and .state == "active")' > /dev/null

# start without the snippet used below
for id in $(omeka-s-cli code-snippet:list --json | jq -r '.[] | select(.name == "Footer notice") | .id'); do
    omeka-s-cli code-snippet:delete "$id" --quiet
done

# --8<-- [start:import]
omeka-s-cli code-snippet:import snippets.json
# --8<-- [end:import]

# --8<-- [start:list]
omeka-s-cli code-snippet:list
id=$(omeka-s-cli code-snippet:list --json | jq -r '.[] | select(.name == "Footer notice") | .id')
# --8<-- [end:list]
test -n "$id"

# --8<-- [start:show]
omeka-s-cli code-snippet:show "$id"
# --8<-- [end:show]

# --8<-- [start:activate]
omeka-s-cli code-snippet:activate "$id"
omeka-s-cli code-snippet:deactivate "$id"
# --8<-- [end:activate]
omeka-s-cli code-snippet:show "$id" --json | jq -e '.active == false' > /dev/null

# --8<-- [start:export]
omeka-s-cli code-snippet:export > "$SCRATCH_DIR/snippets.json"
omeka-s-cli code-snippet:export "$id" --output "$SCRATCH_DIR/footer-notice.json"
# --8<-- [end:export]
jq -e '.format == "omeka-s-code-snippets" and (.snippets | length) == 1' "$SCRATCH_DIR/footer-notice.json" > /dev/null

# --8<-- [start:delete]
omeka-s-cli code-snippet:delete "$id"
# --8<-- [end:delete]
! omeka-s-cli code-snippet:show "$id" || exit 1

# --8<-- [start:delete-ignore]
omeka-s-cli code-snippet:delete "$id" --ignore-not-found
# --8<-- [end:delete-ignore]
