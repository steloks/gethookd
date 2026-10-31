import { vi } from 'vitest';
import type { ApiKey, Usage } from '../api';
import { assertMatchesSchema } from './openapi';

export const SECRET = 'ghk_' + 'A'.repeat(40) + '0123abcd';

export interface Sent {
    method: string;
    path: string;
    headers: Record<string, string>;
    body: unknown;
}

type Reply = (request: Sent) => Response | Promise<Response> | undefined;

/**
 * An in-memory /developer/* that records every request the page sends. Every body it answers
 * with is checked against openapi/v1.yaml first, so the fixtures can't drift from the real API.
 */
export function fakeServer(initial: { keys?: ApiKey[]; usage?: Partial<Usage>; validate?: boolean } = {}) {
    const keys: ApiKey[] = [...(initial.keys ?? [])];
    const usage: Usage = {
        period_start: '2026-10-01T00:00:00Z',
        resets_at: '2026-11-01T00:00:00Z',
        billable_calls: 82,
        quota: 1000,
        remaining: 918,
        by_client: { rest: 2, mcp: 80 },
        ...initial.usage,
    };
    const sent: Sent[] = [];
    const replies: Reply[] = [];
    const holds: Array<{ matches: (request: Sent) => boolean; gate: Promise<void> }> = [];
    const json = (status: number, body: unknown, schema?: string) => {
        if (initial.validate !== false) {
            if (status >= 400) assertMatchesSchema('Error', body);
            else if (schema) assertMatchesSchema(schema, body);
        }
        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    };
    const ok = (path: string, request: Sent): Response => {
        if (request.method === 'GET' && path === '/developer/keys') {
            if (initial.validate !== false) keys.forEach((key) => assertMatchesSchema('ApiKey', key));
            return json(200, { data: structuredClone(keys) });
        }
        if (request.method === 'GET' && path === '/developer/usage') {
            if (initial.validate !== false) assertMatchesSchema('Usage', usage);
            return json(200, { data: { ...usage } });
        }
        if (request.method === 'POST' && path === '/developer/keys') {
            const { name, scopes } = request.body as { name: string; scopes: ApiKey['scopes'] };
            const key = apiKey({ id: 100 + keys.length, name, scopes, prefix: SECRET.slice(0, 12) });
            keys.unshift(key);
            return json(201, { data: key, secret: SECRET, note: 'This is the only time the full key is shown.' }, 'CreatedKey');
        }
        const revoke = /^\/developer\/keys\/(\d+)$/.exec(path);
        if (request.method === 'DELETE' && revoke) {
            const key = keys.find((k) => k.id === Number(revoke[1]))!;
            key.revoked_at ??= '2026-10-31T12:00:00Z';
            if (initial.validate !== false) assertMatchesSchema('ApiKey', key);
            return json(200, { data: key });
        }
        return json(404, { error: { code: 'not_found', message: 'There is no such resource.', next_step: 'Check the path.', retryable: false } });
    };

    vi.stubGlobal(
        'fetch',
        vi.fn(async (path: string, init: RequestInit = {}) => {
            const request: Sent = {
                method: init.method ?? 'GET',
                path,
                headers: (init.headers ?? {}) as Record<string, string>,
                body: typeof init.body === 'string' ? JSON.parse(init.body) : undefined,
            };
            sent.push(request);
            const held = holds.findIndex((hold) => hold.matches(request));
            if (held !== -1) {
                const { gate } = holds.splice(held, 1)[0]!;
                const response = ok(path, request); // the state as of now, delivered later
                await gate;
                return response;
            }
            const special = await replies.shift()?.(request);
            return special ?? ok(path, request);
        }),
    );

    return {
        sent,
        keys,
        json,
        /** The next request gets this reply (undefined: the normal one). */
        replyOnce: (reply: Reply) => replies.push(reply),
        /** The next matching request is answered with the state as it is then, but only when released. */
        hold(method: string, path: string) {
            let release!: () => void;
            const gate = new Promise<void>((resolve) => (release = resolve));
            holds.push({ matches: (request) => request.method === method && request.path === path, gate });
            return () => release();
        },
    };
}

export function apiKey(overrides: Partial<ApiKey> = {}): ApiKey {
    return {
        id: 1,
        name: 'laptop',
        prefix: 'ghk_abcdefgh',
        scopes: ['search:read'],
        created_at: '2026-10-30T09:00:00Z',
        last_used_at: null,
        last_mcp_call_at: null,
        revoked_at: null,
        billable_calls_this_period: 0,
        ...overrides,
    };
}

