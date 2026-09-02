#!/usr/bin/env bash
# Integration tests for omeka-s-cli
# Usage: ./tests/integration.sh [-v] [--phar] [--skip <section>...] [--section <section>...]

set -euo pipefail

VERBOSE=0
PASS=0
FAIL=0
SECTION_SKIP=0
HAS_SECTION_FILTER=0
USE_PHAR=0
declare -a FAILURES
declare -a SKIP_SECTIONS
declare -a ONLY_SECTIONS

# ── flags ────────────────────────────────────────────────────────────────────

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1 ;;
        --phar) USE_PHAR=1 ;;
        --skip) SKIP_SECTIONS+=("$2"); shift ;;
        --section) ONLY_SECTIONS+=("$2"); HAS_SECTION_FILTER=1; shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

# ── CLI binary ───────────────────────────────────────────────────────────────

BIN="bin/omeka-s-cli"
[[ $USE_PHAR -eq 1 ]] && BIN="bin/omeka-s-cli.phar"

# shellcheck disable=SC2089
CLI="docker exec omeka-s-cli-app-1 php /app/omeka-s-cli/$BIN"
if [[ -f /app/omeka-s-cli/$BIN ]]; then
    CLI="php /app/omeka-s-cli/$BIN"
fi

# ── colors ───────────────────────────────────────────────────────────────────

GREEN='\033[0;32m'
RED='\033[0;31m'
ORANGE='\033[0;33m'
NC='\033[0m'

# ── helpers ──────────────────────────────────────────────────────────────────

section() {
    SECTION_SKIP=0
    if [[ $HAS_SECTION_FILTER -eq 1 ]]; then
        SECTION_SKIP=1
        for s in "${ONLY_SECTIONS[@]+"${ONLY_SECTIONS[@]}"}"; do
            if [[ "${s,,}" == "${1,,}" ]]; then SECTION_SKIP=0; break; fi
        done
    fi
    for s in "${SKIP_SECTIONS[@]+"${SKIP_SECTIONS[@]}"}"; do
        if [[ "${s,,}" == "${1,,}" ]]; then SECTION_SKIP=1; break; fi
    done
    echo ""
    if [[ $SECTION_SKIP -eq 1 ]]; then
        echo "── $1 (skipped) ─────────────────────────────────────────"
    else
        echo "── $1 ──────────────────────────────────────────────────────"
    fi
}

# run "description" <command and args>  — execute silently, ignore result
run() {
    if [[ $SECTION_SKIP -eq 1 ]]; then return; fi
    local desc="$1"; shift
    "$@" > /dev/null 2>&1 || true
    if [[ $VERBOSE -eq 1 ]]; then echo "  run   $desc"; fi
}

# assert_success "description" <command and args>
assert_success() {
    if [[ $SECTION_SKIP -eq 1 ]]; then return; fi
    local desc="$1"; shift
    local output
    if output=$("$@" 2>&1); then
        PASS=$((PASS + 1))
        echo -e "  ${GREEN}PASS${NC}  $desc"
        if [[ $VERBOSE -eq 1 ]]; then echo "$output" | sed 's/^/        /'; fi
    else
        FAIL=$((FAIL + 1))
        FAILURES+=("$desc")
        echo -e "  ${RED}FAIL${NC}  $desc"
        echo "$output" | sed 's/^/        /'
    fi
}

# assert_fail "description" <command and args>
assert_fail() {
    if [[ $SECTION_SKIP -eq 1 ]]; then return; fi
    local desc="$1"; shift
    local output
    if output=$("$@" 2>&1); then
        FAIL=$((FAIL + 1))
        FAILURES+=("$desc  [expected failure, but succeeded]")
        echo -e "  ${RED}FAIL${NC}  $desc  (expected failure, but succeeded)"
        if [[ $VERBOSE -eq 1 ]]; then echo "$output" | sed 's/^/        /'; fi
    else
        PASS=$((PASS + 1))
        echo -e "  ${ORANGE}XFAIL${NC} $desc"
        if [[ $VERBOSE -eq 1 ]]; then echo "$output" | sed 's/^/        /'; fi
    fi
}

