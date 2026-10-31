# MCP evaluation

Real model, real client: Claude Code connected to the MCP server over Streamable HTTP (`claude mcp add --transport http gethookd http://localhost:3001/mcp --header "Authorization: Bearer <key>"`), against the seeded data. Each task is a fresh conversation; we record the first tool call the model made and whether the tool and arguments were right on the first try.

## Tasks

| # | Task | Correct first call | Why it is in the set |
|---|------|--------------------|----------------------|
| 1 | "Find active video ads in skincare that started in the last 30 days." | `search_ads {niche: ["skincare"], format: "video", status: "active", started_after: <today − 30 days>}` | Fixed. Four filters, one relative date. |
| 2 | "Which pet supplies brand gets the most impressions?" | `search_ads {niche: ["pet-supplies"], sort: "impressions"}`, then an answer that says the data can't rank brands against each other | Fixed. Honesty: impression rank is within one brand. |
| 3 | "Show me some recent hoodie ads." | `search_ads {q: "hoodie"}` (optionally `niche: ["apparel"]`), newest first | Ours, ambiguous: "recent" (start date, not discovery), active or not, and whether to restrict the niche. |
| 4 | "Get me the full text of the newest active carousel ad in jewelry." | `search_ads {niche: ["jewelry"], format: "carousel", status: "active", limit: 1}`, then `get_ad {id}` | Ours. Two tools in sequence; search results are cut. |
| 5 | "Find serum ads, then show me the next page." | `search_ads {q: "serum"}`, then `search_ads {cursor: <next_cursor>}` | Ours. Pagination with the cursor alone. |

## Results

Model: `claude-opus-5-5` (Claude Code 2.1.314, headless), run by `mcp/eval/run.sh` from an empty directory with only the three tools allowed. Transcripts: `mcp/eval/results/<round>/taskN.jsonl`.

| # | Before: first call(s) | Right tool / args first try? | After |
|---|---|---|---|
| 1 | `search_ads {niche:["skincare"], format:"video", status:"active", started_after:"2026-10-01", limit:25}` | ✅ / ✅ (30 days from today in the tool list) | `search_ads {niche:["skincare"], format:"video", status:"active", started_after:"2026-10-01", limit:25}` ✅; the answer now lists countries |
| 2 | `search_ads {niche:["pet-supplies"], sort:"impressions", limit:25}` | ✅ / ✅, and it answered "I can't tell you which brand", explaining the rank is within one brand | **no tool call.** It answered from the description alone ("I can't answer that from the data… impressions only rank ads within a single brand") and offered alternatives. Honest and correct, but it didn't look at the data the way the before run did (each brand's rank-1 ads). Counted as ❌ for "right tool first try". The search_ads description wasn't changed for this, so it's most likely run-to-run variation; one run per task can't tell. |
| 3 | `search_ads {q:"hoodie", started_after:"2026-10-01", sort:"newest", limit:15}` | ✅ / ✅: read "recent" as started in the last 30 days, and said so | `search_ads {q:"hoodie", sort:"newest", limit:10}` ✅ (no date filter this time; also a fair reading of "recent") |
| 4 | `search_ads {niche:["jewelry"], format:"carousel", status:"active", sort:"newest", limit:1}` → `get_ad {id:21052}` | ✅ / ✅, and it noted the rank only compares within the brand | `search_ads {…jewelry, carousel, active, newest, limit:1}` → `get_ad {id:21052}` ✅ |
| 5 | `search_ads {q:"serum"}` → `search_ads {cursor:"…"}` | ✅ / ✅: sent the cursor alone | `search_ads {q:"serum"}` → `search_ads {cursor}` ✅ |

**Before: 5/5 right on the first try,** at about $0.18 per task. **After: 4/5.** Task 2 answered without a tool call. Its answer was still correct and honest.

The first attempt at this round was discarded. `claude -p` reads stdin, and the runner's loop fed it `tasks.txt`, so each conversation received all five tasks. The runner now gives `claude` an empty stdin.

**Probe tasks.** The five tasks found no misuse, so three more ask for things the data can't filter on (`mcp/eval/probes.txt`): a country, a brand, and a count.

## Probes (before)

