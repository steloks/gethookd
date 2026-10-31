# GetHookd ad search — v1 API and MCP server

Work in progress for the GetHookd "Fullstack Engineer (API & MCP)" assessment. Done so far: the data model, deterministic seed, live ingest script, the frozen v0 endpoint with its contract test, API keys, and the v1 endpoints `GET /api/v1/ads` (with cursor pagination), `GET /api/v1/ads/{id}` and `GET /api/v1/niches`. Setup instructions (`make up`) come with the Docker/Makefile step.

The v1 API is described in [`api/openapi/v1.yaml`](api/openapi/v1.yaml) (OpenAPI 3.0). It is written by hand and kept true by tests:
- Every v1 response the test suite receives is validated against it, and every request the API accepted must be valid by it too. A v1 request the document has no operation for must fail with a body in the `Error` schema.
- `api/tests/Feature/V1/OpenApiTest.php` checks that it documents exactly the routes, search parameters, allowed values, limits and error codes the code has.
- The same test checks that every response status it documents really happens.

## Assumptions

Where the spec is ambiguous, this is what the code does and why.

### Data (Part 0)

- The seed is anchored to a fixed date, not to "today", so every run produces the same rows.
- `impression_rank` is an ad's position within its own brand (1 = most impressions at that brand). It is never compared across brands.
- `brand_name` comes from a join with `brands`; it is not copied onto ads.
- The ingest script never deactivates an ad in the same cycle that inserted it.
- `days_active` and "today" use the UTC calendar day.
- A new ad's `impression_rank` is NULL or its brand's current maximum + 1. Existing ranks are never rewritten.

### v0

- Invalid `page` or `per_page` values fall back to the defaults, like any other ignored input (v0 quirk, kept on purpose).

### v1 search (`GET /api/v1/ads`)

- `started_after=YYYY-MM-DD` is inclusive: it keeps ads that started **on or after** that UTC day.
- `niche` takes several slugs separated by commas (`niche=skincare,haircare`). Repeating the parameter (`niche=a&niche=b`) and array syntax (`niche[]=a`) are rejected with the comma form as the correction, because PHP would silently keep only the last value.
- Values must be exact (`skincare`, `video`, `active`). Near misses (`Skincare`, `Pet Supplies`, `vidoe`) are rejected with a `did_you_mean`; they are never corrected silently, so the caller always knows what was searched.
- `limit` is 1–100, default 20. A larger value is rejected (with 100 as the suggestion) rather than clamped.
- Unknown parameters are rejected on every v1 endpoint, including `/ads/{id}` and `/niches`, which take none.
- `sort=impressions` orders by the within-brand rank: every brand's rank-1 ads, then rank 2, and so on, with ads without impression data last. The response includes a `sort_note` saying this is not a cross-brand ranking.
- Ties in either sort are broken by `id`, highest first.

### Pagination (`cursor`)

- Every search response has `next_cursor`: send it back as `?cursor=` for the next page. It is `null` on the last page.
- The cursor carries the search (filters, sort, limit), so `?cursor=…` alone is enough. Sending it with the same filters also works. Sending it with a different filter or sort is a 422 `cursor_mismatch`, because the position would mean nothing in another search. `limit` may change from page to page.
- Cursors are opaque and signed with a key derived from `APP_KEY`. A cursor that was changed, or issued under another key, is a 422. Rotating `APP_KEY` invalidates walks in progress. Cursors do not expire.
- Without an `APP_KEY` (at least 32 characters) the API neither issues nor accepts cursors, so searches fail with a 500 `internal_error` instead of handing out cursors anyone could forge. `make up` must run `php artisan key:generate`.

**Guarantees while ads are being ingested and deactivated during a walk** (proved by `tests/Feature/V1/CursorTest.php`, which runs the real ingest cycle between pages):

- No ad is ever returned twice in one walk.
- Without a `status` filter, the walk returns exactly the ads that matched when page 1 was read, in the order page 1 implied. An ad deactivated mid-walk is still returned, with its current status.
- With `status=active`, an ad deactivated before its page is read is left out. Nothing else is left out.
- With `status=inactive`, an ad deactivated mid-walk may also appear on a later page (it matches by the time that page is read). Nothing else is added.
- The walk is pinned to the highest ad id when page 1 was read: ads with a higher id never appear, so new ads cannot push other ads onto pages already read. Both sort keys (`start_date`, `impression_rank`) never change after insert, so a deactivation cannot move an ad across a page boundary either. To see new ads, start a new walk.
- Pinning by id is exact only if ads become visible in id order. Postgres hands out ids at insert, not at commit, so two writers inserting at once could commit out of order. Ingest cycles therefore take a Postgres advisory lock (`AdIngestor::tick`), so cycles never overlap, even with several ingest processes running. Ads are only ever inserted by ingest and the seed.

### Rate limit

