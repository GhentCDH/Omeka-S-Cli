# Examples for docs/commands/custom-vocabularies.md. Run by tests/docs-examples.sh in the Omeka S
# directory of an installed instance; the regions between the --8<-- markers are shown on the page.

FILES="$EXAMPLES_DIR/files/custom-vocabularies"
# the files the page shows, in the Omeka S directory where the commands run
cp "$FILES"/*.json .
trap 'rm -f "$OMEKA_DIR"/{conditions,languages,collections}.json' EXIT
db() { mysql -u "$DB_USER" -p"$DB_PASSWORD" -h "$DB_HOST" -N -B "$DB_NAME" -e "$1" 2> /dev/null; }

# --8<-- [start:module]
omeka-s-cli module:download customvocab --install
# --8<-- [end:module]
omeka-s-cli module:install customvocab --quiet
omeka-s-cli module:list --json | jq -e '.[] | select(.id == "CustomVocab" and .state == "active")' > /dev/null

# start from no vocabularies with the labels used below
for label in Condition "Object condition" Language Collection; do
    omeka-s-cli custom-vocabulary:delete "$label" --ignore-not-found --quiet
done

# --8<-- [start:import-terms]
omeka-s-cli custom-vocabulary:import conditions.json
# --8<-- [end:import-terms]
# --8<-- [start:import-uris]
omeka-s-cli custom-vocabulary:import languages.json
# --8<-- [end:import-uris]
omeka-s-cli custom-vocabulary:list --json \
    | jq -e 'map({(.label): .type}) | add | .Condition == "literal" and .Language == "uri"' > /dev/null

# importing a label that exists is skipped with a warning (exit code 0)
omeka-s-cli custom-vocabulary:import conditions.json 2>&1 | grep -q 'already exists'
# --8<-- [start:import-update]
omeka-s-cli custom-vocabulary:import conditions.json --update
# --8<-- [end:import-update]

# the item set the Collection vocabulary refers to (created once)
if [[ "$(db "SELECT COUNT(*) FROM value v JOIN property p ON p.id = v.property_id
        WHERE p.local_name = 'identifier' AND v.value = 'urn:example:item-set:photographs'")" == 0 ]]; then
    cat > "$SCRATCH_DIR/item-set.json" <<'JSON'
{
  "dcterms:title": [{ "generator": "literal", "mode": "values", "values": ["Photographs"] }],
  "dcterms:identifier": [{ "generator": "literal", "mode": "values", "values": ["urn:example:item-set:photographs"] }]
}
JSON
    omeka-s-cli dummy:create-item-sets --config "$SCRATCH_DIR/item-set.json" --quiet
fi

# --8<-- [start:import-item-set]
omeka-s-cli custom-vocabulary:import collections.json
# --8<-- [end:import-item-set]
omeka-s-cli custom-vocabulary:list --json | jq -e '.[] | select(.label == "Collection" and .type == "resource")' > /dev/null

# --8<-- [start:list]
omeka-s-cli custom-vocabulary:list
omeka-s-cli custom-vocabulary:list --json | jq -r '.[].label'
# --8<-- [end:list]

# --8<-- [start:export]
omeka-s-cli custom-vocabulary:export Condition
omeka-s-cli custom-vocabulary:export Language "$SCRATCH_DIR/languages.json"
# --8<-- [end:export]
jq -e '."o:uris" | length == 3' "$SCRATCH_DIR/languages.json" > /dev/null

# --8<-- [start:export-item-set]
omeka-s-cli custom-vocabulary:export Collection
# --8<-- [end:export-item-set]
omeka-s-cli custom-vocabulary:export Collection | jq -e '."o:item_set".value == "urn:example:item-set:photographs"' > /dev/null

# --8<-- [start:export-item-set-property]
omeka-s-cli custom-vocabulary:export Collection --item-set-property dcterms:title
# --8<-- [end:export-item-set-property]
omeka-s-cli custom-vocabulary:export Collection --item-set-property dcterms:title \
    | jq -e '."o:item_set" == {"property": "dcterms:title", "value": "Photographs", "o:title": "Photographs"}' > /dev/null
! omeka-s-cli custom-vocabulary:export Collection --item-set-property dcterms:subject || exit 1

# --8<-- [start:import-identifier]
omeka-s-cli custom-vocabulary:import conditions.json Condition --label "Object condition"
# --8<-- [end:import-identifier]
omeka-s-cli custom-vocabulary:list --json | jq -e 'any(.label == "Object condition") and all(.label != "Condition")' > /dev/null

# --8<-- [start:delete]
omeka-s-cli custom-vocabulary:delete "Object condition"

id=$(omeka-s-cli custom-vocabulary:list --json | jq -r '.[] | select(.label == "Language") | .id')
omeka-s-cli custom-vocabulary:delete "$id"
# --8<-- [end:delete]
! omeka-s-cli custom-vocabulary:delete Language || exit 1

# --8<-- [start:delete-ignore]
omeka-s-cli custom-vocabulary:delete Collection --ignore-not-found
omeka-s-cli custom-vocabulary:delete Collection --ignore-not-found
# --8<-- [end:delete-ignore]
test "$(omeka-s-cli custom-vocabulary:list --json | jq '[.[] | select(.label | IN("Object condition", "Language", "Collection"))] | length')" = 0
