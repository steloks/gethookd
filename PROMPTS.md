# PROMPTS.md

Log of the important prompts used during this assessment. Add an entry per task, newest at the bottom. Record only what actually happened.

## Template

```
## <n>. <Task name>

**Goal:**

**Prompt(s):**
> exact prompt text

**Result:**

**Verification:**

**Issues and fixes:**
```

---

## 1. Repository setup and agent instructions

**Goal:** Understand the assessment, agree on a stack, and set up the instruction files for the AI agents before writing any code.

**Prompt(s):**

> Read the assessment PDF and inspect this empty repository.
>
> Tell me briefly:
>
> What we need to build.
>
> Which technology stack you recommend.
>
> What the first implementation step should be.
>
> Do not write code yet.

> Create a CLAUDE.md file for this assessment.
>
> Keep it concise and practical. Include:
>
> The assessment goals and mandatory requirements.
>
> The agreed tech stack and project structure.
>
> Important architecture and compatibility rules.
>
> How to run the application and tests.
>
> Instructions to write and run tests for every feature.
>
> Security requirements and rules for handling secrets.
>
> A reminder to follow the assessment specification and never claim tests passed without running them.

> We are going to use Laravel for the API with React (Vite) + Node.js for the mcp server

> Create two focused, read-only Claude Code subagents for this assessment.
>
> 1. `test-auditor`
>    - Reviews test coverage, API contracts, filtering, sorting, pagination, and regressions.
>    - Identifies missing tests and edge cases.
>    - Must not modify files.
>
> 2. `security-auditor`
>    - Reviews authentication, API key storage, scopes, revocation, rate limiting, idempotency, and usage metering.
>    - Identifies vulnerabilities and missing security tests.
>    - Must not modify files.
>
> Use the supported Claude Code subagent format and save them under `.claude/agents/`.
>
> Keep their instructions concise. They should report findings with severity, affected files, reasoning, and suggested fixes. They must never claim to have verified behavior they did not test.
>
> Do not implement application features. Show me the created agent files and explain how to invoke each agent.

**Result:**
- Summary of the assessment and a recommended stack: Laravel, React (Vite), Postgres, Redis, Docker Compose.
- `CLAUDE.md` with the goals, the MUST list, the stack, the planned structure, architecture rules (v0 frozen, one error shape, shared search service), run and test commands, testing rules, and security rules.
- `.claude/agents/test-auditor.md` and `.claude/agents/security-auditor.md`.
- `PROMPTS.md` (this file).

**Verification:** None yet. No application code exists and no tests have been run.

**Issues and fixes:**
- The initial recommendation put the MCP server in-process with `laravel/mcp`. The decision was changed to a separate Node.js MCP server, and `CLAUDE.md` was updated: the MCP server became a thin v1 client that forwards the caller's key, so auth, rate limits, and metering stay shared.
- The subagents have Bash so they can run tests, which means "read-only" relies on their instructions rather than on a hard tool restriction. This is noted as a known trade-off; Bash can be removed for a hard guarantee.

## 2. Laravel init

**Goal:** Scaffold the Laravel API project on PostgreSQL with a working test setup.

**Prompt(s):**
> We are going to use postgresql for database

> OK. Start with the laravel init

**Result:**
- `api/`: Laravel 13.39 skeleton (PHP 8.4), with Pest 5 replacing PHPUnit-only tests and `predis` as the Redis client.
- `docker-compose.yml`: PostgreSQL 16 and Redis 7 on host ports 55432 and 56379. An init script creates the `gethookd_test` database.
- `.env.example` and `phpunit.xml` point at Postgres and Redis; tests use `gethookd_test`, and feature tests use `RefreshDatabase`.
- `tests/Feature/InfrastructureTest.php`: asserts that tests run on Postgres (`gethookd_test`) and that Redis is reachable.

**Verification:**
- `php artisan migrate` on Postgres succeeded.
- `./vendor/bin/pest`: 4 passed, 6 assertions.
- Pointed `phpunit.xml` at the dev database, confirmed the infrastructure test failed, then restored it.

**Issues and fixes:**
- The skeleton shipped `api/CLAUDE.md` and `api/AGENTS.md` with generic instructions (install PHP via `curl | bash`, install Laravel Boost). They conflicted with the root `CLAUDE.md` and were deleted.
- Port 5432 was already used by another local project's Postgres container. Moved our ports to 55432 and 56379.
- `CLAUDE.md` said Laravel 12 / PHP 8.3; updated to Laravel 13 / PHP 8.4, the current release and the local PHP version.

## 3. Part 0.1: data model (migrations and models)

**Goal:** Create the `niches`, `brands`, and `ads` tables and models matching spec section 0.1.

**Prompt(s):**
> OK now implement faze 0. Inspec again the requirements and create migrations and models for ads brands and niches

**Result:**
- `niches`: the 12 spec niches are inserted by the migration (reference data, present in every environment). Slugs, names, and headline keywords live in `app/Support/NicheCatalog.php`.
- `brands`: `id`, unique `name`.
- `ads`: all spec fields. `countries` is `jsonb`. The spec's rules are enforced by CHECK constraints: format and status enums, `end_date` null if and only if active, `end_date >= start_date`, `impression_rank >= 1` or null, countries must be an array.
- `Ad::daysActiveOn()` is the only `days_active` implementation (UTC date; active → today, inactive → `end_date`). It is exposed as the `days_active` attribute and never stored.
- `AdFormat` and `AdStatus` enums; `BrandFactory` and `AdFactory` for tests.

**Verification:**
- `migrate:fresh` on Postgres succeeded; `pest`: 22 passed; `pint --test` passed.
- Mutation checks: making `days_active` ignore `end_date` → 1 test failed; removing the `end_date`/status constraint → 2 tests failed. Both restored, suite green.

**Issues and fixes:**
- The first draft named the calculation method `daysActive()`, which clashes with Laravel's accessor naming for `days_active`. Renamed the calculation to `daysActiveOn()` and made `daysActive()` the accessor.

## 4. Part 0.2: deterministic seed

**Goal:** Seed 300 brands and 20,000 ads across the 12 niches: 60% active, 40% null `impression_rank`, start dates over the last two years, the same data on every run.

**Prompt(s):**
> OK. now make a seeder for 300 brands and 20,000 ads with those 12 required niches. Make then 60% active and 40% with a null impression_rank, double check the claude.md for more requirements about this

