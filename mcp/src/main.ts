import { createHttpServer } from './server.js';
import { createV1Client } from './v1Client.js';

const port = Number(process.env.MCP_PORT ?? 3001);
const host = process.env.MCP_HOST ?? '127.0.0.1';
const v1BaseUrl = process.env.V1_BASE_URL ?? 'http://127.0.0.1:8000';
const allowedHosts = (process.env.MCP_ALLOWED_HOSTS ?? `localhost:${port},127.0.0.1:${port}`)
  .split(',')
  .map((h) => h.trim())
  .filter((h) => h !== '');

const server = createHttpServer({
  v1: createV1Client({ baseUrl: v1BaseUrl, timeoutMs: Number(process.env.V1_TIMEOUT_MS ?? 10_000) }),
  allowedHosts,
});

server.listen(port, host, () => {
  // Never log request headers: they carry the caller's API key.
  console.log(`GetHookd MCP server on http://${host}:${port}/mcp (v1 at ${v1BaseUrl})`);
});

for (const signal of ['SIGINT', 'SIGTERM'] as const) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
