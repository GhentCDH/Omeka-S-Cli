# Examples for docs/commands/users.md. Run by tests/docs-examples.sh on an installed Omeka S; the regions
# between the --8<-- markers are shown on the page.

# start clean, so the script can also run on an instance an earlier run left behind
for email in jane.doe@example.org john.roe@example.org cataloguer@example.org; do
    omeka-s-cli user:delete "$email" --ignore-not-found --quiet
done

# the passwords come from the environment, as they would in a real script
export JANE_PASSWORD='jane-initial-password' NEW_PASSWORD='jane-new-password' CATALOGUER_PASSWORD='cataloguer-password'

# does the stored hash of a user's password match a plain password?
password_is() {
    local hash
    hash=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -N \
        -e "SELECT password_hash FROM user WHERE email = '$1'")
    php -r 'exit(password_verify($argv[1], $argv[2]) ? 0 : 1);' "$2" "$hash"
}

# --8<-- [start:add]
omeka-s-cli user:add jane.doe@example.org "Jane Doe" editor "$JANE_PASSWORD"
# --8<-- [end:add]
password_is jane.doe@example.org "$JANE_PASSWORD"

# --8<-- [start:add-inactive]
omeka-s-cli user:add john.roe@example.org "John Roe" researcher --inactive
# --8<-- [end:add-inactive]
omeka-s-cli user:list --json | jq -e '.[] | select(.email == "john.roe@example.org") | .is_active == false and .role == "researcher"'

# a second add of the same email fails ...
! omeka-s-cli user:add jane.doe@example.org "Jane Doe" editor "$JANE_PASSWORD" || exit 1

# --8<-- [start:ignore-existing]
omeka-s-cli user:add jane.doe@example.org "Jane Doe" editor "$JANE_PASSWORD" --ignore-existing
# --8<-- [end:ignore-existing]

# --8<-- [start:exists]
if ! omeka-s-cli user:exists cataloguer@example.org --quiet; then
    omeka-s-cli user:add cataloguer@example.org "Cataloguer" author "$CATALOGUER_PASSWORD"
fi
# --8<-- [end:exists]
omeka-s-cli user:exists cataloguer@example.org --quiet
! omeka-s-cli user:exists nobody@example.org --quiet || exit 1

# --8<-- [start:list]
omeka-s-cli user:list
# --8<-- [end:list]

# --8<-- [start:list-json]
omeka-s-cli user:list --json | jq -r '.[] | select(.role == "global_admin") | .email'
# --8<-- [end:list-json]
test "$(omeka-s-cli user:list --json | jq -r '.[] | select(.role == "global_admin") | .email')" = admin@example.org

# --8<-- [start:update]
omeka-s-cli user:update jane.doe@example.org --role site_admin --name "Jane A. Doe"
# --8<-- [end:update]
omeka-s-cli user:list --json | jq -e '.[] | select(.email == "jane.doe@example.org")
    | .role == "site_admin" and .display_name == "Jane A. Doe" and .is_active == true'
# the password is not touched by an update
password_is jane.doe@example.org "$JANE_PASSWORD"

# --8<-- [start:update-password]
omeka-s-cli user:update-password jane.doe@example.org "$NEW_PASSWORD"
# --8<-- [end:update-password]
password_is jane.doe@example.org "$NEW_PASSWORD"

# --8<-- [start:enable-disable]
omeka-s-cli user:enable john.roe@example.org
omeka-s-cli user:disable cataloguer@example.org
# --8<-- [end:enable-disable]
omeka-s-cli user:list --json | jq -e '[.[] | select(.email == "john.roe@example.org" or .email == "cataloguer@example.org")
    | {(.email): .is_active}] | add == {"john.roe@example.org": true, "cataloguer@example.org": false}'
# a second disable only warns
omeka-s-cli user:disable cataloguer@example.org --quiet

# --8<-- [start:create-api-key]
key=$(omeka-s-cli user:create-api-key jane.doe@example.org importer --json)
KEY_IDENTITY=$(jq -r '.key_id' <<< "$key")
KEY_CREDENTIAL=$(jq -r '.key_credential' <<< "$key")
# --8<-- [end:create-api-key]
[[ ${#KEY_IDENTITY} -eq 32 && ${#KEY_CREDENTIAL} -eq 32 ]]
! omeka-s-cli user:create-api-key jane.doe@example.org importer || exit 1

# --8<-- [start:list-api-keys]
omeka-s-cli user:list-api-keys jane.doe@example.org
# --8<-- [end:list-api-keys]
omeka-s-cli user:list-api-keys jane.doe@example.org --json | jq -e --arg id "$KEY_IDENTITY" \
    '.[] | select(.key_label == "importer") | .key_id == $id'

# --8<-- [start:delete-api-key]
omeka-s-cli user:delete-api-key jane.doe@example.org importer
# --8<-- [end:delete-api-key]
omeka-s-cli user:list-api-keys jane.doe@example.org --json | jq -e 'length == 0'

# --8<-- [start:delete]
omeka-s-cli user:delete john.roe@example.org
omeka-s-cli user:delete cataloguer@example.org --ignore-not-found
# --8<-- [end:delete]
! omeka-s-cli user:exists john.roe@example.org --quiet || exit 1
! omeka-s-cli user:delete john.roe@example.org || exit 1
omeka-s-cli user:delete john.roe@example.org --ignore-not-found

# --8<-- [start:by-id]
id=$(omeka-s-cli user:list --json | jq -r '.[] | select(.email == "jane.doe@example.org") | .id')
omeka-s-cli user:disable "$id"
# --8<-- [end:by-id]
omeka-s-cli user:list --json | jq -e '.[] | select(.email == "jane.doe@example.org") | .is_active == false'

# clean up
omeka-s-cli user:delete jane.doe@example.org --quiet