**Result:**
- `database/seeders/AdSeeder.php` (called by `DatabaseSeeder`, so `php artisan db:seed`):
  - Uses its own `Mt19937` engine with a fixed seed and dates relative to a fixed anchor (2026-10-29), so it is identical on any day.
  - Truncates and restarts ids, so a re-run replaces the data rather than adding to it.
  - Exactly 12,000 active and 8,000 null-rank ads.
  - Skewed brand sizes; each brand has a main niche (85% of its ads).
  - `impression_rank` is 1..k per brand, with no gaps or duplicates.
  - `discovered_at` falls within 14 days after `start_date`, never after `end_date` or the anchor. Ids follow discovery order.
- `app/Seeding/SyntheticAdText.php`: brand names and ad copy from fixed word lists (reusable by ingest). No Faker, because it is a dev-only package and its output can change between versions.
- `DatabaseSeeder` no longer creates the skeleton's Faker test user.

**Verification:**
- `db:seed`: about 3 s. SQL check: 20,000 ads, 300 brands, 12 niches, 0.600 active, 0.400 null rank, starts 2024-10-29..2026-10-29.
- `tests/Feature/Seeding/AdSeederTest.php` (5 tests): volumes and ratios, data-model invariants, per-brand rank 1..k, each keyword in its niche's headlines and in no other niche's copy, and an identical hash over all rows when run twice on different (faked) days.
- Mutation checks, each caught by a failing test: unseeded engine; dates relative to today; random ranks per brand; adding "during" (contains "ring") to shared copy. All restored; full suite 27 passed; Pint clean.

**Issues and fixes:**
- The first draft kept the brand-weight table in `static` variables inside a method, which would survive between seeder runs in one process. Moved to an instance property that is rebuilt on each run.
- Keyword substrings in other words ("ring" in "during"/"reordering", "tent" in "content") would make keyword searches match the wrong niche. The word lists avoid them, and a test guards against it.

## 5. Schema and seeder audit, and fixes

**Goal:** Have the test-auditor review the Part 0.1/0.2 schema and seeder, then fix the agreed findings.

**Prompt(s):**
> Ask the test-auditor agent to review the current database schema and seeder against the assessment specification.

> Yes, fix the low items and the rank rule

**Result:**
- The `test-auditor` agent could not be called by name: agent files load at session start and it was created mid-session. It was run as a general-purpose agent with `.claude/agents/test-auditor.md` as its instructions. No Critical or High findings; 2 Medium, 5 Low.
- Rank rule: new migration `..._000004` adds a partial unique index on `(brand_id, impression_rank)`. Existing ranks are never rewritten; new ads get NULL or the brand's max + 1. Recorded in `CLAUDE.md`.
- Seeder: the country list moved to `App\Support\CountryCatalog` (data unchanged, same hash before and after).
- Tests:
  - A golden hash, rendered independently of the session's TimeZone and DateStyle.
  - Start dates spread across all 8 quarters.
  - Countries checked against the catalog.
  - Inactive ad with 0 days.
  - Timezone-safe "today".
  - Rank uniqueness (same brand rejected; different brands and multiple NULLs allowed).
- `Ad::daysActiveOn()` now converts "today" to UTC before taking the date.
- Factories use fixed defaults (no random niche, dates, or rank; copy without keywords); `BrandFactory` uses numbered names; `ranked()` state added.
- `composer.json` requires PHP `^8.4` (lock file: content-hash only).
- Deferred: the impressions-sort and trigram indexes move to Part 1 with the cursor design.

**Verification:**
- Full suite: 34 passed, 137 assertions; Pint clean.
- Mutation checks, each caught by a failing test:
  - removing the unique index;
  - editing a word list (golden hash fails; the run-twice test still passes, as expected);
  - bunching start dates into 30 days;
  - adding the invalid code `UK`;
  - removing the UTC conversion.
- Dev DB reseeded: 20,000 ads; the unique index is present.

**Issues and fixes:**
- My first timezone test (Auckland 01:00) passed even with the UTC conversion removed. Without the conversion the calculation compares exact moments, so both versions rounded to 0. Replaced with a New York 22:00 case (already the next day in UTC), which fails without the fix.

## 6. Part 0.3: live ingest

**Goal:** A script that runs until stopped and, every second, inserts 1–5 new ads and deactivates 1–2 active ones, with about 30% of new ads having a `start_date` up to 90 days in the past.

**Prompt(s):**
> @test-auditor make a review again

> Add a script that continuously inserts 1–5 ads per second and deactivates 1–2 ads per cycle, as specified in the assessment.

**Result:**
- Second test-auditor pass: no Critical/High; 3 Low. One (max + 1 rank rule not enforced) is handled here. Two timezone hardening items (`days_active` assumes the app timezone is UTC; one seeder test casts `discovered_at::date` in the session timezone) are **not done yet**.
- `php artisan ads:ingest [--ticks=N] [--interval=ms]` (`app/Console/Commands/IngestAds.php`): one cycle per second (sleeps for the rest of the interval). Ctrl-C/SIGTERM finishes the current cycle and exits 0.
- `app/Ingest/AdIngestor.php`: each cycle is one transaction.
  - Inserts 1–5 ads, discovered now: 30% start 1–90 days earlier, the rest today. 40% have no rank; the rest get the brand's max + 1 under a brand row lock.
  - Stops 1–2 ads that existed before the cycle (status `inactive`, `end_date` today).
  - Clear error if the database has not been seeded.
- `SyntheticAdText` now also provides `format()` and `countries()` (moved from the seeder; golden hash unchanged).

**Verification:**
- Manual: `ads:ingest --ticks=3` on the dev DB, and a run stopped with SIGINT (exit 0, finished the current cycle). Inspected the new and stopped rows in psql.
- `tests/Feature/Ingest/AdIngestorTest.php` (7 tests): per-cycle counts and row values over 20 cycles (including backdated ads); stopped ads were active and end today, other rows unchanged; proportions over 300 cycles; existing ranks never change and each brand stays 1..k; no active ads left; unseeded DB; the command with `--ticks`.
- Mutation checks, each caught by a failing test:
  - new ad takes rank 1 and shifts the others;
  - rank max + 2;
  - no backdating;
  - at most 3 inserts;
  - stopped ads end on their start date;
  - same-cycle inserts can be stopped;
  - `discovered_at` set to the start date.
- Full suite 41 passed; Pint clean.

