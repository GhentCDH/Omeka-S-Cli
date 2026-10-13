# Examples for docs/commands/dummy-data.md. Run by tests/docs-examples.sh in the Omeka S directory of
# an installed instance; the regions between the --8<-- markers are shown on the page.

# the config files the page shows, in the Omeka S directory where the commands run
cp "$EXAMPLES_DIR"/files/dummy/*.json .
trap 'rm -f "$OMEKA_DIR"/{items,item-sets,populate-item-sets}.json' EXIT

# the checks count rows in the database: the instance may hold resources of other examples
db() { mysql -u "$DB_USER" -p"$DB_PASSWORD" -h "$DB_HOST" -N -B "$DB_NAME" -e "$1" 2> /dev/null; }
last_id() { db "SELECT COALESCE(MAX(id), 0) FROM resource"; }

before=$(last_id)
# --8<-- [start:items]
omeka-s-cli dummy:create-items --number 10
# --8<-- [end:items]
test "$(db "SELECT COUNT(*) FROM item WHERE id > $before")" = 10
# every item has a title and a description
test "$(db "SELECT v.resource_id FROM value v JOIN property p ON p.id = v.property_id
    WHERE v.resource_id > $before AND p.local_name IN ('title', 'description')
    GROUP BY v.resource_id HAVING COUNT(DISTINCT p.local_name) = 2" | wc -l)" = 10

before=$(last_id)
# --8<-- [start:item-sets]
omeka-s-cli dummy:create-item-sets -n 5
# --8<-- [end:item-sets]
test "$(db "SELECT COUNT(*) FROM item_set WHERE id > $before")" = 5

before=$(last_id)
# --8<-- [start:items-config]
omeka-s-cli dummy:create-items -n 10 --config items.json
# --8<-- [end:items-config]
test "$(db "SELECT COUNT(*) FROM item WHERE id > $before")" = 10
# two subjects, a CC0 rights statement and a class on each
test "$(db "SELECT COUNT(*) FROM value v JOIN property p ON p.id = v.property_id
    WHERE v.resource_id > $before AND p.local_name = 'subject'")" = 20
test "$(db "SELECT COUNT(*) FROM value WHERE resource_id > $before AND type = 'uri'
    AND uri = 'https://creativecommons.org/publicdomain/zero/1.0/'")" = 10
test "$(db "SELECT COUNT(*) FROM resource r JOIN resource_class c ON c.id = r.resource_class_id
    WHERE r.id > $before AND r.resource_type LIKE '%Item' AND c.local_name IN ('Text', 'StillImage')")" = 10

before=$(last_id)
# --8<-- [start:item-sets-config]
omeka-s-cli dummy:create-item-sets -n 5 --config item-sets.json
# --8<-- [end:item-sets-config]
test "$(db "SELECT COUNT(*) FROM resource WHERE id > $before AND resource_type LIKE '%ItemSet' AND is_public = 0")" = 5

before=$(last_id)
# --8<-- [start:populate]
omeka-s-cli dummy:create-items -n 10 --config populate-item-sets.json
# --8<-- [end:populate]
test "$(db "SELECT COUNT(*) FROM item WHERE id > $before")" = 10
# each new item is in one or two item sets, and links to an existing item
test "$(db "SELECT COUNT(*) FROM (SELECT item_id, COUNT(*) n FROM item_item_set WHERE item_id > $before
    GROUP BY item_id HAVING n BETWEEN 1 AND 2) x")" = 10
test "$(db "SELECT COUNT(*) FROM value WHERE resource_id > $before AND type = 'resource'
    AND value_resource_id <= $before")" = 10

# an item set config may not assign item sets
! omeka-s-cli dummy:create-item-sets --config populate-item-sets.json || exit 1
# item_set needs at least `min` item sets to choose from
echo '{"o:item_set": {"generator": "item_set", "min": 100000, "max": 100000}}' > "$SCRATCH_DIR/too-many.json"
! omeka-s-cli dummy:create-items --config "$SCRATCH_DIR/too-many.json" > "$SCRATCH_DIR/out" 2>&1 || exit 1
grep -q 'requires at least 100000 item sets' "$SCRATCH_DIR/out"
