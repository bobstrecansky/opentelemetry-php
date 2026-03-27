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

echo "Running Mago linting..."
"${MAGO_DOCKER[@]}" lint
lint_exit=$?

echo "Mago checks completed."

if [[ $lint_exit -ne 0 ]]; then
  echo "Mago linting failed."
  exit 1
fi
