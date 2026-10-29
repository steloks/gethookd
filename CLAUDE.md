# CLAUDE.md

Instructions for AI coding agents working on this repo. Source of truth: the GetHookd "Fullstack Engineer (API & MCP)" assessment PDF. If this file and the spec disagree, the spec wins — flag the conflict, don't silently pick one.

## Goal

Build a v1 ad-search API and an MCP server that fix the v0 problems for AI-agent callers, without breaking v0. 8-hour time box: finish every MUST before any SHOULD/COULD. 70% done carefully beats 100% done thinly.

### Mandatory (MUST)

- **Part 0 — World:** `ads` data model; deterministic seed (≥20k ads, ≥300 brands, all 12 niches, ~60% active, start dates over last 2 years, ~40% null `impression_rank`; same data on every run); live ingest script (every second: insert 1–5 ads, deactivate 1–2; ~30% of new ads have `start_date` up to 90 days in the past); v0 endpoint exactly as specified, quirks included; v0 contract test.
- **Part 1 — v1 API:** keyword search + filters `niche` (multi), `format`, `status`, `started_after`; sorts `newest` and `impressions` (nulls always last, deterministic tiebreak); reject unknown params/invalid values with allowed values + "did you mean"; echo applied (normalised) filters; cursor pagination stable under live ingest for both sorts; one error shape for every status; OpenAPI spec kept true by a test; one test per filter proving it changes results.
- **Part 2 — MCP:** `search_ads`, `get_ad`, `list_niches` on top of v1, sharing auth/rate limit/metering; model-friendly descriptions, schemas, compact responses with a stated size budget, actionable errors (rate-limit errors say how long to wait); real-model eval of 5 tasks → `MCP_EVAL.md`.
- **Part 3 — Keys & metering:** keys hashed, shown once, visible prefix, scopes (`search:read`, `keys:manage`), revocable (fails on the very next call, REST and MCP); 60 req/min per key shared across v0/v1/MCP with accurate `Retry-After`; written billable-call rule counted exactly; same `Idempotency-Key` within 24h billed once; concurrency test proving exact count.
- **Part 4 — Docs & UI:** React developer page (create key with secret shown once + copy button, list keys with prefix/scopes/created/last used, revoke, usage vs. quota read from the billing meter — no second counter); `DESIGN.md`, `AI_NOTES.md`, `README.md`.

SHOULD: Bearer auth on v1 (same key as v0 query param), 1,000/month quota with a distinct non-retryable error stating reset time, "Connect your AI tool" panel.

## Stack

- Laravel 13 / PHP 8.4 — v0/v1 REST API, keys, metering, ingest command
- React + Vite — developer page
- Node.js 22 + TypeScript + `@modelcontextprotocol/sdk` — MCP server (Streamable HTTP), a separate service that calls v1 over HTTP
- PostgreSQL 16 — primary DB (row locks and unique constraints for exact metering); tests use the separate `gethookd_test` database, never SQLite
- Redis 7 (via `predis`) — rate limiter and cache
- Pest 5 (Laravel) and Vitest (MCP server) — tests
- Docker Compose + Makefile — one-command startup

## Project structure (planned)

```
api/                           Laravel app
  app/Http/Controllers/Api/V0/   v0 controller — frozen
  app/Http/Controllers/Api/V1/   v1 controllers
  app/Http/Middleware/           auth, rate limit, metering (shared by v0 and v1)
  app/Search/                    search service, cursor codec, filter validation
  app/Support/Errors/            error code registry + single error renderer
  app/Console/Commands/          seed, ingest
  database/migrations, database/seeders
  resources/js/                  React developer page (Vite)
  openapi/                       OpenAPI spec
  tests/Feature/{V0,V1,Keys,Metering}
mcp/                           Node.js MCP server
  src/server.ts                  transport + tool registration
  src/tools/                     search_ads, get_ad, list_niches
  src/v1Client.ts                HTTP client for v1 (forwards caller's key)
  test/                          Vitest tests
docker/                        container config (postgres init creates gethookd_test)
docker-compose.yml, Makefile
```

Host ports: Postgres `55432`, Redis `56379` (non-default to avoid clashing with other local stacks).

## Architecture and compatibility rules

