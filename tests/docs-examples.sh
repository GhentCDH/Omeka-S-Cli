#!/usr/bin/env bash
# Runs every example of the documentation site against a live Omeka S, inside the dev container.
#
# The examples are the bash scripts in docs/examples/; the pages embed regions of them with snippet
# includes, so what the docs show is exactly what runs here. A script fails on the first command that
# exits non-zero (set -euo pipefail), and adds its own checks (jq/grep) outside the shown regions.
#
# Usage (inside the container, after `box compile`):
#   tests/docs-examples.sh [-v] [--source] [--instance <name>] [--script <name>]... [--lint-only]
#
#   -v, --verbose      trace every command of the scripts (bash -x) and show their output
#   --source           run bin/omeka-s-cli instead of the PHAR (the PHAR is what ships: test it)
#   --instance <name>  use /var/www/omeka-s-<name> and database omeka_<name> instead of the default
#                      instance, so examples can be developed without wiping /var/www/omeka-s (skips
#                      the fresh: scripts, which show the default paths in their commands)
#   --script <name>    run only this script (repeatable), e.g. --script modules
#   --lint-only        only check the pages, run no examples
#
# From the host:
#   docker exec omeka-s-cli-app-1 /app/omeka-s-cli/tests/docs-examples.sh
#
# WARNING: wipes the database and the Omeka S directory of the instance it runs on.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="$REPO_DIR/docs"
EXAMPLES_DIR="$DOCS_DIR/examples"

VERBOSE=0
USE_PHAR=1
LINT_ONLY=0
INSTANCE=""
declare -a ONLY_SCRIPTS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--verbose) VERBOSE=1 ;;
        --source) USE_PHAR=0 ;;
        --instance) INSTANCE="$2"; shift ;;
        --script) ONLY_SCRIPTS+=("${2%.sh}"); shift ;;
        --lint-only) LINT_ONLY=1 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

# The scripts, in the order they run. The plain ones share one instance, but each must also pass on
# its own on a freshly installed instance (`--script <name>`): it sets up what it needs (a module, a
# user, ...) itself, idempotently, and does not rely on an earlier script. Every script in
# docs/examples/ must be listed (the lint checks it), so none goes untested.
#
#   none:<script>   needs no Omeka S instance
#   fresh:<script>  wipe the instance first; the script builds it from nothing
#   <script>        runs on the instance as the previous scripts left it (installed by the runner
#                   when the run starts with such a script)
SCRIPTS=(
    "none:installation"
    "fresh:core"
    "usage"
    "modules"
    "themes"
    "users"
    "sites"
    "settings"
    "vocabularies"
    "custom-vocabularies"
    "resource-templates"
    "code-snippets"
    "dummy-data"
    "blueprints"
    "fresh:automation/provision"
    "automation/provision-check"
    "fresh:automation/blueprint-deploy"
)

# ── output ───────────────────────────────────────────────────────────────────

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'
PASS=0
FAIL=0
declare -a FAILURES=()

pass() { PASS=$((PASS + 1)); echo -e "  ${GREEN}PASS${NC}  $1"; }
fail() { FAIL=$((FAIL + 1)); FAILURES+=("$1"); echo -e "  ${RED}FAIL${NC}  $1"; }

summary() {
    echo ""
    echo "────────────────────────────────────────────────────────────"
    if [[ $FAIL -eq 0 ]]; then
        echo -e "  Results: ${GREEN}$PASS passed${NC}, $FAIL failed"
    else
        echo -e "  Results: $PASS passed, ${RED}$FAIL failed${NC}"
        echo ""
        echo "  Failed:"
        for f in "${FAILURES[@]}"; do echo -e "    ${RED}-${NC} $f"; done
    fi
    echo "────────────────────────────────────────────────────────────"
    [[ $FAIL -eq 0 ]]
}

# ── lint: the pages show no untested commands ────────────────────────────────

# A shell code block on a page may not contain an omeka-s-cli command typed in the markdown: commands
# must come from an example script (--8<-- include), so they are tested. Output blocks use ```text.
lint_pages() {
    local offenders
    offenders=$(find "$DOCS_DIR" -name '*.md' -print0 | xargs -0 awk '
        FNR == 1 { in_block = 0 }
        /^[[:space:]]*```/ {
            if (!in_block) {
                in_block = 1
                lang = $0; sub(/^[[:space:]]*```+[[:space:]]*/, "", lang); sub(/[[:space:]{].*/, "", lang)
                shell = (lang == "" || lang == "bash" || lang == "sh" || lang == "shell" || lang == "console")
            } else {
                in_block = 0
            }
            next
        }
        # the command as a word: not the container name omeka-s-cli-app-1 or a path like bin/omeka-s-cli
        in_block && shell && /(^|[^-[:alnum:]_.\/])omeka-s-cli([[:space:]]|$)/ && !/--8<--/ {
            print FILENAME ":" FNR ": " $0
        }
    ')
    if [[ -n "$offenders" ]]; then
        fail "pages show only tested commands (include them from docs/examples/ instead):"
        echo "$offenders" | sed "s#^$REPO_DIR/#        #"
    else
        pass "pages show only tested commands"
    fi

    # every example script is run
    local script listed missing=""
    while IFS= read -r script; do
        script="${script#"$EXAMPLES_DIR/"}"; script="${script%.sh}"
        listed=0
        for entry in "${SCRIPTS[@]}"; do [[ "${entry#*:}" == "$script" ]] && listed=1; done
        [[ $listed -eq 0 ]] && missing+="        $script"$'\n'
    done < <(find "$EXAMPLES_DIR" -name '*.sh' | sort)
    if [[ -n "$missing" ]]; then
        fail "every example script is run (add these to SCRIPTS in tests/docs-examples.sh):"
        echo -n "$missing"
    else
        pass "every example script is run"
    fi
}

