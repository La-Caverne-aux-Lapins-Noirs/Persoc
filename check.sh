#!/bin/sh

FAILED=0

printf "Validating syntax... "
for file in $(find . -name "*.php" 2>/dev/null | sort); do
    if ! php -l "$file" >/dev/null; then
        printf "\n\033[0;31m%s Syntax error\033[00m\n" "$file"
        FAILED=1
    fi
done
if [ "$FAILED" -ne 0 ]; then
    printf "failed.\n"
    exit 1
fi
printf "done.\n"

for file in tests/*.php; do
    if [ "$file" = "tests/tools.php" ]; then
        continue
    fi

    ERR="$file.errors"
    OUT=$(php "$file" 2>"$ERR")
    STATUS=$?

    if [ "$STATUS" -eq 0 ]; then
        printf "\033[0;32m"
        rm -f "$ERR"
        printf "%s\n" "$OUT"
    else
        printf "\033[0;31m"
        basename_file=$(basename "$file")
        if [ -n "$OUT" ]; then
            printf "%s\n" "$OUT"
        fi
        if [ -s "$ERR" ]; then
            cat "$ERR"
        fi
        rm -f "$ERR"
        printf "KO %s\n" "$basename_file"
        FAILED=1
    fi
    printf "\033[00m"
done

exit "$FAILED"
