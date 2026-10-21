#!/usr/bin/env bash
# Token report for a Claude Code session: what each tool call added to the context, and totals per tool type
# (code-map vs grep vs read). Works on the session transcripts Claude Code writes to ~/.claude/projects/.
#
#   bench/session-tokens.sh                     latest session of the project in the current directory
#   bench/session-tokens.sh ~/Docker/nrg-core   latest session of that project
#   bench/session-tokens.sh <session>.jsonl     a specific transcript
set -euo pipefail
export LC_ALL=C

target="${1:-$PWD}"
if [[ -f "$target" ]]; then
  file="$target"
else
  dir="$HOME/.claude/projects/$(cd "$target" && pwd | sed 's|[^A-Za-z0-9]|-|g')"
  file="$(ls -t "$dir"/*.jsonl 2>/dev/null | head -1 || true)"
  [[ -n "$file" ]] || { echo "No Claude Code sessions found in $dir" >&2; exit 1; }
fi
echo "Session: $file"
echo

jq -rs '
  def category: if startswith("mcp__code-map__") then "code-map"
    elif . == "Grep" or . == "Glob" then "grep/glob"
    elif . == "Read" then "read"
    elif . == "Bash" then "bash"
    else "other" end;

  # Streaming writes one line per content block with the same message id; keep one entry per message.
  (map(select(.type == "assistant")) | group_by(.message.id) | map({
      usage: (last.message.usage),
      tools: [ .[].message.content[]? | select(.type == "tool_use") | {id, name, input: (.input | tostring)} ]
    })) as $turns
  | (map(select(.type == "user") | .message.content | arrays | .[] | select(.type == "tool_result")
      | {key: .tool_use_id, value: (.content | if type == "array" then (map(.text // "") | join("")) else tostring end | length)})
    | from_entries) as $sizes
  | [ $turns[] | .tools[] | . + {chars: ($sizes[.id] // 0), cat: (.name | category)} ] as $calls

  | "Tool calls (result size ≈ tokens added to the context; 1 token ≈ 4 chars):",
    "",
    ( $calls[] | "  \(.cat | . + "          " | .[0:10])  \(.name | sub("mcp__code-map__"; "code-map:") | . + "                         " | .[0:26])  \(.chars | tostring | "       " + . | .[-7:]) chars ≈ \((.chars / 4 | floor) | tostring | "     " + . | .[-6:]) tok   \(.input | .[0:70])" ),
    "",
    "Per tool type:",
    ( $calls | group_by(.cat)[] | "  \(.[0].cat | . + "          " | .[0:10])  \(length) call(s), \(map(.chars) | add) chars ≈ \(map(.chars) | add / 4 | floor) tokens" ),
    "",
    "Per turn (every turn re-sends the whole context; cached reads are billed at ~10%):",
    ( $turns | to_entries[] | .value.usage as $u
      | "  turn \(.key + 1 | tostring | "  " + . | .[-2:])  context \((($u.input_tokens // 0) + ($u.cache_read_input_tokens // 0) + ($u.cache_creation_input_tokens // 0)) | tostring | "       " + . | .[-7:])  new \(($u.cache_creation_input_tokens // 0) | tostring | "      " + . | .[-6:])  out \(($u.output_tokens // 0) | tostring | "     " + . | .[-5:])  \([.value.tools[].name | sub("mcp__code-map__"; "code-map:")] | join(", "))" ),
    "",
    "Totals: \($turns | length) turns, \($turns | map(.usage.cache_read_input_tokens // 0) | add) cached-read, \($turns | map(.usage.cache_creation_input_tokens // 0) | add) newly-written, \($turns | map(.usage.output_tokens // 0) | add) output tokens"
' "$file"
