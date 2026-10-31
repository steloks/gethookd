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
