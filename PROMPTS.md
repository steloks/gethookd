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
