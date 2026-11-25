#!/usr/bin/env bash
# Copy the shared guardrails, references and helper script into every skill,
# so each skill stays self-contained when installed or packaged on its own.
# Edit files under shared/ only, then run this script.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
for skill in "$root"/skills/acs-*/; do
  mkdir -p "$skill/references" "$skill/scripts"
  cp "$root/shared/guardrails.md" "$root/shared/browser-tools.md" "$root/shared/output-structure.md" "$skill/references/"
  cp "$root/shared/acs_tool.py" "$skill/scripts/acs_tool.py"
  chmod +x "$skill/scripts/acs_tool.py"
done
echo "synced shared files into: $(ls -d "$root"/skills/acs-*/ | xargs -n1 basename | tr '\n' ' ')"
