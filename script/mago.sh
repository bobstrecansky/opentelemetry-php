#!/usr/bin/env bash
set -uo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Error: 'docker' is not installed or not in PATH."
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="${SCRIPT_DIR}/.."
MAGO_IMAGE="ghcr.io/carthage-software/mago"
MAGO_DOCKER=(docker run --rm -v "${ROOT_DIR}:/app" -w /app "${MAGO_IMAGE}")

echo "Running Mago formatting check..."
"${MAGO_DOCKER[@]}" format --check
format_exit=$?

echo "Running Mago linting..."
"${MAGO_DOCKER[@]}" lint --reporting-format=github
lint_exit=$?

echo "Running Mago static analysis..."
"${MAGO_DOCKER[@]}" analyze --reporting-format=github
analyze_exit=$?

echo "All Mago checks completed."

if [[ $format_exit -ne 0 || $lint_exit -ne 0 || $analyze_exit -ne 0 ]]; then
  echo "One or more Mago checks failed."
  echo "format=${format_exit} lint=${lint_exit} analyze=${analyze_exit}"
  exit 1
fi
