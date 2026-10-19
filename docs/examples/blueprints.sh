# Examples for docs/commands/blueprints.md (and docs/automation/blueprints.md). Run by
# tests/docs-examples.sh on an installed Omeka S; the regions between the --8<-- markers are shown on
# the pages. The blueprints are the files in docs/examples/files/blueprints, reached as ~/blueprints.

# a private home: ~/blueprints is the example directory, the CLI's download cache is shared
CACHE_DIR="$HOME/.cache/omeka-s-cli"
mkdir -p "$CACHE_DIR"
export HOME="$SCRATCH_DIR"
mkdir -p ~/.cache
ln -sfn "$CACHE_DIR" ~/.cache/omeka-s-cli
ln -sfn "$EXAMPLES_DIR/files/blueprints" ~/blueprints

# the output of a command that must fail
failure_output() { if "$@" > "$SCRATCH_DIR/failure.log" 2>&1; then return 1; fi; cat "$SCRATCH_DIR/failure.log"; }

# --8<-- [start:validate]
omeka-s-cli blueprint:validate ~/blueprints/site.blueprint.jsonc
# --8<-- [end:validate]

# --8<-- [start:validate-invalid]
if ! omeka-s-cli blueprint:validate ~/blueprints/invalid.blueprint.jsonc; then
    echo "Not deploying an invalid blueprint." >&2
fi
# --8<-- [end:validate-invalid]
failure_output omeka-s-cli blueprint:validate ~/blueprints/invalid.blueprint.jsonc | grep 'Additional object properties are not allowed: stat' > /dev/null

# --8<-- [start:validate-json]
omeka-s-cli blueprint:validate ~/blueprints/site.blueprint.jsonc --json
# --8<-- [end:validate-json]
omeka-s-cli blueprint:validate ~/blueprints/site.blueprint.jsonc --json | jq -e '.valid and (.errors | length == 0)'
! omeka-s-cli blueprint:validate ~/blueprints/invalid.blueprint.jsonc --json > "$SCRATCH_DIR/invalid.json" 2> /dev/null || exit 1
jq -e '.valid == false and (.errors | length == 4)' "$SCRATCH_DIR/invalid.json"

# --8<-- [start:validate-partial]
omeka-s-cli blueprint:validate ~/blueprints/shared/modules.jsonc --as modules
omeka-s-cli blueprint:validate ~/blueprints/shared/settings.json --as settings
# --8<-- [end:validate-partial]

# --8<-- [start:validate-root]
omeka-s-cli blueprint:validate ~/blueprints/library/library.blueprint.jsonc --root ~/blueprints
# --8<-- [end:validate-root]
failure_output omeka-s-cli blueprint:validate ~/blueprints/library/library.blueprint.jsonc \
    | grep 'resolves outside the blueprint root' > /dev/null
omeka-s-cli blueprint:validate ~/blueprints/library/library.blueprint.jsonc --root ~/blueprints 2>&1 \
    | grep "'Sharing' is declared more than once" > /dev/null

# --8<-- [start:dry-run]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core --dry-run
# --8<-- [end:dry-run]
omeka-s-cli site:list --json | jq -e 'all(.[]; .slug != "collections")'

# an installed instance needs --force, also with --skip core
failure_output omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core | grep 'Pass --force' > /dev/null

# --8<-- [start:deploy]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core --force
# --8<-- [end:deploy]
omeka-s-cli module:list --json | jq -e '[.[] | select(.state == "active") | .id] | index("Common") and index("Sharing")'
omeka-s-cli vocabulary:list --json | jq -e 'any(.[]; .prefix == "coll")'
omeka-s-cli resource-template:list --json | jq -e 'any(.[]; .label == "Book")'
omeka-s-cli user:list --json | jq -e 'any(.[]; .email == "editor@example.org")'
omeka-s-cli site:list --json | jq -e 'any(.[]; .slug == "collections" and .is_default)'
omeka-s-cli site:list-permissions collections --json \
    | jq -e 'any(.[]; .email == "editor@example.org" and .role == "editor")'
test "$(omeka-s-cli config:get pagination_per_page)" = 50

# a second run changes nothing: existing resources are skipped
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core --force > "$SCRATCH_DIR/redeploy.log" 2>&1
grep -q "site 'collections' already exists" "$SCRATCH_DIR/redeploy.log"
test "$(omeka-s-cli resource-template:list --json | jq '[.[] | select(.label == "Book")] | length')" = 1

