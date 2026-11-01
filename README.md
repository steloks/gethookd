# GetHookd ad search — v1 API and MCP server

My submission for the GetHookd "Fullstack Engineer (API & MCP)" assessment:
- a frozen v0 endpoint;
- a v1 search API for AI agents;
- an MCP server on top of v1;
- API keys with a shared rate limit, exact billing and a monthly quota;
- a React developer page.

All MUST and SHOULD items are done.

More: [DESIGN.md](DESIGN.md) · [MCP_EVAL.md](MCP_EVAL.md) · [AI_NOTES.md](AI_NOTES.md) · [REFERENCE.md](REFERENCE.md) (every rule in detail) · [OpenAPI spec](api/openapi/v1.yaml) · [CLAUDE.md](CLAUDE.md)

## Run it

Needs Docker (with Compose v2), `make` and `openssl`. Ports 8000, 3001, 55432 and 56379 must be free.

```
make up
```

This builds and starts everything (about 3 minutes the first time), then migrates and seeds 20,000+ ads. Then:

1. Open **http://localhost:8000/developer** and create an API key. It is shown once.
2. Try it: `curl -H "Authorization: Bearer <key>" "http://localhost:8000/api/v1/ads?niche=skincare&limit=2"`

| Command | What it does |
|---|---|
| `make up` | Start everything |
| `make ingest` | Live ingest: new ads every second, until Ctrl-C |
| `make test` | All tests: API, developer page, MCP server, and MCP end to end |
| `make key` | Create an API key in the terminal |
| `make seed` | Reset the ads to the seed data |
| `make down` | Stop. `docker compose down -v` also deletes the data |

## Connect Claude Code

On the developer page, use **Connect your AI tool**. It creates a key, gives you one command to paste, and confirms when Claude's first call arrives. By hand:

```
claude mcp add --transport http gethookd http://localhost:3001/mcp --header "Authorization: Bearer <key>"
```

## Assumptions

Where the spec was unclear (details in [REFERENCE.md](REFERENCE.md)):
- **Data:** the seed is anchored to a fixed date, so every run gives the same data. All dates use the UTC day.
- **`impression_rank`** only ranks ads within one brand, and is never compared across brands.
- **v1 rejects instead of guessing.** Unknown parameters, near-miss values (`Skincare`) and `limit` over 100 get a 422 with the likely fix.
- **`started_after` includes the given day.**
- **`niche` takes several slugs separated by commas.**
- **v0 under the rate limit** returns 429 with `Retry-After`. That is the only v0 response besides 200 and 400, because the spec requires a 429 on every version.
- **A billable call** is a successful `GET` of v0 `/ads`, v1 `/ads` or v1 `/ads/{id}`. Errors, `/niches` and key management are free.
- **MCP retries** reuse the tool call's `Idempotency-Key`; a new tool call is billed.
- **The rate limit** is per key, the **quota** per account. The quota resets each UTC calendar month.
- **The developer page has no login** (the spec's single local user). It only answers on localhost.

## Unfinished, and what I'd do next

1. A brand filter and a country filter. The eval showed people ask for both.
2. Faster keyword search (a trigram or full-text index), the first thing that breaks at 110M ads.
3. A cheaper `total` for v0.
4. More eval runs per task, and more than one model.
5. A login for the developer page, and a production web server.

Not done: the COULD item "a raw session export".
