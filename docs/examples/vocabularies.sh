# Examples for docs/commands/vocabularies.md. Run by tests/docs-examples.sh on an installed Omeka S; the
# regions between the --8<-- markers are shown on the page.

# the page keeps its example files in ~/vocabularies: point HOME to the scratch directory
export HOME="$SCRATCH_DIR"
mkdir -p ~/vocabularies
cp "$EXAMPLES_DIR"/files/vocabularies/archive.ttl ~/vocabularies/
# start without the vocabularies this script imports (it may run after other scripts)
for prefix in arch skos event; do omeka-s-cli vocabulary:delete "$prefix" --ignore-not-found --quiet; done

# --8<-- [start:list]
omeka-s-cli vocabulary:list
# --8<-- [end:list]

# --8<-- [start:list-json]
omeka-s-cli vocabulary:list --json | jq -r '.[] | "\(.prefix)\t\(.namespaceUri)"'
# --8<-- [end:list-json]
omeka-s-cli vocabulary:list --json | jq -e 'any(.[]; .prefix == "dcterms")' > /dev/null

# --8<-- [start:import-file]
omeka-s-cli vocabulary:import \
    --source ~/vocabularies/archive.ttl \
    --namespace-uri "https://example.org/archive#" \
    --prefix arch \
    --label "Archive" \
    --format turtle
# --8<-- [end:import-file]
omeka-s-cli vocabulary:list --json | jq -e '.[] | select(.prefix == "arch") | .propertyCount == 2 and .resourcesClassCount == 1' > /dev/null

# --8<-- [start:import-url]
omeka-s-cli vocabulary:import \
    --source https://www.w3.org/TR/skos-reference/skos.rdf \
    --namespace-uri "http://www.w3.org/2004/02/skos/core#" \
    --prefix skos \
    --label "SKOS"
# --8<-- [end:import-url]
omeka-s-cli vocabulary:list --json | jq -e 'any(.[]; .prefix == "skos")' > /dev/null

# importing an existing namespace again is a no-op with a notice (exit code 0)
omeka-s-cli vocabulary:import --source ~/vocabularies/archive.ttl --namespace-uri "https://example.org/archive#" \
    --prefix arch --label "Archive" 2>&1 | grep -q -- '--update'

# --8<-- [start:create-config]
cd ~/vocabularies
omeka-s-cli vocabulary:create-import-config \
    --source archive.ttl \
    --namespace-uri "https://example.org/archive#" \
    --prefix arch \
    --label "Archive" \
    --comment "Classes and properties for the letters collection." \
    --format turtle \
    --output archive.json
# --8<-- [end:create-config]
# the generated file is the one shown on the page
diff ~/vocabularies/archive.json "$EXAMPLES_DIR/files/vocabularies/archive.json"
cd "$OMEKA_DIR"

omeka-s-cli vocabulary:delete arch --quiet
# --8<-- [start:import-config]
omeka-s-cli vocabulary:import --config ~/vocabularies/archive.json
# --8<-- [end:import-config]
omeka-s-cli vocabulary:list --json | jq -e '.[] | select(.prefix == "arch") | .label == "Archive"' > /dev/null

# the vocabulary gets a new property
cat >> ~/vocabularies/archive.ttl <<'EOF'

arch:dateSent a rdf:Property ;
    rdfs:label "date sent" ;
    rdfs:comment "The date the letter was sent." .
EOF
# --8<-- [start:update]
omeka-s-cli vocabulary:import --config ~/vocabularies/archive.json --update
# --8<-- [end:update]
omeka-s-cli vocabulary:list --json | jq -e '.[] | select(.prefix == "arch") | .propertyCount == 3' > /dev/null

# --8<-- [start:search]
omeka-s-cli vocabulary:search event --repository lov
# --8<-- [end:search]
omeka-s-cli vocabulary:search event --json | jq -e 'any(.[]; .id == "lov:event")' > /dev/null

# --8<-- [start:import-from-repo]
omeka-s-cli vocabulary:import-from-repo lov:event
# --8<-- [end:import-from-repo]
omeka-s-cli vocabulary:list --json | jq -e 'any(.[]; .prefix == "event")' > /dev/null

# --8<-- [start:import-from-repo-prefix]
omeka-s-cli vocabulary:import-from-repo event --prefix --update
# --8<-- [end:import-from-repo-prefix]

# --8<-- [start:delete]
omeka-s-cli vocabulary:delete event
omeka-s-cli vocabulary:delete skos --ignore-not-found
# --8<-- [end:delete]
! omeka-s-cli vocabulary:list --json | jq -e 'any(.[]; .prefix == "event" or .prefix == "skos")' > /dev/null || exit 1

# the built-in vocabularies cannot be deleted
! omeka-s-cli vocabulary:delete dcterms > /dev/null 2>&1 || exit 1

omeka-s-cli vocabulary:delete arch --quiet
