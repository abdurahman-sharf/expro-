#!/usr/bin/env bash
#
# Runs the official ZATCA e-invoicing SDK, offline, on the XML files of a folder, in the order of their names, keeps
# its full output next to each file as <name>.sdk.txt, and gives each file one of three results:
#
#   PASSED               the SDK passed the file and ran every check the file calls for: XSD, EN, KSA and PIH, and
#                        QR and SIGNATURE for a simplified document;
#   FAILED               the SDK refused the file for a rule of the standard v1.2 (or gave no rule for its refusal);
#   NOT FULLY VALIDATED  the SDK did not run every check: it stopped at a rule of 2021 that v1.2 dropped or that the
#                        SDK of 2021 works out wrongly, or it skipped a check, or it gave no result. This is never an
#                        acceptance: the checks after the one it stopped at were not made.
#
# Nothing here connects to ZATCA. Download the SDK from the official ZATCA page (docs/tax/references.md gives the
# versions and SHA-256 checked), unpack it, run its install.sh once, and point ZATCA_SDK_HOME at that folder:
#
#   ZATCA_SDK_HOME=/path/to/sdk tools/zatca/validate.sh [--chain] folder
#
# For each file, the SDK is given a config of its own: the certificate the file was stamped with (the SDK compares
# the issuer and the serial number the stamp names with the certificate of its config), and the previous hash the
# PIH check expects: the one in <name>.pih when that file exists, and with --chain the hash the SDK itself works
# out for the file before (the files are then one chain, in the order of their names).
#
# The SDK asks for Java 11 to 14. On a later Java, which dropped the curve secp256k1, Java is made to use the
# BouncyCastle provider the SDK carries in place of SunEC.
#
# Exit status: 0 when every file PASSED, 1 when one FAILED, 3 when none failed and one was NOT FULLY VALIDATED.
# The sample matrix and the documents Expro issues are checked by the tests of the group zatca-sdk.

set -euo pipefail

chain=0

if [ "${1:-}" = "--chain" ]; then
    chain=1
    shift
fi

directory="${1:-}"
sdk="${ZATCA_SDK_HOME:-}"
fatoora="$sdk/Apps/fatoora"
config="${SDK_CONFIG:-$sdk/Configuration/config.json}"

# The rules of 2021 (SDK 2.0.3) that v1.2 dropped or changed (BR-KSA-16 asks a standard invoice for its payment means,
# which v1.2 checks only when they are given), the two it works out wrongly (BR-S-10 over the whole file, BR-KSA-51
# in floating point), and the codes of its QR check, which reads the tags as laid out in 2021.
legacy_codes=" BR-KSA-05 BR-KSA-09 BR-KSA-10 BR-KSA-16 BR-KSA-50 BR-KSA-51 BR-KSA-62 BR-KSA-63 BR-KSA-64 BR-KSA-65 BR-KSA-F-02 BR-CL-KSA-14 BR-S-10 BR-O-02 BR-O-05 R S vatTotal "

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

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

export FATOORA_HOME="$sdk/Apps"
legacy=0

if grep -q '20210819' "$config"; then
    legacy=1
fi

java_major="$(java -version 2>&1 | sed -n 's/.*version "\([0-9]*\).*/\1/p' | head -1)"
jar="$(ls "$sdk"/Lib/Java/*.jar 2>/dev/null | head -1 || true)"

if [ "${java_major:-0}" -ge 15 ] && [ -n "$jar" ] && unzip -l "$jar" 2>/dev/null | grep -q 'org/bouncycastle/jce/provider/BouncyCastleProvider.class'; then
    echo "security.provider.3=org.bouncycastle.jce.provider.BouncyCastleProvider" > "$work/java.security"
    export _JAVA_OPTIONS="${_JAVA_OPTIONS:-} -Djava.security.properties=$work/java.security"
fi

configured() {
    local certificate="$1" pih="$2" target="$3"

    sed -e "s|\"certPath\": *\"[^\"]*\"|\"certPath\": \"$certificate\"|" -e "s|\"pihPath\": *\"[^\"]*\"|\"pihPath\": \"$pih\"|" "$config" > "$target"
}

