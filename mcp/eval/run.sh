#!/usr/bin/env bash
# Replays the MCP_EVAL.md tasks (tasks.txt, one per line) through Claude Code in headless mode,
# each in a fresh conversation, against the local MCP server. Transcripts (stream-json) go to
# results/<label>/taskN.jsonl.
#
# Usage: GETHOOKD_KEY=<a search:read key> mcp/eval/run.sh <label>      e.g. before, after
# Optional: MCP_URL (default http://localhost:3001/mcp), EVAL_MODEL (default: Claude Code's default),
#           TASKS (another task file, e.g. probes.txt), EVAL_MAX_TURNS (default 15, caps cost if a model loops)
set -euo pipefail

label=${1:?usage: GETHOOKD_KEY=<key> run.sh <label>}
: "${GETHOOKD_KEY:?set GETHOOKD_KEY to a search:read key}"

here=$(cd "$(dirname "$0")" && pwd)
out="$here/results/$label"
mkdir -p "$out"

# Run from an empty directory, so the model sees only the MCP tools, not this repo's code or CLAUDE.md.
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
umask 077
printf '{"mcpServers":{"gethookd":{"type":"http","url":"%s","headers":{"Authorization":"Bearer %s"}}}}\n' \
  "${MCP_URL:-http://localhost:3001/mcp}" "$GETHOOKD_KEY" > "$work/mcp.json"

n=0
while IFS= read -r task || [ -n "$task" ]; do
  [ -z "$task" ] && continue
  n=$((n + 1))
  echo "Task $n: $task"
  (cd "$work" && claude -p "$task" \
    --mcp-config "$work/mcp.json" --strict-mcp-config \
    --allowedTools "mcp__gethookd__search_ads,mcp__gethookd__get_ad,mcp__gethookd__list_niches" \
    --output-format stream-json --verbose --max-turns "${EVAL_MAX_TURNS:-15}" \
    ${EVAL_MODEL:+--model "$EVAL_MODEL"} < /dev/null) > "$out/task$n.jsonl"
  # (stdin is /dev/null: claude -p reads stdin, and here stdin is tasks.txt, so the first task
  # would swallow all the others into its prompt.)
done < "${TASKS:-$here/tasks.txt}"

if grep -rqF "$GETHOOKD_KEY" "$out"; then
  echo "WARNING: the API key appears in $out; do not commit these files." >&2
fi
echo "Transcripts in $out"