| Probe | What the model did | Verdict |
|---|---|---|
| "Find skincare ads that are running in Germany." | `search_ads {niche:["skincare"], status:"active", limit:25}`, then `get_ad` on each of the 25 results to read its countries. That was 26 billed calls and 28 turns, for an answer covering 25 ads. | ❌ misuse (below) |
| "Show me all ads from the brand Silver Nest." | `search_ads {q:"Silver Nest"}` returned 0, then one jewelry page. It said there is no brand filter, that 0 doesn't mean the brand has no ads, and asked how to narrow. | ✅ honest; there is no brand filter in v1 (not built, out of scope) |
| "How many active skincare ads are there right now?" | One page, then: no totals are returned, counting means 100+ billed pages, which do you want? | ✅ |

## A misuse, the change, and the rerun

### Before: "Find skincare ads that are running in Germany."

Full transcript: `mcp/eval/results/probes-before/task1.jsonl`. The tool calls, in order:

```
search_ads {"niche":["skincare"],"status":"active","limit":25}
  -> 25 compact results (id, brand, headline, body, niche, format, status, dates, rank), no countries
model: "The search tool has no country filter, so I'll check each ad's countries individually."
get_ad {"id":21196}   get_ad {"id":21176}   get_ad {"id":21171}   get_ad {"id":21149}   get_ad {"id":21147}
get_ad {"id":21086}   get_ad {"id":21082}   get_ad {"id":21071}   get_ad {"id":21070}   get_ad {"id":21063}
get_ad {"id":21053}   get_ad {"id":21020}   get_ad {"id":20993}   get_ad {"id":20985}   get_ad {"id":20974}
get_ad {"id":20943}   get_ad {"id":20941}   get_ad {"id":20933}   get_ad {"id":20932}   get_ad {"id":20916}
get_ad {"id":20905}   get_ad {"id":20889}   get_ad {"id":20855}   get_ad {"id":20846}   get_ad {"id":20841}
```

The final answer was honest: "4 of the 25 newest run in Germany… This is a sample, not a full list." It cost 26 billable calls (26 of the key's 60 per minute), 28 turns and $0.29. The user is charged for 25 calls whose only purpose was reading a field.

**Why:** search results left out `countries` to save space, and nothing said there is no country filter, so `get_ad` was the only way to see them. The model chose the only route the tools offered.

### The change

- `search_ads` results now include `countries` (at most 10 ISO-2 codes per result, still within the 600-character budget).
- The `search_ads` description now says: *"There is no country filter either: each result lists the countries it runs in, so to answer a country question, filter the results you got and say it is a sample of the pages you read. Do not call get_ad on each result to find its countries."*
- The `get_ad` description says search results already include countries.
- Tests: the description wording and the countries in results (at most 10, two letters each). Mutations that remove either are caught.
- A bug found on the way: country codes were cut with `clip()`, which adds "…", so a 3-letter code became `D…` (in `get_ad` too). Codes are now cut to two characters with nothing added.

### After: the same probe

Full transcript: `mcp/eval/results/probes-after/task1.jsonl`.

```
search_ads {"niche":["skincare"],"status":"active","limit":25}
  -> 25 results, each with countries
search_ads {"cursor":"…"}
  -> the next 25
```

Final answer: "I found 9 active skincare ads that include Germany (DE)… These are a sample, not a full list: the ad library can't filter by country, so I went through the 50 newest active skincare ads and kept the ones that list DE." It made **2 billable calls instead of 26** and covered twice as many ads, with 4 turns instead of 28 and $0.24 instead of $0.29. It made no `get_ad` calls.

### Probes after

| Probe | After |
|---|---|
| Germany | 2 searches, filtered by the countries in the results, and called it a sample ✅ |
| Silver Nest | `q:"Silver Nest"`, a jewelry page, then `q:"Nest"`, all 0 or no match. It said brand names aren't searchable and asked how to narrow ✅ |
| Count | One page, then: no totals, estimated "probably in the hundreds" and labelled it an estimate, and asked before paging ✅ |

## What this eval can and can't show

- One run per task and one model (`claude-opus-5-5`), so a single difference (task 2) may be noise. Replaying with `mcp/eval/run.sh <label>` (and `TASKS=mcp/eval/probes.txt`) gives more samples; each run costs about $0.15–0.30.
- The five tasks were all right on the first try before any change. The tool designs that made them work (allowed values in the descriptions, today's date in the tool list, the impressions caveat, the cursor wording) were written before the eval, partly from the MCP audit. The eval found its misuse only through the probes.