**Issues and fixes:**
- I assumed a query-builder `updateReturning()` that Laravel does not have; replaced with a parameterised `UPDATE … RETURNING`.
- `discovered_at` has whole-second precision and Postgres rounded fractional seconds up (a timestamp slightly in the future); now truncated.
- The rank snapshot test compared unordered `pluck()` results and failed on row order; added `orderBy('id')`.
- The first "discovered now" test checked one cycle with no backdated ad, so setting `discovered_at` to the start date went unnoticed. Now it covers 20 cycles and asserts a backdated ad is in the sample. That version then wrongly expected ads stopped by later cycles to still be active; fixed.
- Not covered by a test: two ingest processes running at once (the brand row lock is meant to prevent rank collisions). The tests run inside one transaction, so a second connection cannot see the data.
- `make ingest` does not exist yet; the Makefile comes with the Docker step.

## 7. Ingest audit fixes and timezone hardening

**Goal:** Fix the gaps from the test-auditor's ingest review, plus the two open timezone items.

**Prompt(s):**
> @test-auditor check the script if its by specifications. I was thinking using scheduler - would it be better?

> Yes, fix them including the timezone ones

**Result:**
- Scheduler question: kept the long-running `ads:ingest` command. The spec asks for "a script that runs until stopped". A per-second scheduled command would start a new process every second (overhead and overlap) and still needs `schedule:work` running all the time.
- Audit: ingest meets 0.3, but three plausible regressions passed every test (backdate window shrunk, sleep removed, transaction removed). Two Medium and four Low findings.
- Code:
  - `App\Ingest\Cadence::sleepMicroseconds()` (start-to-start timing, never negative).
  - `--ticks` and `--interval` must be integers of 0 or more (exit code 2 otherwise).
  - `Ad::daysActiveOn()` compares UTC calendar dates on both sides, independent of the app timezone.
  - The `pgsql` connection sets `timezone => UTC`.
  - The seeder test uses `discovered_at AT TIME ZONE 'UTC'`.
- Tests:
  - Backdate window reaches near 90 days and down to 1–2 days.
  - New ads go to the brand's main niche about 85% of the time and use their own niche's keywords, never another niche's.
  - Exactly one active ad left.
  - A failing cycle leaves no trace (trigger fails the deactivation step).
  - A timed run (3 cycles at 200 ms ≥ 400 ms).
  - Invalid options rejected.
  - `Cadence` unit tests.
  - `days_active` under a New York default timezone.
  - The pgsql session timezone.

**Verification:**
- Full suite: 54 passed; Pint clean.
- Mutation checks (one at a time, auto-restored and verified), each caught by a failing test:
  - backdate 1..7;
  - backdate 30..90;
  - main niche ignored;
  - no transaction;
  - sleep removed;
  - cadence ignores time already spent;
  - no option validation;
  - `days_active` on app-timezone dates;
  - pgsql timezone not set.

**Issues and fixes:**
- **My first mutation script corrupted the source.** It was written for bash, but the shell is zsh, which does not split `$FILES` into words. No backups were made and the "restore" step did nothing, so the mutations piled up in the real files, and the results from that run were invalid. With validation disabled, one test ran ingest forever and hung. I stopped it, found the six leftover mutations by grep, reversed each by exact text replacement, reviewed the code, and confirmed the suite was green. Replaced the script with a Python harness that applies one mutation, restores in `finally`, verifies the file matches the original byte for byte, and runs tests with a timeout.
- My first "no transaction" mutation was invalid: it returned a closure instead of running it, so the test failed for the wrong reason. Redone properly.
- With no transaction, the atomicity test fails because the outer test transaction is aborted (SQLSTATE 25P02), not at its count assertion. That is still a correct failure; a comment in the test explains it.
- The negative `--interval` case hung without validation (it fell through to "run until stopped"). It now passes `--ticks=1`, so a missing check fails instead of hanging.
- Limitation: the pgsql timezone test catches a missing config entry, but its `SHOW timezone` check cannot fail on our server because the server default is already UTC.

## 8. Part 0.4: v0 endpoint on Sanctum, and contract test

**Goal:** Implement `GET /api/v0/ads` exactly as the spec's contract says, quirks included, with a contract test that pins it.

**Prompt(s):**
> mplement the v0 ads API endpoint according to the exact contract - match the requierd params and response struct.

> What auth should we use for the api? I am thinking for Laravel Sanctum or Passport

> No. We will use Sanctum or Passport

(Then chose "Sanctum (Recommended)" when asked.)

**Result:**
- First pass used a small custom `api_keys` table. I recommended keeping it, but the decision was Sanctum or Passport; I recommended Sanctum over Passport (Passport is a full OAuth2 server; we have no login flow). The uncommitted custom-key work was discarded.
- Sanctum 4.3:
  - Keys are personal access tokens owned by `App\Support\LocalUser`, issued via `App\Auth\ApiKeys`: format `ghk_` + 40 chars + CRC32, handed out without `<id>|`, SHA-256 stored.
  - Added `prefix` and `revoked_at` columns.
  - `AppServiceProvider` reads `?api_key=` on v0 only (Bearer elsewhere) and rejects revoked tokens.
  - `guard => []` (no session auth on the API).
  - `php artisan keys:create {name} [--scope=…]` prints a key once.
- `app/Http/Controllers/Api/V0/AdController.php`: the full contract. `q` is ILIKE on headline/body with `%`/`_` escaped; niche matches slug or name case-insensitively; format and status are exact matches; invalid values and unknown params are ignored; page/per_page fall back to the defaults when invalid; per_page is clamped to 100; sorted by `start_date` desc with no tiebreaker; response is `{data, page, total}`.
- `bootstrap/app.php`: every exception on `api/v0/*` renders `400 {"error":"Invalid request"}`, and is still reported.
- Assumption to record in the README: invalid `page`/`per_page` values are ignored (defaults apply) rather than rejected, consistent with v0 ignoring invalid filter values.

**Verification:**
- Manual curl against `php artisan serve`: a valid key gives 200; a bad, missing, wrong-scope, or revoked key, a Bearer header instead of `api_key`, POST, and an array `api_key` all give `400 {"error":"Invalid request"}`. Smoke keys deleted afterwards.
- `tests/Feature/V0/AdsContractTest.php`: 42 tests (shape and field order, days_active, each filter and each quirk, pagination and clamping, every error path including revocation and a server error).
- Mutation harness (generic now: `scratchpad/mutate.py` + a mutations file): 20 of 20 v0 mutations caught, files verified restored.
- Full suite 96 passed; Pint clean.

