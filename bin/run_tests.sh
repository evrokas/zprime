#!/usr/bin/env bash
#
# Runs the ZPrime regression suite: static PHP/JS/CSS/template/app-file
# checks, then (if those pass) the functional tests against a dedicated,
# disposable test database -- NEVER the live one. See tests/README.md
# before your first run (you need config/db.test.php and web/core set up).
#
# Usage:
#   bin/run_tests.sh                 # everything
#   bin/run_tests.sh --static-only   # skip the DB-backed functional suite
#   bin/run_tests.sh --functional-only

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"
exec php tests/run_all.php "$@"
