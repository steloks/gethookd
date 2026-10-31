# Design

## Billing and metering

The rule is written in the README ("Billing"). The decisions behind it:

- **Only successful data calls are billed.** Errors are never billed: v1 rejects unclear requests instead of guessing (a 422 with the fix), and billing those would charge agents for the strictness that keeps them from reporting wrong answers. Load from bad requests is limited by the rate limit, not by billing.
- **`/niches` is free,** because tool descriptions send agents there first to get valid slugs; charging for it would push them to guess.
- **Repeats are run again, not replayed.** Every billable endpoint is a read, and the data changes every second, so a stored response would be stale and cost storage for nothing. `Idempotency-Key` decides billing only.
- **A key is bound to its request** (a fingerprint of method, path and query), or a client could send one constant key and never be billed.
- **Exact counting:** each billable call takes a row lock on its account, claims its `Idempotency-Key` (insert, or reclaim an expired one, under a unique index), checks the quota and inserts its billing row, all in one transaction. Concurrent requests of one account are serialised only for that short write, so the count and the quota can't be overshot.
- **Quota per account, rate limit per key:** the plan belongs to the account; the rate limit protects the service from one client.
- **MCP retries** reuse the tool call's `Idempotency-Key`; a new tool call is a new billable call (README).

## Rate limit

Fixed windows of one clock minute, one Redis counter per key; see the README ("Rate limit") for the trade-off against a sliding-window log or token bucket.

## MCP server

- **Thin client of v1, over HTTP.** The MCP server never touches the database and holds no key: each tool call is a v1 request with the caller's own `Authorization` header. Auth, scopes, revocation, the rate limit, billing and the quota are therefore v1's, with nothing to keep in sync. The cost is one extra hop per call.
- **The key is read from every request, never stored with the session,** so revoking a key (or swapping it in the client config) applies to the next tool call.
- **No argument checks in the MCP server.** Tool schemas are hand-written JSON Schema, not SDK/zod validation: the SDK would silently strip an unknown argument (`nich`), which is exactly v0's bug. Arguments go to v1 as sent; v1's 422 (with `did_you_mean`) becomes `corrected_arguments`. Allowed values are listed in the descriptions, and a test checks them against the Laravel enums and niche catalog, so the descriptions can't drift from what v1 accepts.
- **Response size is a budget, not a hope.** Results are cut and measured after JSON encoding (quotes and emoji make the encoded text longer), with fallbacks that cut further, so a page has a hard upper bound. `limit` is capped at 25 in the tool even though v1 allows 100: the only argument the MCP server checks, because it is the tool's own budget.
- **`impression_rank` is renamed `impression_rank_in_brand`** in tool output, and the descriptions say it does not compare brands. A model reading `impression_rank: 1` would otherwise call that ad the most-seen overall. ("Which pet supplies brand gets the most impressions?" can't be answered from this data, and the tools say so.)
- **Retries:** one retry on timeout, dropped connection or 5xx, with the call's `Idempotency-Key` (sha256 of session id, JSON-RPC id, tool and arguments), so a lost answer is not billed twice. This is proved end to end: a proxy drops Laravel's first answer, and exactly one billing row is written.