- Each API key may make **60 requests per clock minute** (12:00:00–12:00:59, then 12:01:00–…). v0, v1 and the MCP server share one counter per key; the MCP server calls v1 with the caller's key, so it needs nothing of its own.
- Over the limit: 429 `rate_limited` with `retry_after` and a `Retry-After` header, both the whole seconds until the minute ends (rounded up, so waiting that long always works).
- Every request with a valid key counts, including ones then refused for their scope (403) or parameters (422): the limit protects the service from load, which those requests still cause. Requests without a valid key, and revoked keys, are refused before they are counted.
- **v0 under the rate limit (a conflict in the spec):** v0 returns 400 `{"error":"Invalid request"}` for every error, but the rate limit must be shared with v0 and return 429 with `Retry-After`. v0 therefore returns **429**, with `Retry-After` and its usual body. It is the only v0 response that is not 400 or 200; every other v0 error is unchanged.
- If Redis is unreachable or doesn't answer within 0.5 s (`REDIS_TIMEOUT`, `REDIS_READ_WRITE_TIMEOUT`), requests are refused (v1: 503 `unavailable`, retryable; v0: its usual 400) and the error is reported, rather than served without a limit or held for PHP's 60-second socket default.
- The minute comes from the app server's clock. With several app servers, clock skew between them would shift their windows slightly; with one server (as here) it can't. Using Redis's clock (`TIME` in the script) would remove that if the API ran on several servers.
- Fixed windows are a deliberate trade-off (see below): a caller can send 60 requests at 12:00:59 and 60 more at 12:01:00.

**Why fixed windows:** one Redis counter per key and minute, incremented and given an expiry in one Lua script, so concurrent requests can never both take the last slot (`tests/Concurrency`: 100 requests from 20 processes at once, exactly 60 succeed). It is the simplest scheme to explain ("60 per clock minute"), costs one small key per active key, and gives an exact `Retry-After` for its own rule. The cost is the burst at a minute boundary: up to 120 requests within about a second. A sliding-window log would close that gap (at most 60 in *any* 60 seconds) at the cost of storing up to 60 timestamps per key, and a token bucket would smooth traffic to one request a second after a burst; either can replace `KeyRateLimiter` without touching its callers.

### Billing (the billable-call rule)

**A billable call** is a `GET` request with a valid key that returns 2xx with ad data, on one of:
- v0 `GET /api/v0/ads`
- v1 `GET /api/v1/ads` (search, every page)
- v1 `GET /api/v1/ads/{id}`

The MCP server calls v1, so `search_ads` and `get_ad` are billed through these; nothing is billed twice for one MCP call.

**Never billable:**
- `GET /api/v1/niches` (and MCP `list_niches`): reference data the tools tell agents to read first.
- Any error: 4xx (including 422, which is us refusing to guess), 429 and 5xx.
- `HEAD` requests (no ad data is returned).
- Key management and usage endpoints.

**When it is counted:** after the endpoint has produced its 2xx response and before the response is sent, as one row in `billable_calls`. If the row can't be written, the caller gets a 500 and no data. The quota, the developer page and any invoice all count these rows; there is no second counter.

**`Idempotency-Key` (24 hours, per API key):**
- Requests from the same API key with the same `Idempotency-Key` within 24 hours of the first billed one are billed once. The 24 hours are measured from that first billed call to the time the repeat arrives.
- A repeat runs the request again (all endpoints are reads, and the data is live) and is not billed; the response says so in the `Billed: false` header. A billed response has `Billed: true`.
- The key is bound to the request (method, path and query string). Sending a used key with a different request is a 422 `idempotency_key_reused`, so one constant key can't make every call free.
- A request that fails (any error) doesn't use up its key; the next success with that key is billed.
- Concurrent requests with the same key all run; exactly one is billed.
- Format: 1–255 visible ASCII characters, sent once. On v1 an invalid key is a 422; on v0 it is ignored (v0 ignores invalid input) and the call is billed normally.

**Monthly quota:** 1,000 billable calls per calendar month (UTC) per account, shared by all of its keys (the rate limit is per key). Over the quota, billable calls get a 429 `quota_exceeded`, which is not retryable and gives `resets_at` (the first instant of the next month) and a `Retry-After` until then. `/niches` and repeats of an already-billed `Idempotency-Key` still work, since they cost nothing. On v0 the quota error is v0's usual 400.

**MCP retries:** the MCP server sends one `Idempotency-Key` per tool call, derived from the MCP session and the JSON-RPC request id, and reuses it when it retries v1 after a timeout or 5xx, or when the client re-sends the same message. A model deciding to call a tool again is a new tool call and is billed, like a REST client sending a new request: nothing distinguishes "retry" from "ask again" except the request id, and treating identical arguments as a retry would make deliberate repeats free.

### Errors

- Every non-v0 error uses one shape: `{"error": {"code", "message", "next_step", "retryable", "details"?, "retry_after"?}}`. The codes are listed in `api/app/Support/Errors/ErrorCode.php`.
- HTTP errors raised outside our code keep their meaning: 401 becomes `unauthenticated`, 403 (e.g. a denied authorization) becomes `forbidden`, other client errors become a non-retryable `bad_request` (400), 429 becomes `rate_limited` with `retry_after`, and 503 becomes a retryable `unavailable`. Anything else is `internal_error`, which never includes the underlying message.
- Error messages never echo more than a short, cleaned part of the caller's input. Anything that looks like an API key (`ghk_…` in any case, also with its underscore percent-encoded, and in the decoded path) is replaced with `ghk_[redacted]`. When a corrected request would contain a key the caller put in a value, no corrected request is given.
- A 422 lists at most 10 problems in `details`; the message says how many there are. So a request with thousands of bad parameters can't produce a huge response.
- A successful search echoes `q` in `applied_filters` as sent, even if it looks like a key: it is the caller's own search term, returned only to them.
- A CORS preflight (`OPTIONS`) is answered without a key and is not a search. It must not count as a billable call.