default_pih="$(sed -n 's/.*"pihPath": *"\([^"]*\)".*/\1/p' "$config")"
default_certificate="$(sed -n 's/.*"certPath": *"\([^"]*\)".*/\1/p' "$config")"
previous=""
passed=0
failed=0
partial=0

for file in "$directory"/*.xml; do
    name="$(basename "$file" .xml)"
    report="$directory/$name.sdk.txt"
    certificate="$default_certificate"
    pih="$default_pih"

    if grep -q '<ds:X509Certificate>' "$file"; then
        sed -n 's/.*<ds:X509Certificate>\([^<]*\)<.*/\1/p' "$file" | tr -d '\n' > "$work/$name.certificate"
        certificate="$work/$name.certificate"
    fi

    if [ -f "$directory/$name.pih" ]; then
        pih="$directory/$name.pih"
    fi

    if [ "$chain" -eq 1 ] && [ -n "$previous" ]; then
        configured "$certificate" "$pih" "$work/hash.json"
        SDK_CONFIG="$work/hash.json" "$fatoora" -generateHash -invoice "$previous" > "$work/hash.txt" 2>&1 || true
        sed -n 's/.*INVOICE HASH = *\([^ ]*\).*/\1/p' "$work/hash.txt" | tr -d '\r\n' > "$work/$name.pih"
        pih="$work/$name.pih"
    fi

    configured "$certificate" "$pih" "$work/$name.json"
    SDK_CONFIG="$work/$name.json" "$fatoora" -validate -invoice "$file" > "$report" 2>&1 || true
    previous="$file"

    stages=" $(sed -n 's/.*\[\([A-Z]*\)\] validation result : \([A-Z]*\).*/\1=\2/p' "$report" | tr '\n' ' ')"
    codes="$(sed -n 's/.*CODE : \([^,]*\), MESSAGE.*/\1/p' "$report" | sort -u | tr '\n' ' ' | sed 's/ $//')"
    refused="$(printf '%s' "$stages" | tr ' ' '\n' | sed -n 's/=FAILED$//p' | tr '\n' ' ' | sed 's/ $//')"
    warnings="$( (grep -i 'warn' "$report" || true) | sed -n 's/.*CODE : \([^,]*\), MESSAGE.*/\1/p' | sort -u | tr '\n' ' ')"
    required="XSD EN KSA PIH"

    if grep -q '<cbc:InvoiceTypeCode name="02' "$file"; then
        required="XSD EN KSA QR SIGNATURE PIH"
    fi

    missing=""

    for stage in $required; do
        if [[ "$stages" != *" $stage=PASSED "* ]]; then
            missing="$missing $stage"
        fi
    done

    if grep -q "GLOBAL VALIDATION RESULT = PASSED" "$report"; then
        if [ -z "$missing" ]; then
            result="PASSED"
            detail=""
        else
            result="NOT FULLY VALIDATED"
            detail="checks not run:$missing"
        fi
    elif grep -q "GLOBAL VALIDATION RESULT = FAILED" "$report"; then
        current=""

        for code in $codes; do
            if [ "$legacy" -eq 0 ] || [[ "$legacy_codes" != *" $code "* ]]; then
                current="$current $code"
            fi
        done

        if [ -n "$current" ] || [ -z "$codes" ]; then
            result="FAILED"
            detail="${current:- no rule code}; failed checks: ${refused:-none named}"
        else
            result="NOT FULLY VALIDATED"
            detail="refused only for rules of 2021: $codes; checks not run or not passed:$missing"
        fi
    else
        result="NOT FULLY VALIDATED"
        detail="the SDK gave no result"
    fi

    if [ -n "$warnings" ]; then
        detail="$detail (warnings: $warnings)"
    fi

    case "$result" in
        PASSED) passed=$((passed + 1)) ;;
        FAILED) failed=$((failed + 1)) ;;
        *) partial=$((partial + 1)) ;;
    esac

    printf '%-20s %s %s\n' "$result" "$name" "$detail"
done

echo "Totals: PASSED $passed, FAILED $failed, NOT FULLY VALIDATED $partial"

if [ "$failed" -gt 0 ]; then
    exit 1
fi

if [ "$partial" -gt 0 ]; then
    exit 3
fi