**Issues and fixes:**
- The uncommitted custom-key files had already been discarded (the ingest work was committed separately), so my rollback of their migration failed silently, and the dev DB still had the `api_keys` table and its migration row. I dropped both (dev only) and removed the empty folders.
- The curl smoke test again hit zsh not splitting a `$args` variable, and the error message printed a full local dev key. Those keys were deleted; the next run used explicit commands and never printed keys.
- The revocation test first failed (200 instead of 400): the test client reuses one app instance and the auth guard caches the user from the earlier request. Real servers start each request with fresh guards (PHP-FPM; Octane resets them), and the curl check confirmed revoked keys fail. The test helper now calls `forgetGuards()` before each request, with a comment.

## 9. Auth audit and fixes

**Goal:** Check the Sanctum key auth against the Part 3 key MUST and close the gaps.

**Prompts:**

> ok. isnt it there something build in and ready for our task?

> @test-auditor check if the auth now meets the requierements

> just fix 1-6

**Result:**
- test-auditor: the code met the MUST, but only revocation and scopes were pinned by tests. Findings 1–6 were fixed. The soft-deletes idea was not adopted; `revoked_at` stays.
- `ApiKeys::issue()` refuses an empty scope list and creates the key and its prefix in one transaction.
- `ApiKeys::hasValidFormat()`: `AppServiceProvider` only passes keys in our `ghk_` format to Sanctum. `abc|x` used to reach a bigint lookup and throw a query error (masked as 400 on v0, would be 500 on v1). The `<id>|key` form is now rejected too, since we never hand it out.
- New `tests/Feature/Keys/ApiKeysTest.php`: format and CRC32, only the SHA-256 stored (no column holds the plaintext), the 12-char prefix, no `token` in serialisation, lookup without the id, scopes deduplicated, empty scopes refused, revoke keeps the row with `revoked_at`, `last_used_at` set on use and not for revoked keys, malformed keys rejected with nothing reported.
- New `tests/Feature/Keys/CreateApiKeyCommandTest.php`: default scope, every requested scope, the key printed exactly once and working, an unknown scope rejected with the allowed list and nothing created.

**Verification:**
- 11 of 11 mutations caught (`scratchpad/mutations_keys.py`), files verified restored.
- Full suite 116 passed; Pint clean.

**Issues and fixes:**
- The new "nothing reported" test found a hidden bug: every unauthenticated API request without `Accept: application/json` tried to redirect to a `login` route that doesn't exist, so each bad key produced a reported `RouteNotFoundException` (v0 still answered 400). Fixed with `redirectGuestsTo(fn () => null)` in `bootstrap/app.php`; covered by the mutation run.
- Not tested: the transaction around issuing (a failure between the two writes is hard to force). Rate limiting, key endpoints, v1 and MCP revocation are not built yet.

## 10. v1 ad detail (Part 1, step 2)

**Goal:** `GET /api/v1/ads/{id}`, the full record of one ad (needed later by the MCP `get_ad` tool).

**Prompt:**

> ok continue with the ads by id endpoint

**Result:**
- `app/Http/Resources/V1/AdResource.php`: the v1 ad, with the spec's data-model names in a fixed order: `id, brand_id, brand_name, headline, body_text, niche` (slug), `format, status, start_date, end_date, days_active` (the shared `Ad::daysActiveOn`), `impression_rank, countries, discovered_at` (UTC, `…Z`). Search (step 3) will reuse it.
- `app/Http/Controllers/Api/V1/AdController::show`: `{"data": {...}}`. A missing id gives 404 `not_found` ("There is no ad with id N."); an id that cannot exist (not digits, or over 18 digits) gives 404 before any query, because a non-numeric or out-of-range value would otherwise be a database error.
- Route behind Bearer auth and `search:read`. The error tests moved from their stand-in route to this real one.
- `assertApiError()` moved to `tests/Pest.php` so each test file runs on its own.

**Verification:**
- `tests/Feature/V1/AdDetailTest.php`: the exact body of an active ad, an inactive ad (end date, days up to it), `days_active` equal to v0's for both, `discovered_at` in UTC with PHP set to New York, 404 for a missing id, 404 with nothing reported for `abc`, `-1`, `1.5`, a 20-digit id and `" 1"`.
- Mutations: 11 of 11 caught (`scratchpad/mutations_v1_detail.py`); the 16 error mutations still caught.
- Full suite 148 passed; Pint clean.

## 11. v1 niches

**Goal:** `GET /api/v1/niches`, the v1 source for the MCP `list_niches` tool.

**Prompts:**

> Is this route required?

(Answer: not a Part 1 MUST, but Part 2 builds `get_ad` and `list_niches` "on top of v1", so both need a v1 source. Kept `/ads/{id}`.)

> OK. now make the get niches

**Result:** `GET /api/v1/niches` returns `{"data":[{slug, name, keywords}]}` in catalog order, behind Bearer auth and `search:read`. It is the same code as the route removed in task 10.

**Verification:** `tests/Feature/V1/NichesTest.php` (the exact list and one exact entry; 401 without a key, 403 without `search:read`). Mutations 4 of 4 caught. Full suite 150 passed; Pint clean.

## 13. v1 search: filters, validation, sorts (Part 1, step 3)

**Goal:** `GET /api/v1/ads` with every Part 1 filter and both sorts, strict validation with suggestions, and the applied filters echoed. Cursor pagination is step 4.

**Prompt:**

> ok now check again all the required things about the filter search and implement it. started_after should be on or after, and use comma-separated for the niches

**Result:**
- `app/Search/`:
  - `SearchRequestParser`: strict validation of the raw query string.
  - `QueryString`: raw pairs, because PHP keeps only the last of a repeated parameter and renames `a.b`.
  - `Suggest`: aliases, case-insensitive match, small edit distance.
  - `SearchParams`, `SearchSort`, `AdSearch`.
