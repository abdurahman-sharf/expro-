#!/usr/bin/env bash
#
# Runs the official ZATCA e-invoicing SDK, offline, on the XML files of a folder, and keeps its full output next
# to each file as <name>.sdk.txt. Nothing here connects to ZATCA: the SDK validates files on this machine.
#
# The SDK is not shipped with Expro: download it from the official ZATCA page (docs/tax/references.md gives the
# version and the SHA-256 checked), unpack it, run its install.sh once, and point ZATCA_SDK_HOME at that folder.
#
# The sample matrix of TAX2 §5.3 is checked by the test that knows what each sample must give:
#
#   ZATCA_SDK_HOME=/path/to/sdk vendor/bin/phpunit --no-coverage --group zatca-sdk \
#       tests/Libraries/EInvoicing/DocumentStamperTest.php
#
# This script is for other files, such as documents Expro issued:
#
#   ZATCA_SDK_HOME=/path/to/sdk tools/zatca/validate.sh folder
#
# The public SDK 2.0.3 applies the rules of 2021-08-19, older than the standard v1.2 Expro follows (TAX2 T5 report):
#   - a refusal for a rule v1.2 dropped is listed as such and does not fail the run;
#   - its QR check reads the tags as laid out in 2021, so it refuses every simplified document laid out as v1.2 says;
#   - it checks the issuer and the serial number of the stamp against the certificate in its own config.json.
# It asks for Java 11 to 14. On a later Java, which dropped the curve secp256k1, Java is made to use the
# BouncyCastle provider the SDK carries in place of SunEC.

set -euo pipefail

directory="${1:-}"
sdk="${ZATCA_SDK_HOME:-}"
fatoora="$sdk/Apps/fatoora"
config="${SDK_CONFIG:-$sdk/Configuration/config.json}"
dropped=" BR-KSA-05 BR-KSA-09 BR-KSA-50 BR-KSA-62 BR-KSA-64 BR-KSA-65 BR-KSA-F-02 BR-CL-KSA-14 BR-O-02 BR-O-05 "

if [ -z "$sdk" ] || [ ! -x "$fatoora" ]; then
    echo "Set ZATCA_SDK_HOME to the folder the official SDK was unpacked in (Apps/fatoora must exist)." >&2
    exit 2
fi

if [ ! -f "$config" ] || ! grep -q '"xsdPath": *"[^"]' "$config"; then
    echo "Run install.sh of the SDK once: it writes Configuration/config.json." >&2
    exit 2
fi

if [ -z "$directory" ] || ! ls "$directory"/*.xml >/dev/null 2>&1; then
    echo "Give a folder that holds XML files." >&2
    exit 2
fi

export FATOORA_HOME="$sdk/Apps"
export SDK_CONFIG="$config"
legacy=0

if grep -q '20210819' "$config"; then
    legacy=1
    providers="$(mktemp)"
    trap 'rm -f "$providers"' EXIT
    echo "security.provider.3=org.bouncycastle.jce.provider.BouncyCastleProvider" > "$providers"
    export _JAVA_OPTIONS="${_JAVA_OPTIONS:-} -Djava.security.properties=$providers"
fi

failures=0

for file in "$directory"/*.xml; do
    name="$(basename "$file" .xml)"
    report="$directory/$name.sdk.txt"

    "$fatoora" -validate -invoice "$file" > "$report" 2>&1 || true

    if grep -q "GLOBAL VALIDATION RESULT = PASSED" "$report"; then
        echo "ok      $name"
        continue
    fi

    codes="$(sed -n 's/.*CODE : \([^,]*\), MESSAGE.*/\1/p' "$report" | sort -u | tr '\n' ' ')"
    other=""

    for code in $codes; do
        if [ "$legacy" -eq 0 ] || [[ "$dropped" != *" $code "* ]]; then
            other="$other $code"
        fi
    done

    if [ -z "$other" ] && [ -n "$codes" ]; then
        echo "legacy  $name  refused only for rules v1.2 dropped: $codes"
    else
        echo "FAILED  $name  ${other:- (no rule code, see the report)}  see $report"
        failures=$((failures + 1))
    fi
done

if [ "$failures" -gt 0 ]; then
    echo "$failures file(s) refused." >&2
    exit 1
fi

echo "No file refused for a rule of the standard v1.2."
