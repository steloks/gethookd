# AI notes

## Time and tools

**About 11 hours** in total;

| Part | Tool | What I did myself |
|---|---|---|
| 0 Data, seed, ingest, v0 | Claude Code | Set the rules in `CLAUDE.md` before any code: v0 frozen, tests with every feature, tests must fail when the feature breaks |
| 1 v1 search API | Claude Code | Had the AI explain how the OpenAPI spec is kept true; had the spec audited for test gaps and the API for security |
| 2 MCP server and eval | Claude Code (also the MCP client for the eval) | Reviewed the plan before any code; ran the real-model eval rounds; moved Docker ahead of the eval |
| 3 Keys, rate limit, metering | Claude Code | Chose the rate-limit algorithm and the billing rule from the options it laid out; decided which audit findings to fix |
| 4 Developer page and docs | Claude Code | Chose Claude Code for the connect panel; tested the connect flow by hand, end to end |

**How the work ran:**
1. The AI proposed options with trade-offs, and I chose.
2. It implemented the choice with tests.
3. Every test was mutation-checked.
4. Read-only review agents (`.claude/agents/`: tests, security, MCP) audited the result.
5. I decided which findings to fix, and committed each step myself.

**API keys:** I created and handled the real keys for the eval in my own shell. They never went into the chat or the repository.

## Where the AI got it wrong

| What it produced | How it was caught | What we did instead |
|---|---|---|
| A key with only `keys:manage` could create a `search:read` key, giving out access it didn't have | The security review I ran after the key endpoints | I chose the stricter rule: a key can only grant scopes it has. It's covered by tests |

## How I checked that the tests would fail if the feature broke

- **Every new test was mutation-checked.** The code was broken on purpose (drop the sort tiebreaker, skip the Idempotency-Key check, send no CSRF token), the tests had to fail, and then the code was restored. A small script did this one change at a time.
- **When a mutation wasn't caught, the test was fixed before moving on.** Examples:
  - a rate-limit test checked at a moment where rounding made no difference;
  - a quota-bar test used 1,005 of 1,000 calls, which rounds to 100% anyway.
- **Concurrency is tested with real parallel processes,** because exact billing and the 60-per-minute limit only break under load.
- **The OpenAPI spec is validated against real responses,** and the developer page's test data against the spec.
