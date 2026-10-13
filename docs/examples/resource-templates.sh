# Examples for docs/commands/resource-templates.md. Run by tests/docs-examples.sh on an installed Omeka S;
# the regions between the --8<-- markers are shown on the page.

# the page keeps its example files in ~/templates and ~/vocabularies: point HOME to the scratch directory
export HOME="$SCRATCH_DIR"
mkdir -p ~/templates ~/vocabularies
cp "$EXAMPLES_DIR"/files/resource-templates/*.json ~/templates/
cp "$EXAMPLES_DIR"/files/vocabularies/archive.{json,ttl} ~/vocabularies/
# start without the templates and the vocabulary this script creates (it may run after other scripts)
for label in "Letter" "Letter (incoming)" "Incoming letter" "Archive letter" "Archive letter (partial)"; do
    omeka-s-cli resource-template:delete "$label" --ignore-not-found --quiet
done
omeka-s-cli vocabulary:delete arch --ignore-not-found --quiet

# --8<-- [start:common]
omeka-s-cli module:download common --install
# --8<-- [end:common]
# also when Common was already downloaded, but not installed or not enabled
omeka-s-cli module:install Common --quiet
omeka-s-cli module:enable Common --quiet
omeka-s-cli module:status Common --json | jq -e '.[0].state == "active"' > /dev/null

# --8<-- [start:list]
omeka-s-cli resource-template:list
# --8<-- [end:list]

# --8<-- [start:list-json]
omeka-s-cli resource-template:list --json | jq -r '.[].label'
# --8<-- [end:list-json]
omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Base Resource")' > /dev/null

# --8<-- [start:import]
omeka-s-cli resource-template:import ~/templates/letter.json
# --8<-- [end:import]
omeka-s-cli resource-template:list --json | jq -e '.[] | select(.label == "Letter") | .propertyCount == 5 and .class == "Letter"' > /dev/null

# --8<-- [start:import-label]
omeka-s-cli resource-template:import ~/templates/letter.json --label "Letter (incoming)"
# --8<-- [end:import-label]
omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Letter (incoming)")' > /dev/null

# importing a template whose label exists is a no-op with a notice (exit code 0)
omeka-s-cli resource-template:import ~/templates/letter.json 2>&1 | grep -q -- '--update'

# the template gets a description property
jq '."o:resource_template_property" += [."o:resource_template_property"[0]
    | .local_name = "description" | .label = "Description" | ."o:is_required" = false]' \
    ~/templates/letter.json > ~/templates/letter.json.new
mv ~/templates/letter.json.new ~/templates/letter.json
# --8<-- [start:update]
omeka-s-cli resource-template:import ~/templates/letter.json --update
# --8<-- [end:update]
omeka-s-cli resource-template:list --json | jq -e '.[] | select(.label == "Letter") | .propertyCount == 6' > /dev/null

# --8<-- [start:update-identifier]
omeka-s-cli resource-template:import ~/templates/letter.json "Letter (incoming)" --label "Incoming letter"
# --8<-- [end:update-identifier]
omeka-s-cli resource-template:list --json | jq -e '.[] | select(.label == "Incoming letter") | .propertyCount == 6' > /dev/null
! omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Letter (incoming)")' > /dev/null || exit 1

# --8<-- [start:missing-dependencies]
if ! omeka-s-cli resource-template:import ~/templates/archive-letter.json; then
    echo "Import the missing vocabulary first." >&2
fi
# --8<-- [end:missing-dependencies]
! omeka-s-cli resource-template:import ~/templates/archive-letter.json > /dev/null 2>&1 || exit 1
! omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Archive letter")' > /dev/null || exit 1

# --8<-- [start:ignore-deps]
omeka-s-cli resource-template:import ~/templates/archive-letter.json --ignore-deps --label "Archive letter (partial)"
# --8<-- [end:ignore-deps]
# the class and the two properties of the missing vocabulary are left out
omeka-s-cli resource-template:export "Archive letter (partial)" \
    | jq -e '."o:resource_class" == null and ([."o:resource_template_property"[].local_name] == ["title", "date"])' > /dev/null

# --8<-- [start:fix-dependencies]
omeka-s-cli vocabulary:import --config ~/vocabularies/archive.json
omeka-s-cli resource-template:import ~/templates/archive-letter.json
# --8<-- [end:fix-dependencies]
omeka-s-cli resource-template:list --json | jq -e '.[] | select(.label == "Archive letter") | .propertyCount == 4' > /dev/null

# --8<-- [start:export-stdout]
omeka-s-cli resource-template:export "Letter" | jq -r '."o:resource_template_property"[].label'
# --8<-- [end:export-stdout]

# --8<-- [start:export-file]
mkdir -p ~/backup
omeka-s-cli resource-template:export "Letter" ~/backup/letter.json
# --8<-- [end:export-file]
jq -e '."o:label" == "Letter" and (."o:resource_template_property" | length) == 6' ~/backup/letter.json > /dev/null

# --8<-- [start:export-all]
omeka-s-cli resource-template:list --json | jq -r '.[].id' | while read -r id; do
    omeka-s-cli resource-template:export "$id" ~/backup/resource-template-"$id".json
done
# --8<-- [end:export-all]
test "$(find ~/backup -name 'resource-template-*.json' | wc -l)" -eq "$(omeka-s-cli resource-template:list --json | jq length)"

# --8<-- [start:delete]
omeka-s-cli resource-template:delete "Incoming letter"
omeka-s-cli resource-template:delete "Letter (draft)" --ignore-not-found
# --8<-- [end:delete]
! omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Incoming letter")' > /dev/null || exit 1

# leave the instance as the script found it
for label in "Letter" "Archive letter" "Archive letter (partial)"; do
    omeka-s-cli resource-template:delete "$label" --quiet
done
omeka-s-cli vocabulary:delete arch --quiet