- **v0 is frozen.** Never change its behaviour, response shape, or quirks (ignored unknown params, ignored invalid values, `per_page` clamp to 100, no sort tiebreaker, `{"error":"Invalid request"}` 400 for every error). The v0 contract test must pass at all times. Do not "fix" v0 — fixes go in v1.
- **`days_active` is derived, never stored.** Active: `today − start_date`; inactive: `end_date − start_date`, whole days. One shared implementation used by v0, v1, and MCP.
- `end_date` is null if and only if `status = active`.
- `impression_rank` compares ads within one brand only. Never present it as a cross-brand ranking; MCP tool descriptions must say so.
- **The MCP server is a thin client of v1.** Every tool call becomes a v1 HTTP request carrying the caller's key as `Authorization: Bearer`. It never touches the database, never validates filters itself, never caches responses, and never holds its own key. That is how auth, scopes, revocation, rate limits, and metering are shared with REST. It formats and trims v1 responses to the stated size budget.
- MCP requests to v1 identify themselves (e.g. a `X-Client: mcp` header) so usage can be attributed and the "first MCP call arrived" check works. Billing does not depend on this header.
- MCP tool-call retries are deduplicated by forwarding an `Idempotency-Key` to v1; the rule is documented in `DESIGN.md`.
- **One error shape** everywhere (REST 401/403/404/422/429/500 and MCP): `code`, `message`, `next_step`, `retryable` (+ `retry_after` when relevant). Error codes live in one registry in Laravel; the MCP server passes v1 error codes through unchanged and only adds model-facing wording (e.g. the corrected call). It defines its own codes only for failures that never reach v1 (e.g. `upstream_unavailable`).
- **Cursor pagination** is keyset-based on the sort key + `id` tiebreak, plus a snapshot bound (e.g. max id / `discovered_at` at first page) so new ads don't shift pages. The guarantees about inserts and deactivations during a walk must be written in the README and proved by a test.
- **Auth, rate limit, metering** are Laravel middleware applied identically to v0 and v1; MCP inherits them by calling v1. One rate-limit bucket per key. One meter: the quota display reads the same table that billing counts.
- Billable-call rule must be written down in the README/DESIGN.md before it is implemented.
- Keep the OpenAPI spec in sync with v1 — a contract test enforces it.

## Running

```
make up        # build, start containers (api, mcp, postgres, redis, vite), migrate, seed — from a clean clone
make ingest    # run the live ingest script (Ctrl-C to stop)
make test      # run the full test suite (Pest + Vitest)
make test-api  # Laravel tests only
make test-mcp  # MCP server tests only
make seed      # re-run the deterministic seed
```

Every target must work from a fresh `git clone`. If you add a step, add it to the Makefile and README.

## Testing rules

- Every feature gets tests in the same change. No feature is done without them.
- Tests must **fail if the feature breaks.** For each new test, briefly break the code (comment out the filter, drop the tiebreaker, skip the idempotency check) and confirm the test goes red, then restore it. Note this in the commit or `AI_NOTES.md` where relevant.
- Required tests in particular: v0 contract (quirks included); one per v1 filter that fails if the filter is silently ignored; cursor walk with concurrent inserts/deactivations (no duplicates, no skips); impressions sort with nulls last; `days_active` matches between v0 and v1; error shape for each status; OpenAPI conformance; MCP tools forward the caller's key and pass v1 error codes through unchanged; MCP responses stay within the size budget; key revocation takes effect on the next call (REST and MCP); rate limit + `Retry-After`; concurrent requests produce an exact billed count; idempotency-key dedupe.
- Run `make test` (or the targeted test) after every change, and read the output.
- **Never claim tests pass without running them in this session.** If a test was not run, or fails, say so and show the output. Don't skip, weaken, or delete a failing test to get green — fix the code or report the problem.

## Security and secrets

- API keys: generate with a CSPRNG, store only a hash (SHA-256 of a high-entropy key is fine), return the plaintext exactly once at creation. Store and display only the prefix afterwards.
- Compare key hashes in constant time. Check revocation and scopes on every request — no caching that delays revocation.
- Never log full keys, `Authorization` headers, or `api_key` query values. Redact them in logs, error messages, and exceptions.
- 500 responses use the standard error shape and never leak stack traces, SQL, or secrets.
- No real secrets in the repo. Commit `.env.example` only; `.env` stays gitignored. Local dev credentials must be obviously fake.
- Use parameterised queries / the query builder only — no string-built SQL from user input (including `q` and sort fields).
- Synthetic data only. Never scrape or use a real ad library.

## Working rules

- Follow the assessment spec exactly. When it is ambiguous, make a reasonable assumption and record it in the README "Assumptions" section.
- Small, focused commits; never squash. Don't commit or push unless asked.
- Don't add features beyond the spec until all MUSTs are done.
- Keep `README.md`, `DESIGN.md`, `MCP_EVAL.md`, `AI_NOTES.md` up to date as you go, not at the end.
