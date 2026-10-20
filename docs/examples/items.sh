# Examples for docs/commands/items.md. Run by tests/docs-examples.sh on an installed Omeka S;
# the regions between the --8<-- markers are shown on the page.

# the page keeps its example files in ~/content: point HOME to the scratch directory
export HOME="$SCRATCH_DIR"
mkdir -p ~/content
cp "$EXAMPLES_DIR"/files/items/* ~/content/
# imports skip what already exists, so the script also passes when an earlier run created the content

# --8<-- [start:import-item-set]
omeka-s-cli item-set:import ~/content/paintings.jsonld
# --8<-- [end:import-item-set]
omeka-s-cli item-set:list --json | jq -e 'any(.[]; .title == "Paintings")' > /dev/null

# --8<-- [start:import]
omeka-s-cli item:import ~/content/mona-lisa.jsonld --media "upload=$HOME/content/mona-lisa.png"
# --8<-- [end:import]
omeka-s-cli item:list --property dcterms:identifier=obj-1 --json \
    | jq -e '.[0].title == "Mona Lisa" and .[0].media >= 1 and .[0].itemSets == 1' > /dev/null

# --8<-- [start:import-media-options]
omeka-s-cli item:import ~/content/lecture.jsonld \
    --media 'youtube=https://www.youtube.com/watch?v=dQw4w9WgXcQ;start=30;title=Recording'
# --8<-- [end:import-media-options]
omeka-s-cli item:list --property dcterms:identifier=obj-4 --json | jq -e '.[0].media >= 1' > /dev/null

omeka-s-cli site:add Collections --slug collections --ignore-existing --quiet
# --8<-- [start:import-site]
omeka-s-cli item:import ~/content/sketchbook.jsonld --site collections
# --8<-- [end:import-site]
omeka-s-cli item:list --site collections --property dcterms:identifier=obj-5 --json | jq -e 'length == 1' > /dev/null

# --8<-- [start:import-config]
omeka-s-cli item:import --config ~/content/portrait.import.json
# --8<-- [end:import-config]
omeka-s-cli item:list --site collections --property dcterms:identifier=obj-6 --json | jq -e '.[0].media == 2' > /dev/null

# --8<-- [start:import-config-title-only]
omeka-s-cli item:import --config ~/content/notebook.import.json
# --8<-- [end:import-config-title-only]
omeka-s-cli item:list --json | jq -e 'any(.[]; .title == "Notebook page" and .media == 1 and .itemSets == 1)' > /dev/null

# --8<-- [start:import-linked]
omeka-s-cli item:import ~/content/study.jsonld
# --8<-- [end:import-linked]

# --8<-- [start:import-again]
omeka-s-cli item:import ~/content/mona-lisa.jsonld --dry-run
# --8<-- [end:import-again]
omeka-s-cli item:import ~/content/mona-lisa.jsonld --dry-run --json | jq -e '.status == "exists"' > /dev/null

# --8<-- [start:list]
omeka-s-cli item:list
# --8<-- [end:list]

# --8<-- [start:list-filter]
omeka-s-cli item:list --class dctype:StillImage --property "dcterms:creator=Leonardo da Vinci"
# --8<-- [end:list-filter]
omeka-s-cli item:list --class dctype:StillImage --property "dcterms:creator=Leonardo da Vinci" --json \
    | jq -e 'any(.[]; .title == "Mona Lisa")' > /dev/null

# --8<-- [start:search]
omeka-s-cli item:search leonardo
# --8<-- [end:search]
omeka-s-cli item:search leonardo --json | jq -e 'any(.[]; .title == "Mona Lisa")' > /dev/null

# --8<-- [start:export]
id=$(omeka-s-cli item:list --property dcterms:identifier=obj-2 --json | jq '.[0].id')
omeka-s-cli item:export "$id" --reference-by dcterms:identifier
# --8<-- [end:export]
omeka-s-cli item:export "$id" --reference-by dcterms:identifier \
    | jq -e '."dcterms:relation"[0]."o-ref:ref"."dcterms:identifier" == "obj-1"' > /dev/null

rm -rf ~/export
# --8<-- [start:export-dir]
omeka-s-cli item:export --class dctype:StillImage --output-dir ~/export --reference-by dcterms:identifier
# --8<-- [end:export-dir]
ls ~/export/*-mona-lisa.jsonld > /dev/null

# --8<-- [start:export-item-sets]
omeka-s-cli item-set:export --output-dir ~/export/item-sets --reference-by dcterms:identifier
# --8<-- [end:export-item-sets]
ls ~/export/item-sets/*-paintings.jsonld > /dev/null

# --8<-- [start:missing-vocabulary]
if ! omeka-s-cli item:import ~/content/letter.jsonld; then
    echo "Install what is missing, then import again." >&2
fi
# --8<-- [end:missing-vocabulary]
{ omeka-s-cli item:import ~/content/letter.jsonld 2>&1 || true; } | grep -q 'uses vocabulary https://example.org/ns/letters#, which is not installed'

# --8<-- [start:ingesters]
omeka-s-cli media:ingesters
# --8<-- [end:ingesters]
omeka-s-cli media:ingesters --json | jq -e 'any(.[]; .name == "iiif")' > /dev/null

# one example per core ingester; the count shows each one added a media
media_count() { omeka-s-cli item:list --property dcterms:identifier=obj-2 --json | jq '.[0].media'; }
before=$(media_count)

# --8<-- [start:media-upload]
omeka-s-cli media:add "$id" --ingester upload --source ~/content/mona-lisa.png --title "Scan"
# --8<-- [end:media-upload]

# --8<-- [start:media-url]
omeka-s-cli media:add "$id" --ingester url \
    --source https://iiif.io/api/image/3.0/example/reference/918ecd18c2592080851777620de9bcb5-gottingen/full/max/0/default.jpg
# --8<-- [end:media-url]

# --8<-- [start:media-html]
omeka-s-cli media:add "$id" --ingester html --source '<p>Preparatory drawing, about 1503.</p>'
# --8<-- [end:media-html]

# --8<-- [start:media-iiif]
omeka-s-cli media:add "$id" --ingester iiif \
    --source https://iiif.io/api/image/3.0/example/reference/918ecd18c2592080851777620de9bcb5-gottingen/info.json
# --8<-- [end:media-iiif]

# --8<-- [start:media-iiif-presentation]
omeka-s-cli media:add "$id" --ingester iiif_presentation \
    --source https://iiif.io/api/cookbook/recipe/0001-mvm-image/manifest.json
# --8<-- [end:media-iiif-presentation]

# --8<-- [start:media-oembed]
omeka-s-cli media:add "$id" --ingester oembed --source https://www.youtube.com/watch?v=dQw4w9WgXcQ
# --8<-- [end:media-oembed]

# --8<-- [start:media-youtube]
omeka-s-cli media:add "$id" --ingester youtube --source https://youtu.be/dQw4w9WgXcQ
# --8<-- [end:media-youtube]
test "$(media_count)" -eq $((before + 7))

# --8<-- [start:media-add-data]
omeka-s-cli media:add "$id" --data '{
    "ingester": "youtube",
    "source": "https://youtu.be/dQw4w9WgXcQ",
    "start": "30",
    "title": "Recording",
    "dcterms:description": "The first minute of the talk"
}'
# --8<-- [end:media-add-data]
omeka-s-cli media:add "$id" --data '{"ingester": "html", "source": "<p>x</p>", "start": "30"}' --json | jq -e '.id > 0' > /dev/null
