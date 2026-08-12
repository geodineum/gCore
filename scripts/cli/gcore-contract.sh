#!/bin/bash
set -euo pipefail

# Print the gCore integration contract (CONTRACT.md). Sourced by
# `geodineum gcore contract`.

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    echo "Usage: geodineum gcore contract"
    echo ""
    echo "Print the gCore integration contract (CONTRACT.md)."
    exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
CONTRACT="${REPO_ROOT}/CONTRACT.md"

if [[ -r "$CONTRACT" ]]; then
    cat "$CONTRACT"
else
    echo "Error: contract not found at ${CONTRACT}" >&2
    echo "This component may be incompletely deployed." >&2
    exit 1
fi
