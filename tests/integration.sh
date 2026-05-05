#!/usr/bin/env bash
# Integration tests for omeka-s-cli
# Usage: ./tests/integration.sh [-v] [--skip <section>...]

set -euo pipefail

CLI="docker exec omeka-s-cli-app-1 php /app/omeka-s-cli/bin/omeka-s-cli"
# check if inside the container
if [[ -f /app/omeka-s-cli/bin/omeka-s-cli ]]; then
    CLI="php /app/omeka-s-cli/bin/omeka-s-cli"
fi

VERBOSE=0
PASS=0
FAIL=0
SECTION_SKIP=0
declare -a FAILURES
declare -a SKIP_SECTIONS

# ── flags ────────────────────────────────────────────────────────────────────

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1 ;;
        --skip) SKIP_SECTIONS+=("$2"); shift ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

# ── colors ───────────────────────────────────────────────────────────────────

GREEN='\033[0;32m'
RED='\033[0;31m'
ORANGE='\033[0;33m'
NC='\033[0m'

# ── helpers ──────────────────────────────────────────────────────────────────

section() {
    SECTION_SKIP=0
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
    if [[ ${#FAILURES[@]} -gt 0 ]]; then
        echo ""
        echo "  Failed tests:"
        for f in "${FAILURES[@]}"; do
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

cd /var/www/omeka-s || exit

assert_success "download Omeka S 4.1.1"         $CLI core:download /var/www/omeka-s 4.1.1
assert_success "create database config"         $CLI config:create-db-ini --username omeka --password omeka --host db --dbname omeka
assert_success "install Omeka S"                $CLI core:install
assert_success "Omeka S is installed"           $CLI core:status --is-installed

# ── core ─────────────────────────────────────────────────────────────────────

section "Core"
assert_output_is "core:version shows version" "4.1.1"  $CLI core:version
assert_output_contains "Omeka S status is 'installed'" "installed"   $CLI core:status
assert_fail "Omeka S upgrade to nonexistent version fails" $CLI core:upgrade 9.9.9
assert_success "Omeka S update to 4.2.0" $CLI core:update 4.2.0
assert_output_contains "Omeka S status is 'needs_migration'" "needs_migration"   $CLI core:status
assert_success "Omeka S migrate" $CLI core:migrate

# ── modules ──────────────────────────────────────────────────────────────────

section "Modules"

# get module:list output in json and test it has zero modules
assert_output_is "module:list with zero modules" "0"   bash -c "$CLI module:list --json | jq '. | length'"
assert_success "download module Common using repository"    $CLI module:download common
assert_success "download module Common version 3.4.82 (force download)"    $CLI module:download common:3.4.82 --force
assert_output_contains "module Common status is 'not_installed'" "not_installed"   $CLI module:status common
assert_success "module Common status outputs valid json"    bash -c "$CLI module:status common --json | jq ."
assert_success "install Common"    $CLI module:install common
assert_success "update Common"    $CLI module:update common:3.4.83
assert_output_contains "module Common status is 'needs_upgrade'" "needs_upgrade"   $CLI module:status common
assert_success "upgrade Common"    $CLI module:upgrade common
assert_output_is "module:list with one modules" "1"   bash -c "$CLI module:list --json  | jq '. | length'"
assert_success "uninstall Common"    $CLI module:uninstall common
assert_success "delete Common"    $CLI module:delete common
assert_success "download and install Common"    $CLI module:download common:3.4.82 --install
assert_success "update and upgrade Common to latest version" $CLI module:update common --upgrade

assert_success "download and install Log from zip release"       $CLI module:download https://github.com/Daniel-KM/Omeka-S-module-Log/releases/download/3.4.36/Log-3.4.36.zip --install
assert_output_is "verify log module is outdated" "1"   bash -c "$CLI module:list --outdated --json | jq '. | length'"
assert_fail    "install unknown module fails"   $CLI module:download nonexistent-module-xyz --install

assert_success "download and install Custom Vocab from git repo"             $CLI module:download https://github.com/omeka-s-modules/CustomVocab.git --install
assert_success "download and install outdated Advanced Resource Template"    $CLI module:download advancedresourcetemplate:3.4.51 --install
assert_success "download and install outdated NDE Termennetwerk"    $CLI module:download NdeTermennetwerk:1.3.0 --install
assert_success "download and install Value Suggest"             $CLI module:download valuesuggest --install

assert_success "update and upgrade all outdated modules" $CLI module:update --all --upgrade

# ── modules ──────────────────────────────────────────────────────────────────

section "Themes"

# check theme:list returns json with zero themes
assert_output_is "theme:list with default theme" "1"   bash -c "$CLI theme:list --json | jq '. | length'"
assert_success "download and install freedom theme"    $CLI theme:download freedom
assert_output_is "theme:list with two themes" "2"   bash -c "$CLI theme:list --json | jq '. | length'"
assert_success "delete freedom theme"    $CLI theme:delete freedom
assert_success "download and install freedom theme again"    $CLI theme:download freedom
assert_fail    "install unknown theme fails"   $CLI theme:download nonexistent-theme-xyz --install

# todo: check outdated themes

# ── users ────────────────────────────────────────────────────────────────────

section "Users"

assert_success "user:list returns results"                          $CLI user:list
assert_success "create test user"                                   $CLI user:add test@example.com "Test User" reviewer test
assert_success "create API key for test user"                       $CLI user:create-api-key test@example.com "test-key"
assert_success "delete API key for test user"                       $CLI user:delete-api-key test@example.com "test-key"
assert_success "disable test user"                                  $CLI user:disable test@example.com
assert_success "enable test user"                                   $CLI user:enable test@example.com
assert_success "test user exists"                                   $CLI user:exists test@example.com
assert_fail    "nonexistent user must not exist"                    $CLI user:exists nonexistent@example.com
assert_success "delete test user"                                   $CLI user:delete test@example.com
assert_fail    "delete nonexistent user fails"                      $CLI user:delete nonexistent@example.com

# ── vocabularies ─────────────────────────────────────────────────────────────

section "Vocabularies"
assert_success "vocabulary:list returns results" $CLI vocabulary:list
assert_success "add vocabulary schema.org using options" $CLI vocabulary:import --url "https://schema.org/version/latest/schemaorg-current-https.rdf" --namespace-uri="https://schema.org/" --prefix="schema" --label="schema.org"
assert_success "delete vocabulary schema.org" $CLI vocabulary:delete schema
assert_success "add vocabulary schema.org from local config" $CLI vocabulary:import --config /app/omeka-s-cli/examples/vocabulary/schema-dot-org.json
assert_success "delete vocabulary schema.org" $CLI vocabulary:delete schema
assert_success "add vocabulary person-name-vocabulary from remote config" $CLI vocabulary:import --config https://raw.githubusercontent.com/GhentCDH/Omeka-S-Vocabularies/refs/heads/main/person-name-vocabulary.json

# ── custom vocabularies ──────────────────────────────────────────────────────

section "Custom vocabularies"

# todo: add tests for custom vocabularies once implemented, e.g.:
#assert_success "custom-vocabulary:list returns results"         $CLI custom-vocabulary:list

# ── resource templates ───────────────────────────────────────────────────────

section "Resource templates"
assert_success "resource-template:list returns results"         $CLI resource-template:list
assert_success "delete base resource template"                  $CLI resource-template:delete "base resource"
assert_fail 'delete nonexistent resource template fails'        $CLI resource-template:delete "nonexistent resource template"
assert_fail    "import nonexistent file fails"                  $CLI resource-template:import /tmp/nonexistent.json
assert_success "import base resource template"                  $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/base_resource.json
assert_fail "import resource template with missing dependencies"    $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/template-with-dependencies.json
assert_success "install dependency: vocabulary schema.org from local config" $CLI vocabulary:import --config /app/omeka-s-cli/examples/vocabulary/schema-dot-org.json
assert_success "install dependency: module numericdatatypes" $CLI module:download numericdatatypes --install
assert_success "re-import resource template with dependencies"    $CLI resource-template:import /app/omeka-s-cli/examples/resource-template/template-with-dependencies.json

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
