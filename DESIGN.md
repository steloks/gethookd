# Design

Details of every rule are in the README; this page covers the decisions behind them.

## Trade-offs

- **v1 refuses to guess.** An unknown parameter or invalid value gets a 422 with the likely fix, never a silent correction. It costs agents a round trip, but they never report answers to a question they didn't ask.
- **The cursor is pinned to the highest ad id when page 1 was read.** A walk never repeats or skips an ad while ingest runs. The cost: new ads only appear in a new walk.
- **Fixed-window rate limit** (60 per clock minute, one Redis counter per key). It is simple and exact, and its `Retry-After` is exact too. The cost is a possible burst of up to 120 requests around a minute boundary.
- **Billing counts only successful data calls,** in one transaction that locks the account's row. Errors and `/niches` are free. A repeated `Idempotency-Key` is run again but not billed.
- **The MCP server is a thin HTTP client of v1.** It sends each call with the caller's own key, so auth, scopes, revocation, the rate limit and billing are v1's, with nothing to keep in sync. The cost is one extra hop per call.
- **The developer page has no login** (the spec's single local user). CSRF protection, a host allow-list and the absence of CORS stop other sites; local processes are trusted.
- **One Docker image on `php artisan serve`.** That gives one command to run everything, but it is not a production server.

## How v0 and v1 coexist

- Both read the same `ads` table and use the same `days_active` code.
- Both go through the same auth, rate-limit and billing middleware. One key works on both: `?api_key=` on v0 only, `Authorization: Bearer` everywhere else.
- v0 is frozen, quirks included, and its contract test pins it.
- The one change to v0 is a 429 (with v0's body) for the shared rate limit, because the spec requires it. That conflict is noted in the README.

## Shipping a breaking change to v1

1. Build it as `/api/v2` beside v1; never change v1 in place.
2. Send `Deprecation` and `Sunset` headers on v1, and log which keys still call it so their owners can be told.
3. Move the MCP tools to v2 separately; tools are their own contract.
4. Remove v1 only when its traffic is gone.

## What breaks first at 110M ads (5–6M active)

1. **Keyword search.** v0 and v1 both search with `headline ILIKE '%q%' OR body_text ILIKE '%q%'`. No ordinary index can serve that, so every keyword search reads all 110M rows.
   - **Fix:** a trigram (`pg_trgm`) or full-text index, or a search engine (OpenSearch), with Postgres kept as the source of truth.
2. **v0's `total` and page numbers.** Every v0 request counts all matching rows, and page N skips N−1 pages of rows first. The v0 contract requires `total`, so it can't be dropped.
   - **Fix:** a cached or estimated count per filter combination, a limit on how deep `page` can go, and moving heavy users to v1's cursors.

Also at that size: the ingest script picks ads to deactivate with a count and a random offset over all active ads, which would need sampling by id range. And Sanctum writes `last_used_at` on every request, which would need batching.

The quota count does not break: it reads at most 1,000 rows per account per month, through an index.

## What comes next

- A brand filter and a country filter. The eval's probes showed people ask for both, and today the tools can only say they can't.
- More eval runs per task: one run can't tell a real change from noise.
- A real login on the developer page.
- A production web server (PHP-FPM behind nginx, or Octane).