# someone made the site private by hand; --update brings it back in line
omeka-s-cli site:update collections --private --quiet
# --8<-- [start:update]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core --force --update
# --8<-- [end:update]
omeka-s-cli site:list --json | jq -e 'any(.[]; .slug == "collections" and .is_public)'

# a setting changed by hand; re-applying without the module and theme phases restores it
omeka-s-cli config:set pagination_per_page 25 --quiet
# --8<-- [start:skip]
omeka-s-cli blueprint:deploy ~/blueprints/site.blueprint.jsonc --skip core,modules,themes --force
# --8<-- [end:skip]
test "$(omeka-s-cli config:get pagination_per_page)" = 50

# --8<-- [start:export]
omeka-s-cli blueprint:export ~/snapshot.blueprint.jsonc
omeka-s-cli blueprint:validate ~/snapshot.blueprint.jsonc
# --8<-- [end:export]
grep -v '^//' ~/snapshot.blueprint.jsonc | jq -e '
    any(.modules[]; .name == "Sharing" and .state == "activate")
    and any(.sites[]; .slug == "collections")
    and (has("users") | not)'

# --8<-- [start:export-permissions]
omeka-s-cli blueprint:export --include site-permissions
# --8<-- [end:export-permissions]
omeka-s-cli blueprint:export --include site-permissions | grep -v '^//' | jq -e '
    any(.users[]; .email == "editor@example.org" and (has("password") | not))
    and any(.sites[] | select(.slug == "collections") | .permissions[]; .user == "editor@example.org" and .role == "editor")'
! omeka-s-cli blueprint:export --include settings 2> /dev/null || exit 1

# --8<-- [start:deploy-content]
omeka-s-cli blueprint:deploy ~/blueprints/content.blueprint.jsonc --skip core --force
# --8<-- [end:deploy-content]
omeka-s-cli item:list --json | jq -e 'any(.[]; .title == "Harbour at dawn" and .media == 2 and .itemSets == 1)
    and any(.[]; .title == "Market square" and .media == 1 and .itemSets == 1)' > /dev/null
# a second deploy skips what exists
omeka-s-cli blueprint:deploy ~/blueprints/content.blueprint.jsonc --skip core --force 2>&1 | grep -q "Item 'Harbour at dawn' already exists"

# --8<-- [start:export-content]
omeka-s-cli item:export --property dcterms:identifier=ph-1 --reference-by dcterms:identifier \
    --output-dir ~/content/items
# --8<-- [end:export-content]
ls ~/content/items/*.jsonld > /dev/null

# --8<-- [start:bundle]
omeka-s-cli blueprint:bundle ~/blueprints/site.blueprint.jsonc ~/bundle
# --8<-- [end:bundle]
jq -e '[.. | objects | .source? // empty | select(startswith("./") | not)] | length == 0' ~/bundle/blueprint.json
jq -e '."x-omeka-s-cli".install.source | startswith("./core/omeka-s-")' ~/bundle/blueprint.json

# --8<-- [start:validate-self-contained]
omeka-s-cli blueprint:validate ~/bundle/blueprint.json --self-contained
# --8<-- [end:validate-self-contained]
failure_output omeka-s-cli blueprint:validate ~/blueprints/site.blueprint.jsonc --self-contained | grep 'has no source' > /dev/null

# --8<-- [start:deploy-bundle]
omeka-s-cli blueprint:deploy ~/bundle/blueprint.json --self-contained --skip core --force
# --8<-- [end:deploy-bundle]
omeka-s-cli site:list --json | jq -e 'any(.[]; .slug == "collections")'

# the Dockerfile on the automation page runs these same commands
grep -q 'omeka-s-cli blueprint:bundle /src/site.blueprint.jsonc /opt/bundle' "$EXAMPLES_DIR/files/blueprints/Dockerfile.bundle"
grep -q 'omeka-s-cli blueprint:deploy /opt/bundle/blueprint.json --self-contained' "$EXAMPLES_DIR/files/blueprints/Dockerfile.bundle"
# a restart must not run the core phase again: it refuses an installed instance (and --force would wipe it)
grep -q 'omeka-s-cli core:status --is-installed --base-path /var/www/omeka-s ||' "$EXAMPLES_DIR/files/blueprints/Dockerfile.bundle"

# leave the shared instance as it was (the modules stay)
omeka-s-cli site:delete collections --quiet
omeka-s-cli resource-template:delete Book --quiet
omeka-s-cli vocabulary:delete coll --quiet
omeka-s-cli user:delete editor@example.org --quiet