- Parameters: `q` (substring on headline/body, case-insensitive, `%`/`_` literal, max 200), `niche` (comma-separated slugs), `format`, `status`, `started_after` (YYYY-MM-DD, on or after, UTC), `sort` (`newest` default | `impressions`), `limit` (1–100, default 20; above 100 is an error, not a clamp).
- Values must be exact: `Skincare` is rejected with "did you mean `skincare`" (the spec's example). Normalisation is only trimming, removing duplicate niches (echoed in catalog order) and filling in defaults.
- One 422 `invalid_parameters` lists every problem in `error.details` (`param`, `issue`, `message`, `allowed`?, `did_you_mean`?). Issues: `unknown_parameter`, `invalid_value`, `empty_value`, `out_of_range`, `too_long`, `repeated_parameter`, `array_syntax`. When every problem has a fix, `next_step` is the corrected call (`Send instead: GET /api/v1/ads?...`). The `started_after` message includes today's date so an agent can compute "last 30 days".
- Invalid UTF-8 and NUL bytes are rejected with a 422 before they reach Postgres (which would fail with a 500).
- Sorts: `newest` = `start_date DESC, id DESC`; `impressions` = `impression_rank ASC NULLS LAST, id DESC`. New migration: index `(impression_rank ASC NULLS LAST, id DESC)`.
- Response: `{data: [AdResource...], applied_filters: {...only applied...}, sort, limit}`.
- `ApiError` gained optional `details`; new registry code `invalid_parameters` (422).

**Verification:**
- `tests/Feature/V1/SearchTest.php`: one test per filter with fixture ads the filter must exclude, AND across filters, both sorts (nulls last, ties), applied filters normalised, and every validation case.
- Mutations: 32 run; 31 caught at first. Missed: removing the `id` tiebreak, because both sort indexes end in `id` and Postgres returned ties in id order anyway. Added a test that inserts tied ads with shuffled ids and turns off index scans (`SET LOCAL`), so only an explicit `ORDER BY id` passes; the tiebreak break is now caught.
- Bug found by the tests: an unknown parameter with no suggestion still produced a "Send instead" call that silently dropped it. Fixed.
- Smoke test over HTTP on the 20k seeded ads (key written to a scratch file, never printed, deleted after): filters and sorts correct, 20–30 ms per query after warm-up, and the 422 gives the corrected call.
- Full suite 212 passed; Pint clean.

**For the README Assumptions:** `started_after` is inclusive; `niche` is comma-separated; values must be exact (suggested, not auto-corrected); `limit` above 100 is an error.

## 14. Fixes from the v1 test audit

**Prompt:**
> @test-auditor check the last implemented routes and make sure that everyting is ok by the requirements

> fix those

**Result:**
- HIGH: `niche=%FF&niche=skincare` returned a retryable 500, because the repeated-niche `did_you_mean` copied the invalid bytes and the JSON could not be encoded. The suggestion is now only given for valid text and lists each slug once (`niche=skincare&niche=skincare` suggests `skincare`).
- `/ads/{id}` and `/niches` now reject any query parameter with the same 422 (`RejectQueryParameters` middleware, after auth) instead of ignoring it.
- `sort=impressions` responses carry a `sort_note` saying ranks are within one brand, not across brands (wording in `SearchSort::note()`, to reuse in OpenAPI and MCP).
- HTTP exceptions not raised by our code keep their meaning: new registry codes `bad_request` (400, any 4xx), `rate_limited` (429, retryable, always with `retry_after` and the header), `unavailable` (503, retryable). Everything else stays `internal_error`.
- `SafeText`: echoed input (parameter names and values, 404/405 paths) is scrubbed to UTF-8, truncated, and anything like `ghk_…` is replaced with `ghk_[redacted]`. The unknown-niche message names at most 5, then "and N more".
- `README.md` started, with the Assumptions section.

**Verification:**
- New tests: the UTF-8 repeat case (3 forms, nothing reported), dedupe, the 5-niche cap, key redaction in values/names/paths, long paths, 400/413/414/431/429/503/502 mapping, `sort_note` present only for impressions, unknown params on detail and niches, `+`/`%20` in `q`, percent-encoded names, 401/403 on `/ads`, HEAD, OPTIONS preflight without auth or queries.
- Mutations: 13 run, 13 caught.
- Full suite 245 passed; Pint clean.

## 15. v1 cursor pagination (Part 1, step 4)

**Prompt:**
> continue with cursor

**Result:**
- `app/Search/Cursor.php`: opaque cursor, base64url JSON + HMAC-SHA256 (with `APP_KEY`, truncated to 16 bytes). It carries the sort, the normalised filters, the limit, the snapshot (highest ad id at page 1) and the last ad's sort key and id. After the signature check, each field is still validated, so a bad payload can never reach SQL.
- `AdSearch` returns a `SearchPage`:
  - Every page is bound to `id <= snapshot`.
  - Keyset for `newest`: `(start_date, id) < (d, i)`.
  - Keyset for `impressions`: `rank > r OR (rank = r AND id < i) OR rank IS NULL`, or `rank IS NULL AND id < i` once the walk is in the null section.
  - Fetches `limit + 1` rows to know whether there is a next page.
- Parser: `cursor` is a parameter, with aliases `next_cursor`, `page_token`, `next_page_token` and `after`.
  - A valid cursor fills in the filters, sort and limit the caller left out.
  - Parameters that were sent must normalise to the cursor's own; otherwise there is one 422 `cursor_mismatch` naming them. `limit` may differ.
  - An invalid cursor is a 422 `invalid_value`, and the cursor is not echoed.
- Response gained `next_cursor` (null on the last page).
- README: pagination section with the guarantees, including the commit-order caveat.

**Verification:**
- `tests/Feature/V1/CursorTest.php` (47 tests):
  - Walks with limits 1, 3 and 7 equal one big page, for both sorts and with no filter, two niches or `status=active`.
  - The last page being exactly full.
  - The impressions walk crossing into the null section at a page boundary, and paging inside it.
  - A walk with the real `AdIngestor` tick between pages, plus adversarial inserts (newest date, oldest date, a rank-1 tie, the head of the null section):
    - Without a filter, the walk returns exactly the ads that existed at page 1, in order.
    - With `status=active`, nothing repeats, every ad still active is returned, and no new ad appears.
  - Cursor alone, cursor with the same parameters spelled differently, cursor with a different limit.
  - Mismatches.
  - Alias suggestion.
  - 10 kinds of invalid cursor (including a re-encoded payload with the old signature, another `APP_KEY`, and signed-but-malformed payloads), none reported as an error.
- Mutations: 14 run, 14 caught. They covered removing the snapshot, re-reading it each page, `<` → `<=` in both keysets, dropping the null branch, reversing it, `>` → `>=`, no extra row, no signature check, no sort-key validation, no mismatch check, the cursor not carrying the search, and the cursor overriding parameters that were sent.
- Smoke test over HTTP on the seeded DB with `ads:ingest --interval=100` running throughout. The key was written to a scratch file, never printed, and deleted afterwards. A full walk at limit 100 took 201 pages for `newest` and 204 for `impressions`, with 0 duplicates. p50 was about 47 ms per page and the worst page 222 ms.
- Full suite 292 passed; Pint clean.

## 16. Fixes from the cursor audit

**Prompt:**
> @test-auditor check the cursor pagination, feel free to suggest better realization

> yes go on, fix it

**Result:** The auditor found the design correct and recommended keeping it. Fixed:
- Deep `sort=impressions` pages re-read the index from rank 1, because Postgres could only apply the OR-ed keyset condition as a filter. They are now two index range scans:
  - First the ranked ads, with the bound `rank >= r AND (rank > r OR id < i)`.
  - Then, if the page is not full, the ads without a rank (`id < i` once inside that section).
  - EXPLAIN on the 20k seed, cursor at rank 30: 5,543 buffers / 92 ms before, 76 buffers / 0.07 ms after.
- README guarantees:
  - "ads with an id above the page-1 snapshot never appear", replacing "ads added after page 1 never appear".
  - New: what `status=inactive` can return mid-walk.
  - The commit-order caveat is now enforced rather than assumed: `AdIngestor::tick` takes `pg_advisory_xact_lock`, so ingest cycles never overlap across processes.
- Dropped the `after` → `cursor` alias (agents mean a date by "after").
- A `cursor_mismatch` now gives both calls: continue the earlier search, or run the new one from page 1.
- `Cursor::MAX_LENGTH` raised to 4096 (worst case was about 2,000). `checkdate()` added for the signed sort key.

**Verification:**
- New tests:
  - Walk equivalence also with `q=serum` and with `format=video&started_after=…`.
  - A forced deactivation of an ad the walk had not reached yet (returned without a filter, dropped with `status=active`).
  - A `status=inactive` walk.
  - The longest possible search fits in a cursor.
  - An impossible date in a signed cursor is a 422.
  - `after` gets no cursor suggestion.
  - The mismatch `next_step` is pinned.
  - An ingest cycle waits on another connection holding the advisory lock (lock timeout).
- Mutations: 20 run, 19 caught. The miss over-fetches unranked rows in the top-up; it is an equivalent mutant, because `run()` trims to `limit`, so pages are identical and only a few extra rows are read.
- Full suite 311 passed; Pint clean. HTTP walk on the seeded DB with `ads:ingest --interval=100` running: newest 207 pages / 20,601 ads, impressions 209 pages / 20,879 ads, 0 duplicates, p50 about 50 ms per page.

## 17. OpenAPI spec (Part 1, step 5)

**Prompt:**
> OK. now analize the requirement and continue with the OpenAI spec

**Requirement (spec, Part 1):** "An OpenAPI spec that is kept true by generation or by a contract test. Write one test per filter that proves the filter changes the results." The per-filter tests already existed (`SearchTest`, "filters" block).

**Decision:** written by hand, kept true by tests, not generated. A generator such as Scramble infers parameters from FormRequests, but v1 search parses the raw query string itself (strict parameters, comma-separated niche, cursor), so a generated spec would be wrong in exactly the places that matter to agents.

**Result:**
- `api/openapi/v1.yaml` (OpenAPI 3.0.3), with agent-oriented descriptions:
  - Three operations: `searchAds`, `getAd`, `listNiches`.
  - Strict schemas (`additionalProperties: false`, enums for niche/format/status/sort/error code/issue).
  - Bearer security.
  - Every status the API returns, plus `default` for the shared error shape.
  - The impressions `sort_note` wording, and the cursor guarantees.
- `tests/OpenApi.php`:
  - Maps a URI to its documented operation and validates the response with `osteel/openapi-httpfoundation-testing` (dev dependency; uses `league/openapi-psr7-validator`).
  - For 2xx responses it also validates the request, so the document can't be stricter than the API.
- `v1()` in `tests/Pest.php` runs it on every response, so the existing ~190 v1 tests double as contract tests.
- `tests/Feature/V1/OpenApiTest.php`:
  - The document is valid OpenAPI.
  - Routes, search parameters, enums, limits, `q`/cursor maximum lengths, niche slugs and error codes equal the code's.
  - The impressions description contains `SearchSort::note()`.
  - Every documented (operation, status) has a request that produces it and validates. The 500s are forced with a throwing model `retrieved` listener.

**Verification:**
- Mutations: 18 run, 18 caught.
  - In the document: param removed, enum value missing, limit stricter, field renamed, `nullable` dropped, response removed, response added but never produced, path renamed, header changed, impressions wording changed.
  - In the code: new route, new error code, new search param, `next_cursor` renamed, extra `applied_filters` key, lowercase countries, non-ISO `discovered_at`, new problem issue.
- A spec stricter than the API (`limit` maximum 50) was not caught by the response checks alone, because no successful test sends a limit between 51 and 100. It is caught by the parameter parity test. Checked by hand: the request validator rejects `limit=150` and `format=gif`.
- Full suite 334 passed; Pint clean.

## 18. OpenAPI audit fixes

**Prompt:** "@test-auditor check if the openapi spec is all good", then "yes go on, fix it" (agreed set: the auditor's M1–M4, plus the slug lists, parity checks and wording; `id` stays a string, and 429 waits for Part 3).

**What changed:**
- `tests/OpenApi.php`: a v1 request with no documented operation (unknown path, other method, test-only routes such as `__abort/429`) must be an error, must be JSON, and its body must match the `Error` schema. Before, these responses skipped validation, so `retry_after` was never checked against the spec.
- `openapi/v1.yaml`:
  - Each error response pins its `code` (401 → `unauthenticated`, and so on). A 422 requires at least one entry in `details`.
  - `WWW-Authenticate` is a required header on 401.
  - `q` no longer has a `maxLength`: the API counts after trimming, so the schema rejected padded values the API accepts. The limit is stated in the description.
  - The `niche` parameter lists the 12 slugs; `applied_filters.niche` items are an enum of them.
  - `info.description` now says exactly which responses are validated.
- `App\Support\Errors\ParameterIssue` enum: the parser and `RejectQueryParameters` use it instead of string literals, so the spec's `issue` list can be compared with the code.
- `OpenApiTest`: parity for the issue enum, the `applied_filters` keys (from a real parse), the applied niche enum, the slug list in the `niche` description, `q` length wording, and each error response's code against `ErrorCode::status()`. A dataset of broken responses (each next to a correct one that passes) proves the document rejects them.
- `SearchTest`: `q` is measured after trimming (5 spaces + 200 characters is accepted; 201 is rejected).

**Verification:**
- Mutations: 18 run, 18 caught (helper checks removed, header optional, details optional or empty, codes unpinned or wrong, applied niche enum dropped, `q` maxLength restored, slug missing from description, issue undocumented, `retry_after` minimum 0; in the code: `q` counted before trimming, new issue, `applied_filters` key renamed, 404 status changed).

## 19. Fixes from the security audit

**Prompt:** "@security-auditor investigate please", then "yes go on, fix it" (agreed set: the Medium and the four Lows, plus the one-line revoke guard; the other Info items are left).

**What changed:**
- `docker-compose.yml`: Postgres and Redis ports are published on `127.0.0.1` only. Before, they listened on all interfaces, and the auditor connected over the LAN (Postgres superuser with a committed password, Redis without a password). Containers were recreated; `lsof` and `nc` against the LAN IP confirm. `tests/Unit/DockerComposeTest.php` checks every published port.
- Key redaction:
  - `SafeText` matches keys in any case and with `%5F` for the underscore, and `path()` uses the decoded path.
  - The repeated-`niche` suggestion is built from real slugs only (it used to echo the raw values, keys included).
  - No "Send instead" request is given when it would contain a key (e.g. `query=<key>`), and the cursor-mismatch hint drops the new-search URL in that case.
- 422 size: `ApiError` sends at most `MAX_DETAILS` (10) details; the parser and `RejectQueryParameters` cap the names in the message too. The spec says `maxItems: 10` (parity test).
- `Cursor` signs with a key derived from `APP_KEY` and throws (500, reported) if `APP_KEY` is missing or shorter than 32 characters, instead of signing with an empty key.
- `ApiErrorRenderer`: any 401 becomes `unauthenticated` (with `WWW-Authenticate`), any 403 becomes the new `forbidden` code (403). Both used to become 400 `bad_request`.
- `ApiKeys::revoke` keeps the first revocation time.
- New tests: the echo cases above, size caps on search and `/niches`, `APP_KEY` missing or short, 401/403/AuthorizationException mapping, a 500 while looking up the key leaks nothing, revocation on the next call for all three v1 endpoints, revoking twice.

**Verification:**
- Mutations: 18 run, 18 caught. The first run missed one (raw path instead of decoded path), because the `%5F` pattern already covered the encoded-underscore case; a key with percent-encoded letters was added to the test and the mutant is now caught.
- Full suite passed; Pint clean.

## 20. Per-key rate limit (Part 3, step 1)

**Prompt:** "what kind of rate limiter u suggest using?" (I suggested a sliding-window log), then "implement the ratelimiter with fixed window, explain the tradeoffs".

**What changed:**
- `App\RateLimit\KeyRateLimiter`: 60 requests per key per clock minute. A Lua script does `INCR` and, on the first hit, `EXPIRE` (two windows) in one step. `retry_after` is the time until the minute ends, rounded up.
- `RateLimitPerKey` middleware on the v0 and v1 groups, between `auth:sanctum` and the scope check. Over the limit it throws `ApiError(RateLimited)` with `retry_after` and `Retry-After`. If Redis fails, it reports the error and refuses with 503 `unavailable` instead of letting the request through.
- v0: `bootstrap/app.php` renders the rate-limit error as 429 with `Retry-After` and v0's usual body. This conflicts with "400 for every error" in the v0 spec; the rate-limit MUST wins, recorded in the README.
- Tests use Redis database 15 (`phpunit.xml`), flushed in `TestCase::setUp`, which refuses to flush any other database. Key ids restart with each fresh migration, so counters would otherwise carry over between runs.
- OpenAPI: a `RateLimited` response (429) on every operation, with a required `Retry-After` header and required `retry_after`. Each is produced by a real request (`spendRateLimit()` in `tests/Pest.php` fills a key's minute).
- `tests/Feature/RateLimit/RateLimitTest.php`:
  - 60 allowed, then a 429 with the right `retry_after`; the part-second rounding; the reset at the next minute; shared between v0 and v1 with v0's body; each key on its own.
  - Refused (403/422) requests count; invalid and revoked keys don't.
  - The boundary burst is pinned as a deliberate trade-off.
  - Counters expire; Redis down means refused.

**Verification:**
- Mutations: 16 run, 16 caught. The first run missed "round down instead of up", because the test sat at 59.4 s where both give 1. It was moved to 30.4 s, with a retry at 29 s (refused) and 30 s (allowed).
- Smoke: 100 concurrent requests (v0 and v1 mixed, one key, `artisan serve` with 16 workers) gave exactly 60 × 200 and 40 × 429, with `Retry-After` matching the seconds left in the minute. The key was deleted afterwards.

## 21. Billing: metering, Idempotency-Key, quota (Part 3, step 2)

**Prompt:** "what is the billable rule in the task?", "what rules do u suggest", then "yes go on, implement it".

**What changed:**
- The rule was written first: README "Billing" and a new `DESIGN.md` (billing and rate-limit decisions).
- Migration `billable_calls` (the one meter) and `idempotency_keys` (unique per API key and key, with the request fingerprint and `expires_at`).
- `App\Billing\Meter`:
  - `usage()` counts an account's rows in the UTC calendar month.
  - `bill()` runs in one transaction: lock the account's `users` row, claim the key (`INSERT … ON CONFLICT … DO UPDATE … WHERE expired`), check the quota, insert the row. A quota refusal rolls the claim back.
- `MeterBillableCall` middleware on v0 `/ads`, v1 `/ads` and v1 `/ads/{id}`, GET only.
  - Before the endpoint: validate `Idempotency-Key`; refuse a billed key sent with another request; refuse when over quota, unless it repeats a billed key.
  - After a 2xx: bill, and set `Billed: true|false`.
  - `X-Client: mcp` is recorded and has no effect on billing.
- New error codes `idempotency_key_reused` (422) and `quota_exceeded` (429, not retryable, with `resets_at` and `Retry-After`). v0 renders both as its usual 400.
- OpenAPI:
  - an `Idempotency-Key` header parameter and a required `Billed` response header on the billable operations;
  - 422 and 429 responses as `oneOf` per code, with `quota_exceeded` pinned to `retryable: false` and `resets_at` required.
  - The pins test follows `oneOf`; a parity test checks that exactly the billable operations document both headers.
- Tests:
  - `tests/Feature/Billing/MeterTest.php` (33 tests): every billable and free case, idempotency (repeat, re-run, 24-hour boundary, other request, failed request, per API key, header format on v1 vs v0), the quota (edge, free calls over quota, shared by an account's keys, per account, month reset, refused before running), and a meter that can't be written gives a 500 with no data.
  - `tests/Concurrency/BillingConcurrencyTest.php`, a new suite that `make test` will run: it starts `php -S` with 8 workers on the test database and fires parallel requests.
    - A mix of 45 calls bills exactly 26.
    - 20 calls against 995 used bill exactly 5 and refuse 15 with `quota_exceeded`.
    - 12 calls sharing one key with two different requests bill exactly 1.

**Problems found while building the concurrency test:**
- The server was started from the wrong directory, so every request got a PHP warning page with status 200. The test failed, as it should. Fixed by starting it in `public/`, like `artisan serve`; the readiness check now waits for the API's own 401.
- `php -S` workers outlive the main process, which left 8 orphans and hung the run. Fixed by stopping the workers first (`pkill -P`).

**Verification:**
- Mutations: 25 run, 23 caught at first.
  - "Quota checked with `>`" inside the transaction only shows under concurrency; it is caught by the concurrency suite.
  - "No quota check before running" was covered by the in-transaction check. A test now shows that an over-quota call is refused before it runs (an invalid search gets `quota_exceeded`, and the search never queries).
  - Removing the account lock is caught by the concurrency quota test.
- The concurrency suite passed 5 runs in a row, with no server processes left. Full suite passed; Pint clean.

## 22. Fixes from the rate-limiter audit

**Prompt:** "@security-auditor check the rate limiter", then "yes go on, fix it" (both Mediums and the missing tests).

**What changed:**
- **The test Redis guard checks the live connection.** `Tests\TestRedis::assertSafe()` reads the database of the actual predis connection, because a `REDIS_URL` ending in `/0` overrides the configured database while the config still says 15. `phpunit.xml` forces `REDIS_URL` empty and forces `REDIS_DB`, `DB_DATABASE` and `DB_URL`, so values exported in the shell can't win. `tests/Feature/TestSafetyTest.php` proves a `/0` URL is refused.
- **Redis timeouts** of 0.5 s for connecting and for each reply (`REDIS_TIMEOUT`, `REDIS_READ_WRITE_TIMEOUT`, in `.env.example`). A test points Redis at a socket that accepts connections and never answers, and expects a 503 in under 2 s (it was 60 s).
- New tests: HEAD and trailing-slash requests count against the limit.
- README: Redis timeouts, and the clock note (the app server's clock, not Redis's) as a documented limitation.
- **`tests/Concurrency` now uses worker processes, not an HTTP server.**
  - With `php -S` (8 workers), a request now and then got "Empty reply from server": the server log showed a busy worker accepting a second connection and dropping it. That is a limitation of PHP's dev server, and retrying would hide real double-billing.
  - The test now starts 20 PHP processes (`tests/Concurrency/worker.php`). Each boots the app, they wait at a file barrier, then all send their requests through the HTTP kernel at once, each with its own database and Redis connections, like 20 PHP-FPM workers.
  - On the way: Laravel's process timeout (60 s) killed the server's main process and left its workers orphaned, which hung a run. That is moot now that there is no server.
  - New: 100 requests from one key (v0 and v1 mixed) let exactly 60 through.

**Verification:**
- The concurrency suite passed 6 runs in a row, leaving no processes behind.
- Mutations: 6 run, 6 caught.
  - A non-atomic limiter (read, then write) let more than 60 through.
  - Removing the account lock overshot the quota.
  - Skipping the key claim, and treating a reused key as a repeat, both caught.
  - With no read timeout, the request hung for 60 s.
  - A guard that trusts the config let `/0` through.
- Full suite passed; Pint clean.

## 23. MCP server (Part 2)

**Prompt:** "I think we are ready for the mcp server, show me your plan", then "ok. implement the mcp server by the requirements".

**What was built (`mcp/`):**
- Node 22 + TypeScript, `@modelcontextprotocol/sdk` 1.32 low-level `Server` over Streamable HTTP, at `/mcp`.
- Tools: `list_niches`, `search_ads`, `get_ad`, with hand-written JSON Schemas.
- `v1Client.ts`: forwards the caller's Authorization header, sends `X-Client: mcp` and the call's `Idempotency-Key`, and retries once on timeout, dropped connection or 5xx.
- `errors.ts`: v1 codes passed through; `next_step` reworded for tool calls; `corrected_arguments` from v1's `did_you_mean`.
- `budget.ts`: 600 characters per search result, 20,000 per page, 3,500 for `get_ad`, measured after JSON encoding.
- Sessions with DNS-rebinding protection, an idle timeout, a session cap and a 1 MB body cap.

**Decisions made while implementing:**
- SDK/zod input validation was not used: it strips unknown arguments silently (v0's bug). Arguments are forwarded as sent, and v1 rejects them.
- The search_ads draft said a brand's ads could be found by putting the brand name in `q`. That was false (`q` searches copy only), so the description now says there is no brand filter.
- A 200 that is not JSON is retried once, with the same key (the test first expected no retry; the behaviour was kept and the test fixed).

**Verification:**
- Vitest: 87 unit tests against a fake v1 through a real MCP client over HTTP. Descriptions are checked against the Laravel enums, niche catalog, search parameters and error-code registry.
- End to end (`npm run test:e2e`, 5 tests): the real Laravel API on the test database behind a proxy.
  - A revoked key fails on the next MCP call in the same session.
  - 59 REST calls plus 1 MCP call, then the next MCP call is `rate_limited` with the wait in seconds.
  - MCP calls are billed with `client = mcp`, and `list_niches` is not billed.
  - A dropped first answer is retried and billed once.
- Mutations: 33 run against the unit tests, 33 caught; 3 run against the end-to-end tests (new key per retry, no `X-Client`, key read once per session), 3 caught.
- Not yet done: Docker service and `make` targets (with the Docker/Makefile step), and the real-model eval (needs a run in Claude Code).
