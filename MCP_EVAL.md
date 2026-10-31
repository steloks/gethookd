# MCP evaluation

Real model, real client: Claude Code connected to the MCP server over Streamable HTTP (`claude mcp add --transport http gethookd http://localhost:3001/mcp --header "Authorization: Bearer <key>"`), against the seeded data. Each task is a fresh conversation; we record the first tool call the model made and whether the tool and arguments were right on the first try.

## Tasks

| # | Task | Correct first call | Why it is in the set |
|---|------|--------------------|----------------------|
| 1 | "Find active video ads in skincare that started in the last 30 days." | `search_ads {niche: ["skincare"], format: "video", status: "active", started_after: <today − 30 days>}` | Fixed. Four filters, one relative date. |
| 2 | "Which pet supplies brand gets the most impressions?" | `search_ads {niche: ["pet-supplies"], sort: "impressions"}`, then an answer that says the data can't rank brands against each other | Fixed. Honesty: impression rank is within one brand. |
| 3 | "Show me some recent hoodie ads." | `search_ads {q: "hoodie"}` (optionally `niche: ["apparel"]`), newest first | Ours, ambiguous: "recent" (start date, not discovery), active or not, and whether to restrict the niche. |
| 4 | "Get me the full text of the newest active carousel ad in jewelry." | `search_ads {niche: ["jewelry"], format: "carousel", status: "active", limit: 1}`, then `get_ad {id}` | Ours. Two tools in sequence; search results are cut. |
| 5 | "Find serum ads, then show me the next page." | `search_ads {q: "serum"}`, then `search_ads {cursor: <next_cursor>}` | Ours. Pagination with the cursor alone. |

## Results

| # | Before: right tool / args first try | After |
|---|---|---|
| 1 | _pending_ | _pending_ |
| 2 | _pending_ | _pending_ |
| 3 | _pending_ | _pending_ |
| 4 | _pending_ | _pending_ |
| 5 | _pending_ | _pending_ |

## A misuse, the change, and the rerun

_Pending: one full transcript where the model misused a tool, the description or error change made, and the transcript after._
