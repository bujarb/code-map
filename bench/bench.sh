#!/usr/bin/env bash
# Runs the same prompts through Claude Code with and without the code-map MCP server and compares
# tokens, cost, turns and tool calls. Read-only: the agent can't edit files or run shell commands.
#
#   bench/bench.sh --project ~/Docker/nrg-core [--runs 3] [--model sonnet] [--prompts bench/prompts.tsv]
#                  [--url http://localhost:8765/mcp] [--only lookup,impact] [--hint]
#
# --hint gives the "with" runs a one-line instruction to use code-map, like the line you'd put in AGENTS.md.
# Without it, the agent only uses code-map if it decides to on its own (e.g. via the code-map skill).
#
# Needs: claude CLI (override with CLAUDE_BIN), jq, and a running code-map container indexing the project.
set -euo pipefail
# Decimal point, not the locale's comma, so awk/printf read and print costs correctly.
export LC_ALL=C

here="$(cd "$(dirname "$0")" && pwd)"
project=""
runs=3
model=""
prompts="$here/prompts.tsv"
url="http://localhost:8765/mcp"
only=""
hint=""
claude="${CLAUDE_BIN:-claude}"
hint_text="This PHP project has the code-map MCP server connected (mcp__code-map__* tools). Use it instead of grep for usages, implementations, routes/handlers and class outlines. Short class names work, so call the tool directly; one call is usually the full answer. Read files only at the file:line it returns and don't re-check its results with grep."

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) project="$2"; shift 2 ;;
    --runs) runs="$2"; shift 2 ;;
    --model) model="$2"; shift 2 ;;
    --prompts) prompts="$2"; shift 2 ;;
    --url) url="$2"; shift 2 ;;
    --only) only=",$2,"; shift 2 ;;
    --hint) hint=1; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$project" && -d "$project" ]] || { echo "--project <dir> is required" >&2; exit 2; }
command -v "$claude" >/dev/null || { echo "claude CLI not found (set CLAUDE_BIN)" >&2; exit 2; }
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }
health="${url%/mcp}/health"
curl -fsS "$health" >/dev/null || { echo "code-map is not reachable at $health; start the container first" >&2; exit 2; }

# One cheap call up front, so a missing login fails here instead of in every run.
probe="$("$claude" -p "Reply with: ok" --strict-mcp-config --output-format json < /dev/null 2>&1 || true)"
if ! echo "$probe" | jq -e '.is_error == false' >/dev/null 2>&1; then
  echo "claude can't run prompts: $(echo "$probe" | jq -r '.result // empty' 2>/dev/null || echo "$probe")" >&2
  echo "Run \`claude\` once and use /login, then retry." >&2
  exit 2
fi

out="$here/results/$(date +%Y%m%d-%H%M%S)${hint:+-hint}"
mkdir -p "$out/raw" "$out/answers"
# alwaysLoad keeps the tools out of Claude Code's ToolSearch deferral (the server also asks for this per tool).
printf '{"mcpServers":{"code-map":{"type":"http","url":"%s","alwaysLoad":true}}}\n' "$url" > "$out/with.json"
printf '{"mcpServers":{}}\n' > "$out/without.json"

csv="$out/results.csv"
echo "prompt,mode,run,ok,turns,input,cache_write,cache_read,output,total_tokens,cost_usd,duration_s,codemap_calls,read_calls,search_calls,other_calls,skill_used" > "$csv"

echo "Results: $out"
echo

# Sets $args for one claude run; identical for the warm-up and the measured runs so they share a cache prefix.
build_args() {
  local mode="$1" prompt="$2" allowed="Read,Grep,Glob"
  [[ "$mode" == with ]] && allowed="$allowed,mcp__code-map"
  args=(-p "$prompt" --strict-mcp-config --mcp-config "$out/$mode.json"
        --allowedTools "$allowed" --disallowedTools "Edit,Write,NotebookEdit,Bash"
        --output-format stream-json --verbose)
  [[ -n "$model" ]] && args+=(--model "$model")
  [[ -n "$hint" && "$mode" == with ]] && args+=(--append-system-prompt "$hint_text")
  return 0
}

# Claude caches the prompt prefix (system prompt + tool definitions) for a while. Without a warm-up, whichever
# mode changed most recently pays a full cache write on its first run, which skews single runs heavily.
echo "Warming up the prompt cache for both modes..."
for mode in with without; do
  build_args "$mode" "Reply with: ok"
  (cd "$project" && "$claude" "${args[@]}") < /dev/null > "$out/raw/warmup-$mode.jsonl" 2>&1 || true
done
echo

