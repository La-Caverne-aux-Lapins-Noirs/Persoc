#!/bin/sh

set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAW_DIR="$ROOT/.coverage/raw"
HTML_DIR="$ROOT/.coverage/html"
TEXT_REPORT="$ROOT/.coverage/coverage.txt"
REPORT_SCRIPT="$ROOT/tests/coverage_report.php"
PREPEND_SCRIPT="$ROOT/tests/coverage_prepend.php"

if ! command -v php >/dev/null 2>&1; then
    echo "php not found" >&2
    exit 1
fi

if ! php -r 'exit(function_exists("xdebug_start_code_coverage") ? 0 : 1);' >/dev/null 2>&1; then
    echo "Xdebug coverage is not available." >&2
    echo "Install/enable Xdebug for CLI PHP, then run ./coverage.sh again." >&2
    exit 1
fi

rm -rf "$ROOT/.coverage"
mkdir -p "$RAW_DIR" "$HTML_DIR"

status=0
for test in "$ROOT"/tests/*.php; do
    base=$(basename -- "$test")
    case "$base" in
        tools.php|coverage_prepend.php|coverage_report.php)
            continue
            ;;
    esac

    name=${base%.php}
    echo "COVER $base"
    if ! PERSOC_COVERAGE_FILE="$RAW_DIR/$name.json" \
         PERSOC_LOG_FILE="$ROOT/.coverage/persoc_test.log" \
         php -d xdebug.mode=coverage -d auto_prepend_file="$PREPEND_SCRIPT" "$test"; then
        status=1
    fi
done

if [ "$status" -ne 0 ]; then
    echo "At least one test failed; coverage report not generated." >&2
    exit "$status"
fi

php "$REPORT_SCRIPT" "$RAW_DIR" "$TEXT_REPORT" "$HTML_DIR"
cat "$TEXT_REPORT"
echo "HTML report: .coverage/html/index.html"
