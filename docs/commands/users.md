# Users

The `user:*` commands manage Omeka S user accounts and their API keys: create and update accounts, set
passwords, enable or disable them, and hand out API keys to scripts and other applications.

A command that acts on an existing user takes its **ID or email address** (`2` or
`jane.doe@example.org`); the display name does not identify a user. `user:exists` is the exception: it
only takes an email address.

## Add a user

`user:add` takes the email address, the display name, the role and, optionally, the password:

```bash
--8<-- "docs/examples/users.sh:add"
```

The global roles of Omeka S are `global_admin`, `site_admin`, `editor`, `reviewer`, `author` and
`researcher`; modules can add their own (the Guest module adds `guest`, for example). An unknown role
is refused with the list of valid roles. Pass the password from an environment variable or a secret
store, so it does not end up in your shell history.

Without a password the account exists but cannot log in, until you set one with
[`user:update-password`](#change-a-password) or the user resets it by email. `--inactive` creates a
disabled account:

```bash
--8<-- "docs/examples/users.sh:add-inactive"
```

## Add users in a script that runs more than once

Adding an email address that already exists is an error. With `--ignore-existing`, `user:add` prints a
warning and exits with 0 instead, so a provisioning script can be re-run:

```bash
--8<-- "docs/examples/users.sh:ignore-existing"
```

An existing account is left as it is: its name, role and password are not updated. To make a
decision yourself, `user:exists` answers through its exit code: 0 when a user with that email address
exists, 1 when it does not.

```bash
--8<-- "docs/examples/users.sh:exists"
```

`user:exists` also prints a message on standard output; `--quiet` suppresses it.

## List users

`user:list` shows every user with their ID, name, email address, status and role:

```bash
--8<-- "docs/examples/users.sh:list"
```

```text
Found 4 user(s).
+----+--------------+------------------------+-----------+--------------+
| Id | Display Name | Email                  | Is Active | Role         |
+----+--------------+------------------------+-----------+--------------+
| 1  | Admin        | admin@example.org      | yes       | global_admin |
| 2  | Jane Doe     | jane.doe@example.org   | yes       | editor       |
| 3  | John Roe     | john.roe@example.org   | no        | researcher   |
| 4  | Cataloguer   | cataloguer@example.org | yes       | author       |
+----+--------------+------------------------+-----------+--------------+
```

`--csv` and `--json` give the same data in a form other tools can read. With `--json`, `jq` selects
what a script needs, for example the email addresses of all global administrators:

```bash
--8<-- "docs/examples/users.sh:list-json"
```

## Change a user

`user:update` changes the display name (`--name`), email address (`--email`) or role (`--role`) of a
user. Only the options you pass change; the rest of the account stays as it is:

```bash
--8<-- "docs/examples/users.sh:update"
```

`--activate` and `--deactivate` do the same as [`user:enable` and `user:disable`](#enable-or-disable-a-user).
Add `--json` to print the updated user.

## Change a password

`user:update-password` sets a new password, for example to reset a forgotten one or to rotate the
password of a service account:

```bash
--8<-- "docs/examples/users.sh:update-password"
```

## Enable or disable a user

A disabled user keeps their account, content and site permissions, but cannot log in.

```bash
--8<-- "docs/examples/users.sh:enable-disable"
```

Enabling an active user, or disabling a disabled one, only prints a warning. Like the other commands,
both also accept the user ID; here the ID is looked up from the email address first:

```bash
--8<-- "docs/examples/users.sh:by-id"
```

## Delete a user

```bash
--8<-- "docs/examples/users.sh:delete"
```

Deleting a user that does not exist is an error; with `--ignore-not-found` the command prints a note and
exits with 0. The resources the user created are kept, without an owner.

## Manage API keys

Scripts and applications that use the Omeka S REST API authenticate with an API key, which belongs to
a user and has that user's rights. `user:create-api-key` creates one under a label. The key consists of
an identity (`key_id`) and a credential (`key_credential`). Omeka S stores only a hash of the
credential, so it is shown once, at creation. In a script, capture it with `--json`:

```bash
--8<-- "docs/examples/users.sh:create-api-key"
```

A client then passes both values on every API request, as the `key_identity` and `key_credential`
query parameters. Store the credential in a secret store right away; if it is lost, delete the key and
create a new one. The label must be unique per user.

`user:list-api-keys` shows the keys of a user, without their credentials:

```bash
--8<-- "docs/examples/users.sh:list-api-keys"
```

```text
Found 1 API key(s) for user 'jane.doe@example.org'.
+----------------------------------+-----------+---------+----------------------+
| Key Id                           | Key Label | User Id | User Email           |
+----------------------------------+-----------+---------+----------------------+
| KqjKj9rpinoqXyxOmFOEfOJrepiX6gvM | importer  | 2       | jane.doe@example.org |
+----------------------------------+-----------+---------+----------------------+
```

`user:delete-api-key` revokes a key by its label:

```bash
--8<-- "docs/examples/users.sh:delete-api-key"
```

## Reference

All commands also accept the [global options](../reference/global-options.md).

--8<-- "build/docs/commands/user-add.md"

--8<-- "build/docs/commands/user-exists.md"

--8<-- "build/docs/commands/user-list.md"

--8<-- "build/docs/commands/user-update.md"

--8<-- "build/docs/commands/user-update-password.md"

--8<-- "build/docs/commands/user-enable.md"

--8<-- "build/docs/commands/user-disable.md"

--8<-- "build/docs/commands/user-delete.md"

--8<-- "build/docs/commands/user-create-api-key.md"

--8<-- "build/docs/commands/user-list-api-keys.md"

--8<-- "build/docs/commands/user-delete-api-key.md"