echo "── Lint ────────────────────────────────────────────────────"
lint_pages
if [[ $LINT_ONLY -eq 1 ]]; then summary; exit; fi

# ── environment ──────────────────────────────────────────────────────────────

if [[ ! -d /var/www ]] || ! command -v mysql > /dev/null; then
    echo "Run this inside the dev container: docker exec omeka-s-cli-app-1 $REPO_DIR/tests/docs-examples.sh"
    exit 1
fi

OMEKA_DIR="/var/www/omeka-s${INSTANCE:+-$INSTANCE}"
DB_NAME="omeka${INSTANCE:+_$INSTANCE}"

BIN="$REPO_DIR/bin/omeka-s-cli"
if [[ $USE_PHAR -eq 1 ]]; then
    BIN="$REPO_DIR/bin/omeka-s-cli.phar"
    if [[ ! -f "$BIN" ]]; then echo "No PHAR at $BIN: run 'box compile' first (or pass --source)."; exit 1; fi
fi

# the scripts call `omeka-s-cli`, as a reader would: put a wrapper for the binary under test first on
# the PATH
WRAPPER_DIR="$(mktemp -d)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WRAPPER_DIR" "$WORK_DIR"' EXIT
printf '#!/bin/sh\nexec php %q "$@"\n' "$BIN" > "$WRAPPER_DIR/omeka-s-cli"
chmod +x "$WRAPPER_DIR/omeka-s-cli"
export PATH="$WRAPPER_DIR:$PATH"

# what the scripts may rely on, besides the omeka-s-cli on the PATH
export OMEKA_DIR DB_NAME REPO_DIR EXAMPLES_DIR WORK_DIR
# secrets are read from the environment in the examples, as they should be in real scripts
export DB_HOST=db DB_USER=omeka DB_PASSWORD=omeka ADMIN_PASSWORD='change-me-now'

echo ""
echo "── Environment ─────────────────────────────────────────────"
echo "  binary:   $BIN ($(omeka-s-cli --version 2>/dev/null | tail -1))"
echo "  instance: $OMEKA_DIR (database $DB_NAME)"

# ── instance ─────────────────────────────────────────────────────────────────

# drop the database and empty the Omeka S directory
reset_instance() {
    mysql -u root -proot -h "$DB_HOST" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`;
        GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'%';"
    mkdir -p "$OMEKA_DIR"
    find "$OMEKA_DIR" -mindepth 1 -delete
}

# a fresh, installed instance, for runs that start with a script needing one
install_instance() {
    reset_instance
    (
        cd "$OMEKA_DIR"
        omeka-s-cli core:download . --quiet
        omeka-s-cli config:create-db-ini --host "$DB_HOST" --dbname "$DB_NAME" \
            --username "$DB_USER" --password "$DB_PASSWORD" --quiet
        omeka-s-cli core:install --admin-email admin@example.org --admin-name Admin \
            --admin-password "$ADMIN_PASSWORD" --quiet
    )
}

# ── run ──────────────────────────────────────────────────────────────────────

selected() {
    [[ ${#ONLY_SCRIPTS[@]} -eq 0 ]] && return 0
    for s in "${ONLY_SCRIPTS[@]}"; do [[ "$s" == "$1" ]] && return 0; done
    return 1
}

run_script() {
    local script="$1" log="$WORK_DIR/output.log" status=0
    local bash_flags=(-euo pipefail)
    [[ $VERBOSE -eq 1 ]] && bash_flags+=(-x)

    # each script starts in the Omeka S directory, with a scratch directory of its own for files
    mkdir -p "$WORK_DIR/$script"
    if [[ $VERBOSE -eq 1 ]]; then
        (cd "$OMEKA_DIR" && SCRATCH_DIR="$WORK_DIR/$script" bash "${bash_flags[@]}" "$EXAMPLES_DIR/$script.sh") 2>&1 \
            | tee "$log" | sed 's/^/        /' || status=$?
    else
        (cd "$OMEKA_DIR" && SCRATCH_DIR="$WORK_DIR/$script" bash "${bash_flags[@]}" "$EXAMPLES_DIR/$script.sh") \
            > "$log" 2>&1 || status=$?
    fi

    if [[ $status -eq 0 ]]; then
        pass "$script"
    else
        fail "$script (exit $status)"
        [[ $VERBOSE -eq 1 ]] || tail -n 30 "$log" | sed 's/^/        /'
    fi
}

echo ""
echo "── Examples ────────────────────────────────────────────────"
INSTANCE_READY=0
mkdir -p "$OMEKA_DIR"
for entry in "${SCRIPTS[@]}"; do
    script="${entry#*:}"
    selected "$script" || continue

    if [[ "$entry" == none:* ]]; then
        :
    elif [[ "$entry" == fresh:* ]]; then
        # these show the default paths and database (/var/www/omeka-s, omeka) in their commands
        if [[ -n "$INSTANCE" ]]; then
            echo "  skip  $script (builds the default instance, not run with --instance)"
            continue
        fi
        reset_instance
        INSTANCE_READY=1
    elif [[ $INSTANCE_READY -eq 0 ]]; then
        echo "  ...   installing a fresh Omeka S for $script"
        install_instance
        INSTANCE_READY=1
    fi

    run_script "$script"
done

summary
