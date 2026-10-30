# GetHookd ad search — v1 API and MCP server

Work in progress for the GetHookd "Fullstack Engineer (API & MCP)" assessment. Done so far: the data model, deterministic seed, live ingest script, the frozen v0 endpoint with its contract test, API keys, and the v1 endpoints `GET /api/v1/ads`, `GET /api/v1/ads/{id}` and `GET /api/v1/niches`. Setup instructions (`make up`) come with the Docker/Makefile step.

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

### Errors

- Every non-v0 error uses one shape: `{"error": {"code", "message", "next_step", "retryable", "details"?, "retry_after"?}}`. The codes are listed in `api/app/Support/Errors/ErrorCode.php`.
- HTTP errors raised outside our code keep their meaning: client errors become a non-retryable `bad_request` (400), 429 becomes `rate_limited` with `retry_after`, and 503 becomes a retryable `unavailable`. Anything else is `internal_error`, which never includes the underlying message.
- Error messages never echo more than a short, cleaned part of the caller's input, and anything that looks like an API key is replaced with `ghk_[redacted]`.
- A CORS preflight (`OPTIONS`) is answered without a key and is not a search. It must not count as a billable call.
