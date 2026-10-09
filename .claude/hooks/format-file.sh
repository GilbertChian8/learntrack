#!/usr/bin/env bash
# PostToolUse hook: format the file Claude just edited or wrote.
# .php goes through Pint, .ts and .tsx through Prettier. Other files are left alone.
set -euo pipefail

file=$(jq -r '.tool_input.file_path // empty')

if [ -z "$file" ] || [ ! -f "$file" ]; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

case "$file" in
    *.php) vendor/bin/pint "$file" ;;
    *.ts | *.tsx) npx prettier --write "$file" ;;
esac