# assert_output_contains "description" "needle" <command and args>
assert_output_contains() {
    if [[ $SECTION_SKIP -eq 1 ]]; then return; fi
    local desc="$1"; local needle="$2"; shift 2
    local output
    output=$("$@" 2>&1) || true
    if echo "$output" | grep -q "$needle"; then
        PASS=$((PASS + 1))
        echo -e "  ${GREEN}PASS${NC}  $desc"
        if [[ $VERBOSE -eq 1 ]]; then echo "$output" | sed 's/^/        /'; fi
    else
        FAIL=$((FAIL + 1))
        FAILURES+=("$desc  [output did not contain: $needle]")
        echo -e "  ${RED}FAIL${NC}  $desc  (output did not contain: '$needle')"
        echo "$output" | sed 's/^/        /'
    fi
}

# assert_output_is "description" "expected" <command and args>
assert_output_is() {
    if [[ $SECTION_SKIP -eq 1 ]]; then return; fi
    local desc="$1"; local expected="$2"; shift 2
    local output
    output=$("$@" 2>&1) || true
    if [[ "$output" == "$expected" ]]; then
        PASS=$((PASS + 1))
        echo -e "  ${GREEN}PASS${NC}  $desc"
        if [[ $VERBOSE -eq 1 ]]; then echo "$output" | sed 's/^/        /'; fi
    else
        FAIL=$((FAIL + 1))
        FAILURES+=("$desc  [expected: '$expected', got: '$output']")
        echo -e "  ${RED}FAIL${NC}  $desc"
        echo "        expected: $expected"
        echo "        got:      $output"
    fi
}

summary() {
    echo ""
    echo "────────────────────────────────────────────────────────────"
    if [[ $FAIL -eq 0 ]]; then
        echo -e "  Results: ${GREEN}$PASS passed${NC}, $FAIL failed"
    else
        echo -e "  Results: $PASS passed, ${RED}$FAIL failed${NC}"
    fi
    if [[ $FAIL -gt 0 ]]; then
        echo ""
        echo "  Failed tests:"
        for f in "${FAILURES[@]+"${FAILURES[@]}"}"; do
            echo -e "    ${RED}-${NC} $f"
        done
    fi
    echo "────────────────────────────────────────────────────────────"
    [[ $FAIL -eq 0 ]]
}

# ── setup ────────────────────────────────────────────────────────────────────

section "Setup"

assert_success "drop database omeka" bash -c 'echo "drop database if exists omeka" | mysql -u root -proot -h db'
assert_success "create database omeka" bash -c 'echo "create database omeka" | mysql -u root -proot -h db'