# Alternate with/without per run so drift (API load, cache warmth) affects both modes equally.
while IFS=$'\t' read -r id prompt; do
  [[ -z "$id" || "$id" == \#* ]] && continue
  [[ -n "$only" && "$only" != *",$id,"* ]] && continue

  for ((run = 1; run <= runs; run++)); do
    for mode in with without; do
      raw="$out/raw/$id-$mode-$run.jsonl"
      printf '%-10s %-8s run %d ... ' "$id" "$mode" "$run"
      build_args "$mode" "$prompt"

      # </dev/null: claude -p appends stdin to the prompt, and stdin here is the prompts file being looped over.
      (cd "$project" && "$claude" "${args[@]}") < /dev/null > "$raw" 2>"$raw.err" || true

      # The last "result" event carries usage and cost; assistant events carry the tool calls.
      row="$(jq -rs --arg id "$id" --arg mode "$mode" --arg run "$run" '
        (map(select(.type == "result")) | last // {}) as $r
        | [ .[] | select(.type == "assistant") | .message.content[]? | select(.type == "tool_use") ] as $uses
        | ($uses | map(.name)) as $tools
        | ($r.usage // {}) as $u
        | [ $id, $mode, $run,
            (if ($r | length) > 0 and ($r.is_error | not) then "yes" else "no" end),
            ($r.num_turns // 0),
            ($u.input_tokens // 0), ($u.cache_creation_input_tokens // 0), ($u.cache_read_input_tokens // 0), ($u.output_tokens // 0),
            (($u.input_tokens // 0) + ($u.cache_creation_input_tokens // 0) + ($u.cache_read_input_tokens // 0) + ($u.output_tokens // 0)),
            ($r.total_cost_usd // 0),
            ((($r.duration_ms // 0) / 1000) | floor),
            ($tools | map(select(startswith("mcp__code-map"))) | length),
            ($tools | map(select(. == "Read")) | length),
            ($tools | map(select(. == "Grep" or . == "Glob")) | length),
            ($tools | map(select((startswith("mcp__code-map") or . == "Read" or . == "Grep" or . == "Glob") | not)) | length),
            (if ($uses | any(.name == "Skill" and ((.input.skill // .input.name // "") | tostring | test("code-map")))) then "yes" else "no" end)
          ] | @csv' "$raw" 2>/dev/null || true)"

      if [[ -z "$row" ]]; then
        row="\"$id\",\"$mode\",\"$run\",\"no\",0,0,0,0,0,0,0,0,0,0,0,0,\"no\""
      fi
      echo "$row" >> "$csv"
      jq -rs 'map(select(.type == "result")) | last | .result // "(no result)"' "$raw" > "$out/answers/$id-$mode-$run.md" 2>/dev/null || true

      echo "$row" | awk -F, '{ gsub(/"/, ""); printf "%s, %d turns, %d tokens, $%.4f, %ds, code-map calls: %d, skill: %s\n", ($4 == "yes" ? "ok" : "FAILED"), $5, $10, $11, $12, $13, $17 }'
    done
  done
done < "$prompts"

echo
echo "Averages per prompt (successful runs only):"
echo
awk -F, '
  NR == 1 { next }
  { gsub(/"/, "") }
  $4 != "yes" { failed[$1 "," $2]++; next }
  {
    k = $1 "," $2; n[k]++
    turns[k] += $5; tok[k] += $10; cost[k] += $11; dur[k] += $12; cm[k] += $13; rd[k] += $14; sr[k] += $15; sk[k] += ($17 == "yes")
    if (!($1 in seen)) { seen[$1] = 1; order[++p] = $1 }
  }
  END {
    printf "| %-8s | %-7s | %4s | %6s | %10s | %8s | %6s | %9s | %5s | %6s | %5s |\n", "prompt", "mode", "runs", "turns", "tokens", "cost $", "time s", "code-map", "reads", "greps", "skill"
    printf "|----------|---------|------|--------|------------|----------|--------|-----------|-------|--------|-------|\n"
    for (i = 1; i <= p; i++) {
      for (m = 1; m <= 2; m++) {
        mode = (m == 1 ? "with" : "without"); k = order[i] "," mode
        if (!n[k]) { printf "| %-8s | %-7s | %4s | %6s | %10s | %8s | %6s | %9s | %5s | %6s | %5s |\n", order[i], mode, "0", "-", "-", "-", "-", "-", "-", "-", "-"; continue }
        printf "| %-8s | %-7s | %4d | %6.1f | %10d | %8.4f | %6.0f | %9.1f | %5.1f | %6.1f | %5s |\n", order[i], mode, n[k], turns[k]/n[k], tok[k]/n[k], cost[k]/n[k], dur[k]/n[k], cm[k]/n[k], rd[k]/n[k], sr[k]/n[k], sk[k] "/" n[k]
      }
      w = order[i] ",with"; o = order[i] ",without"
      if (n[w] && n[o] && cost[o] > 0) {
        printf "|          | savings |      | %5.0f%% | %9.0f%% | %7.0f%% | %5.0f%% |           |       |        |       |\n", \
          100 * (1 - (turns[w]/n[w]) / (turns[o]/n[o])), 100 * (1 - (tok[w]/n[w]) / (tok[o]/n[o])), \
          100 * (1 - (cost[w]/n[w]) / (cost[o]/n[o])), 100 * (1 - (dur[w]/n[w]) / (dur[o]/n[o]))
      }
    }
    for (k in failed) printf "\n%d failed run(s) for %s (see raw/*.err)", failed[k], k
    print ""
  }' "$csv" | tee "$out/summary.md"

echo
echo "Check correctness too: compare answers in $out/answers/ (same prompt, with vs without)."
