#!/usr/bin/env bash
#
# Runs the official ZATCA e-invoicing SDK, offline, on the sample matrix of TAX2 §5.3 (T5).
#
# The SDK is not shipped with Expro: download it from the official ZATCA developer page, check its SHA-256
# against docs/tax/references.md, unpack it and point ZATCA_SDK_HOME at the folder it was unpacked in. The
# SDK needs Java 11 or later. Nothing here connects to ZATCA: the SDK validates files on this machine.
#
#   1. vendor/bin/phpunit --no-coverage --filter testSigned_StampsEverySampleOfTheMatrixSoThatItVerifies \
#          tests/Libraries/EInvoicing/DocumentStamperTest.php      (writes writable/zatca-matrix/*.xml)
#   2. ZATCA_SDK_HOME=/path/to/sdk tools/zatca/validate.sh [writable/zatca-matrix]
#
# Every sample must pass, except the broken ones (C-02-*), which must fail. The full output of the SDK
# for each file is kept next to it as <sample>.sdk.txt for the report.

set -euo pipefail

directory="${1:-writable/zatca-matrix}"
fatoora="${ZATCA_SDK_HOME:-}/Apps/fatoora"

if [ -z "${ZATCA_SDK_HOME:-}" ] || [ ! -x "$fatoora" ]; then
    echo "Set ZATCA_SDK_HOME to the folder the official SDK was unpacked in (Apps/fatoora must exist)." >&2
    exit 2
fi

if ! ls "$directory"/*.xml >/dev/null 2>&1; then
    echo "No samples in $directory: run the matrix test first (see the top of this script)." >&2
    exit 2
fi

failures=0

for file in "$directory"/*.xml; do
    sample="$(basename "$file" .xml)"
    report="$directory/$sample.sdk.txt"

    "$fatoora" -validate -invoice "$file" > "$report" 2>&1 || true

    if grep -q "GLOBAL VALIDATION RESULT = PASSED" "$report"; then
        result="PASSED"
    else
        result="FAILED"
    fi

    expected="PASSED"
    case "$sample" in C-02-*) expected="FAILED" ;; esac

    if [ "$result" = "$expected" ]; then
        echo "ok    $sample  $result"
    else
        echo "WRONG $sample  $result (expected $expected), see $report"
        failures=$((failures + 1))
    fi
done

if [ "$failures" -gt 0 ]; then
    echo "$failures sample(s) did not get the expected result." >&2
    exit 1
fi

echo "Every sample got the expected result."