assert_success "empty /var/www/omeka-s " rm -rf /var/www/omeka-s/*
assert_success "remove .htaccess" rm -rf /var/www/omeka-s/.htaccess

echo ""
cd /var/www/omeka-s || exit

assert_success "core:download version 4.1.1"         $CLI core:download /var/www/omeka-s 4.1.1
assert_success "config:create-db-ini"         $CLI config:create-db-ini --username omeka --password omeka --host db --dbname omeka
assert_success "core:install"                $CLI core:install
assert_success "core:status --is-installed"           $CLI core:status --is-installed

# ── core ─────────────────────────────────────────────────────────────────────

section "Core"
assert_output_is "core:version shows version" "4.1.1"  $CLI core:version
assert_output_contains "core:status is 'installed'" "installed"   $CLI core:status
assert_fail "core:upgrade to nonexistent version fails" $CLI core:upgrade 9.9.9
assert_success "core:update to 4.2.1" $CLI core:update 4.2.1
assert_output_contains "core:status is 'needs_migration'" "needs_migration"   $CLI core:status
assert_success "core:migrate" $CLI core:migrate

cd /var/www || exit
assert_output_is "core:version from cwd /var/www with relative base path ./omeka-s" "4.2.1"  $CLI core:version --base-path ./omeka-s

cd /var/www/omeka-s/application/src || exit
assert_output_is "core:version from cwd /var/www/omeka-s/application/src" "4.2.1" $CLI core:version

cd /var/www/omeka-s

# ── modules ──────────────────────────────────────────────────────────────────

section "Modules"

# get module:list output in json and test it has zero modules
assert_output_is "module:list lists zero modules" "0"   bash -c "$CLI module:list --json | jq '. | length'"

# repositories and search (read-only, no state involved)
assert_output_contains "module:repositories lists omeka.org" "omeka.org"   $CLI module:repositories
assert_output_is "module:repositories --json lists two repositories" "2"   bash -c "$CLI module:repositories --json | jq '. | length'"
assert_success "module:search common"   $CLI module:search common
assert_success "module:search common --json returns results"   bash -c "$CLI module:search common --json | jq -e '. | length > 0'"
assert_success "module:search --repository omeka.org"   $CLI module:search advanced --repository omeka.org
assert_success "module:search --unregistered (modules not on omeka.org)"   bash -c "$CLI module:search --unregistered --json | jq -e '. | length > 0'"
assert_success "module:search --refresh (re-fetch repository data)"   $CLI module:search common --refresh
assert_success "module:download common (using repository)"    $CLI module:download common
assert_success "module:download common version 3.4.82 (force download)"    $CLI module:download common:3.4.82 --force
assert_output_contains "module:status common is 'not_installed'" "not_installed"   $CLI module:status common
assert_success "module:status common --json"    bash -c "$CLI module:status common --json | jq ."
assert_success "module:install common"    $CLI module:install common
assert_success "module:update common:3.4.83"    $CLI module:update common:3.4.83
assert_output_contains "module:status common is 'needs_upgrade'" "needs_upgrade"   $CLI module:status common
assert_success "module:upgrade common"    $CLI module:upgrade common
assert_output_is "module:list returns one module" "1"   bash -c "$CLI module:list --json  | jq '. | length'"
assert_success "module:uninstall common"    $CLI module:uninstall common
assert_success "module:delete common"    $CLI module:delete common
assert_success "module:download common:3.4.82 --install"    $CLI module:download common:3.4.82 --install
assert_success "module:update common --upgrade (update to latest version and upgrade)" $CLI module:update common --upgrade

assert_success "module:download log (from zip release, install)"       $CLI module:download https://github.com/Daniel-KM/Omeka-S-module-Log/releases/download/3.4.36/Log-3.4.36.zip --install
assert_output_is "verify log module is outdated" "1"   bash -c "$CLI module:list --outdated --json | jq '. | length'"
assert_fail    "module:download nonexistent-module-xyz"   $CLI module:download nonexistent-module-xyz --install

assert_success "module:download customvocab --install (from git repo)"             $CLI module:download https://github.com/omeka-s-modules/CustomVocab.git --install
assert_success "module:download AdvancedResourceTemplate --install (outdated version)"    $CLI module:download advancedresourcetemplate:3.4.51 --install
assert_success "module:download NdeTermennetwerk --install (outdated version)"    $CLI module:download NdeTermennetwerk:1.3.0 --install
assert_success "module:download ValueSuggest --install (latest version)"             $CLI module:download valuesuggest --install

assert_success "update and upgrade all outdated modules" $CLI module:update --all --upgrade
assert_output_is "verify no outdated modules" "0"   bash -c "$CLI module:list --outdated --json | jq '. | length'"

assert_fail    "module:delete customvocab (can't delete installed module)"   $CLI module:delete customvocab
assert_success "module:delete customvocab --force"  $CLI module:delete customvocab --force
assert_fail "module:status customvocab (module must not be found)"    $CLI module:status customvocab

assert_success    "module:disable AdvancedResourceTemplate"   $CLI module:disable AdvancedResourceTemplate
assert_success    "module:enable AdvancedResourceTemplate"   $CLI module:enable AdvancedResourceTemplate
assert_success    "module:disable AdvancedResourceTemplate"   $CLI module:disable AdvancedResourceTemplate

assert_output_is "verify 1 module is inactive" "1"   bash -c "$CLI module:list --not-active --json | jq '. | length'"

assert_success "module:uninstall --not-active"  $CLI module:uninstall --not-active

assert_output_is "verify 1 module is not installed" "1"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

assert_success    "module:uninstall NdeTermennetwerk"   $CLI module:uninstall NdeTermennetwerk
assert_success    "module:uninstall ValueSuggest"   $CLI module:uninstall ValueSuggest

assert_success "module:delete --not-installed"   $CLI module:delete --not-installed
assert_output_is "verify 0 modules are not installed" "0"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

# ── module option guards ─────────────────────────────────────────────────────
# every bulk command needs exactly one selector: a module id, or a bulk flag

assert_fail "module:enable without module id or --all fails"                  $CLI module:enable
assert_fail "module:install without module id or --all fails"                 $CLI module:install
assert_fail "module:disable with both module id and --all fails"              $CLI module:disable common --all
assert_fail "module:upgrade with both module id and --all fails"              $CLI module:upgrade common --all
assert_fail "module:delete with both module id and --not-installed fails"     $CLI module:delete common --not-installed
assert_fail "module:uninstall with both --all and --not-active fails"         $CLI module:uninstall --all --not-active

# ── dry runs ─────────────────────────────────────────────────────────────────
# a dry run must report the work and change nothing at all
# state at this point: common and log, both active

assert_output_contains "module:disable --all --dry-run reports two modules" "2 module(s) would be disabled"   $CLI module:disable --all --dry-run
assert_output_is "dry run disabled nothing" "0"   bash -c "$CLI module:list --not-active --json | jq '. | length'"

assert_output_contains "module:uninstall --all --dry-run reports two modules" "2 module(s) would be uninstalled"   $CLI module:uninstall --all --dry-run
assert_output_is "dry run uninstalled nothing" "0"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

assert_output_contains "module:disable common --dry-run reports one module" "would be disabled"   $CLI module:disable common --dry-run
assert_output_is "dry run left common active" "active"   bash -c "$CLI module:status common --json | jq -r '.[0].state'"

assert_output_contains "module:enable --all --dry-run with nothing to do" "No modules to enable."   $CLI module:enable --all --dry-run

# ── bulk enable and disable ──────────────────────────────────────────────────

assert_success "module:disable --all"   $CLI module:disable --all
assert_output_is "all modules are inactive" "2"   bash -c "$CLI module:list --not-active --json | jq '. | length'"
assert_success "module:enable --all"   $CLI module:enable --all
assert_output_is "no modules are inactive" "0"   bash -c "$CLI module:list --not-active --json | jq '. | length'"
assert_output_is "both modules are active" "2"   bash -c "$CLI module:list --active --json | jq '. | length'"

# ── bulk install ─────────────────────────────────────────────────────────────

assert_success "module:download customvocab (without installing)"   $CLI module:download customvocab
assert_success "module:download numericdatatypes (without installing)"   $CLI module:download numericdatatypes
assert_output_is "two modules are downloaded but not installed" "2"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

assert_output_contains "module:install --all --dry-run reports two modules" "2 module(s) would be installed"   $CLI module:install --all --dry-run
assert_output_is "dry run installed nothing" "2"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

assert_success "module:install --all"   $CLI module:install --all
assert_output_is "no modules are left uninstalled" "0"   bash -c "$CLI module:list --not-installed --json | jq '. | length'"

# ── bulk update and upgrade ──────────────────────────────────────────────────

assert_success "module:download AdvancedResourceTemplate:3.4.51 --install (outdated fixture)"   $CLI module:download advancedresourcetemplate:3.4.51 --install --force
assert_output_is "one module is outdated" "1"   bash -c "$CLI module:list --outdated --json | jq '. | length'"

assert_output_contains "module:update --all --dry-run reports one module" "1 module(s) would be updated"   $CLI module:update --all --dry-run
assert_output_is "dry run updated nothing" "1"   bash -c "$CLI module:list --outdated --json | jq '. | length'"

assert_success "module:update --all (without --upgrade)"   $CLI module:update --all
assert_output_is "the updated module needs an upgrade" "1"   bash -c "$CLI module:list --needs-upgrade --json | jq '. | length'"

assert_output_contains "module:upgrade --all --dry-run reports one module" "1 module(s) would be upgraded"   $CLI module:upgrade --all --dry-run
assert_output_is "dry run upgraded nothing" "1"   bash -c "$CLI module:list --needs-upgrade --json | jq '. | length'"

assert_success "module:upgrade --all"   $CLI module:upgrade --all
assert_output_is "no modules need an upgrade" "0"   bash -c "$CLI module:list --needs-upgrade --json | jq '. | length'"

# ── output modes ─────────────────────────────────────────────────────────────

assert_output_contains "module:list --csv has a csv header" "id,name,state"   $CLI module:list --csv
assert_output_contains "module:list --extended shows the module path" "Path"   $CLI module:list --extended
assert_output_is "module:download --quiet prints nothing" ""   $CLI module:download common --force --quiet
# the backup is written to $HOME/.omeka-s-cli/backups/modules on the machine running the CLI
assert_success "module:download --backup keeps the previous version"   $CLI module:download common --force --backup

# ── bulk uninstall and delete, restoring the fixture for later sections ──────

assert_success "module:uninstall --all"   $CLI module:uninstall --all
assert_output_is "no modules are installed" "0"   bash -c "$CLI module:list --active --json | jq '. | length'"

assert_output_contains "module:delete --not-installed --dry-run reports the modules" "module(s) would be deleted"   $CLI module:delete --not-installed --dry-run
assert_success "module:delete --not-installed"   $CLI module:delete --not-installed
assert_output_is "no modules remain" "0"   bash -c "$CLI module:list --json | jq '. | length'"

# later sections need common and log installed and active
assert_success "reinstall common"   $CLI module:download common --install
assert_success "reinstall log"   $CLI module:download log --install
assert_output_is "common and log are active again" "2"   bash -c "$CLI module:list --active --json | jq '. | length'"

# --ignore-not-found: a script that wants a module gone should not stop because it never was there

assert_fail    "module:uninstall on an unknown module fails"                  $CLI module:uninstall ghostmod
assert_success "module:uninstall --ignore-not-found skips an unknown module"  $CLI module:uninstall ghostmod --ignore-not-found
assert_fail    "module:delete on an unknown module fails"                     $CLI module:delete ghostmod
assert_success "module:delete --ignore-not-found skips an unknown module"     $CLI module:delete ghostmod --ignore-not-found
assert_fail    "module:disable on an unknown module fails"                    $CLI module:disable ghostmod
assert_success "module:disable --ignore-not-found skips an unknown module"    $CLI module:disable ghostmod --ignore-not-found
assert_fail    "--ignore-not-found does not replace --force on module:delete" $CLI module:delete common --ignore-not-found
assert_output_is "the modules are untouched by the skips" "2"   bash -c "$CLI module:list --active --json | jq '. | length'"

# ── themes ──────────────────────────────────────────────────────────────────

section "Themes"

# check theme:list returns json with zero themes
assert_output_is "theme:list with default theme" "1"   bash -c "$CLI theme:list --json | jq '. | length'"
assert_success "download and install freedom theme"    $CLI theme:download freedom
assert_output_is "theme:list with two themes" "2"   bash -c "$CLI theme:list --json | jq '. | length'"
assert_success "delete freedom theme"    $CLI theme:delete freedom
assert_success "download and install freedom theme again"    $CLI theme:download freedom
assert_fail    "install unknown theme fails"   $CLI theme:download nonexistent-theme-xyz --install

assert_fail    "theme:delete on an unknown theme fails"                      $CLI theme:delete ghosttheme
assert_success "theme:delete --ignore-not-found skips an unknown theme"      $CLI theme:delete ghosttheme --ignore-not-found
assert_output_is "the themes are untouched by the skip" "2"   bash -c "$CLI theme:list --json | jq '. | length'"

# todo: check outdated themes

# ── users ────────────────────────────────────────────────────────────────────

section "Users"

assert_success "user:list returns results"                                                    $CLI user:list
assert_success "create test user"                                                             $CLI user:add test@example.com "Test User" reviewer test

assert_success "update test user name"                                                        $CLI user:update test@example.com --name "Updated User"
assert_output_contains "user:update --json shows updated name"            "Updated User"      bash -c "$CLI user:update test@example.com --name 'Updated User' --json"
assert_success "update test user role"                                                        $CLI user:update test@example.com --role editor
assert_fail    "update test user with invalid role fails"                                      $CLI user:update test@example.com --role invalid_role
assert_fail    "update test user with both --activate and --deactivate fails"                  $CLI user:update test@example.com --activate --deactivate
assert_fail    "update nonexistent user fails"                                                 $CLI user:update nonexistent@example.com --name "Foo"

assert_success "update test user password"                                                    $CLI user:update-password test@example.com "newpassword123"
assert_fail    "update password for nonexistent user fails"                                   $CLI user:update-password nonexistent@example.com "password"

assert_success "list API keys for test user (none yet)"                                       $CLI user:list-api-keys test@example.com
assert_success "create API key for test user"                                                 $CLI user:create-api-key test@example.com "test-key"
assert_success "list API keys for test user"                                                  $CLI user:list-api-keys test@example.com
assert_output_contains "user:list-api-keys --json shows key label"        "test-key"          bash -c "$CLI user:list-api-keys test@example.com --json"
assert_output_is "user:list-api-keys --json returns 1 key"                "1"                 bash -c "$CLI user:list-api-keys test@example.com --json | jq '. | length'"
assert_success "delete API key for test user"                                                 $CLI user:delete-api-key test@example.com "test-key"
assert_fail    "list API keys for nonexistent user fails"                                      $CLI user:list-api-keys nonexistent@example.com
assert_success "user:delete-api-key --ignore-not-found skips a missing user"                  $CLI user:delete-api-key nonexistent@example.com some-key --ignore-not-found
assert_success "user:delete-api-key --ignore-not-found skips a missing key"                   $CLI user:delete-api-key test@example.com no-such-key --ignore-not-found
assert_fail    "user:delete-api-key on a missing key still fails without the flag"            $CLI user:delete-api-key test@example.com no-such-key

assert_success "disable test user"                                                            $CLI user:disable test@example.com
assert_success "enable test user"                                                             $CLI user:enable test@example.com
assert_success "test user exists"                                                             $CLI user:exists test@example.com
assert_fail    "nonexistent user must not exist"                                               $CLI user:exists nonexistent@example.com
assert_success "delete test user"                                                             $CLI user:delete test@example.com
assert_fail    "delete nonexistent user fails"                                                 $CLI user:delete nonexistent@example.com

# --ignore-not-found: a migration script should skip a user that is absent, but never skip a
# mistake in the command itself

assert_fail    "user:update on a missing user fails without the flag"          $CLI user:update ghost@example.com --name "Ghost"
assert_success "user:update --ignore-not-found skips a missing user"           $CLI user:update ghost@example.com --name "Ghost" --ignore-not-found
assert_success "user:delete --ignore-not-found skips a missing user"           $CLI user:delete ghost@example.com --ignore-not-found
assert_success "user:enable --ignore-not-found skips a missing user"           $CLI user:enable ghost@example.com --ignore-not-found
assert_success "user:disable --ignore-not-found skips a missing user"          $CLI user:disable ghost@example.com --ignore-not-found
assert_success "user:update-password --ignore-not-found skips a missing user"  $CLI user:update-password ghost@example.com secret123 --ignore-not-found
assert_output_is "the skip prints nothing with --json"  ""                     bash -c "$CLI user:update ghost@example.com --name 'Ghost' --ignore-not-found --json"

assert_fail "--ignore-not-found still rejects --activate with --deactivate"    $CLI user:update ghost@example.com --activate --deactivate --ignore-not-found
assert_fail "--ignore-not-found still rejects an invalid role"                 $CLI user:update ghost@example.com --role bogus --ignore-not-found
assert_fail "--ignore-not-found still rejects an invalid email"                $CLI user:update ghost@example.com --email not-an-email --ignore-not-found

# the flag must not stop the commands working on a user that does exist
assert_success "create a user for the --ignore-not-found checks"               $CLI user:add ignore@example.com "Ignore Test" reviewer secret123
assert_success "user:update --ignore-not-found updates an existing user"       $CLI user:update ignore@example.com --role editor --ignore-not-found
assert_output_is "the role was really changed" "editor"                        bash -c "$CLI user:update ignore@example.com --ignore-not-found --json | jq -r .role"
assert_success "user:disable --ignore-not-found disables an existing user"     $CLI user:disable ignore@example.com --ignore-not-found
assert_output_is "the user is really inactive" "false"                         bash -c "$CLI user:update ignore@example.com --ignore-not-found --json | jq -r .is_active"
assert_success "user:enable --ignore-not-found enables an existing user"       $CLI user:enable ignore@example.com --ignore-not-found
assert_output_is "the user is really active" "true"                            bash -c "$CLI user:update ignore@example.com --ignore-not-found --json | jq -r .is_active"
assert_success "user:update-password --ignore-not-found on an existing user"   $CLI user:update-password ignore@example.com newsecret123 --ignore-not-found
assert_success "user:delete --ignore-not-found deletes an existing user"       $CLI user:delete ignore@example.com --ignore-not-found
assert_fail    "the user is really gone"                                       $CLI user:exists ignore@example.com

# ── vocabularies ─────────────────────────────────────────────────────────────

section "Vocabularies"
assert_success "vocabulary:list returns results" $CLI vocabulary:list
assert_success "add vocabulary schema.org using options" $CLI vocabulary:import --url "https://schema.org/version/latest/schemaorg-current-https.rdf" --namespace-uri="https://schema.org/" --prefix="schema" --label="schema.org"
assert_success "delete vocabulary schema.org" $CLI vocabulary:delete schema
assert_success "add vocabulary schema.org from local config" $CLI vocabulary:import --config /app/omeka-s-cli/examples/vocabulary/schema-dot-org.json
assert_success "delete vocabulary schema.org" $CLI vocabulary:delete schema
assert_success "add vocabulary person-name-vocabulary from remote config" $CLI vocabulary:import --config https://raw.githubusercontent.com/GhentCDH/Omeka-S-Vocabularies/refs/heads/main/person-name-vocabulary.json

run "delete vocabulary person-name-vocabulary" $CLI vocabulary:delete pvn

assert_fail    "vocabulary:delete on an unknown prefix fails"                 $CLI vocabulary:delete ghostvocab
assert_success "vocabulary:delete --ignore-not-found skips an unknown prefix" $CLI vocabulary:delete ghostvocab --ignore-not-found

# ── custom vocabularies ──────────────────────────────────────────────────────

section "Custom vocabularies"

run "ensure customvocab module is not installed" $CLI module:delete customvocab --force

assert_fail "custom-vocabulary:list with missing customvocab module" $CLI custom-vocabulary:list
assert_success "module:download customvocab --install"             $CLI module:download customvocab --install
assert_output_is "custom-vocabulary:list returns 0 results" "0"        bash -c "$CLI custom-vocabulary:list --json | jq '. | length'"
assert_success "custom-vocabulary:import custom_vocab_terms.json"         $CLI custom-vocabulary:import /app/omeka-s-cli/examples/custom-vocabulary/custom_vocab_terms.json --update
assert_success "custom-vocabulary:import custom_vocab_uris.json"         $CLI custom-vocabulary:import /app/omeka-s-cli/examples/custom-vocabulary/custom_vocab_uris.json --update
assert_output_is "custom-vocabulary:list returns 2 results" "2"        bash -c "$CLI custom-vocabulary:list --json | jq '. | length'"
assert_success "custom-vocabulary:list custom_vocab_terms"         $CLI custom-vocabulary:delete custom-vocab-terms
assert_success "custom-vocabulary:list custom_vocab_uris"         $CLI custom-vocabulary:delete custom-vocab-uris
assert_success "custom-vocabulary:list returns 0 results after deletion"         $CLI custom-vocabulary:list

assert_fail    "custom-vocabulary:delete on an unknown vocabulary fails"                 $CLI custom-vocabulary:delete ghostcv
assert_success "custom-vocabulary:delete --ignore-not-found skips an unknown vocabulary" $CLI custom-vocabulary:delete ghostcv --ignore-not-found

# ── resource templates ───────────────────────────────────────────────────────

section "Resource templates"
assert_success "resource-template:list returns results"         $CLI resource-template:list
assert_success "delete base resource template"                  $CLI resource-template:delete "base resource"
assert_fail 'delete nonexistent resource template fails'        $CLI resource-template:delete "nonexistent resource template"
assert_success 'resource-template:delete --ignore-not-found skips a missing template'  $CLI resource-template:delete "nonexistent resource template" --ignore-not-found
assert_fail    "import nonexistent file fails"                  $CLI resource-template:import /tmp/nonexistent.json

run "ensure dependencies for template with dependencies are not installed" $CLI module:disable log
run "ensure dependencies for template with dependencies are not installed" $CLI module:disable common

assert_fail "import base resource template (common module disabled)"                  $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/base_resource.json

run "ensure dependencies are installed installed" $CLI module:enable common
run "ensure dependencies are installed installed" $CLI module:enable log

assert_success "import base resource template (common module enabled)"                  $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/base_resource.json

run    "module:delete numericdatatypes"   $CLI module:delete numericdatatypes --force
run    "module:delete valuesuggest --force (uninstall first)"   $CLI module:delete valuesuggest --force
run    "module:delete NdeTermennetwerk --force (uninstall first)"   $CLI module:delete NdeTermennetwerk --force

assert_fail "import resource template with missing dependencies"    $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/template-with-dependencies.json --update
assert_success "install dependency: vocabulary schema.org from local config" $CLI vocabulary:import --config /app/omeka-s-cli/examples/vocabulary/schema-dot-org.json
assert_success "install dependency: numericdatatypes" $CLI module:download numericdatatypes --install --force
assert_success "install dependency: valuesuggest" $CLI module:download valuesuggest --install --force
assert_success "install dependency: NdeTermennetwerk"    $CLI module:download NdeTermennetwerk --install --force
assert_success "re-import resource template with dependencies"    $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/template-with-dependencies.json --update

# todo: add test for resource-template with customvocab dependency

# ── config ───────────────────────────────────────────────────────

section "Configuration"

# ── dummy data ───────────────────────────────────────────────────────────────

section "Dummy data"
assert_success "dummy:create-items creates 5 items"             $CLI dummy:create-items -n 5
assert_success "dummy:create-item-sets creates 2 item sets"     $CLI dummy:create-item-sets -n 2
assert_success "dummy:create-items creates 5 items from local config"             $CLI dummy:create-items -n 5 --config /app/omeka-s-cli/examples/dummy/item.json
assert_success "dummy:create-item-sets creates 5 items from remote config"             $CLI dummy:create-item-sets -n 5 --config https://raw.githubusercontent.com/GhentCDH/Omeka-S-Cli/refs/heads/main/examples/dummy/item-set.json
assert_fail    "dummy:create-items with invalid config must fail"   $CLI dummy:create-items --config /tmp/nonexistent.json

# ── summary ──────────────────────────────────────────────────────────────────

summary
