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
