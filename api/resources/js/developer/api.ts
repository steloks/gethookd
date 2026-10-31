/**
 * The developer page's calls to /developer/* (same origin, session cookie). Writes also send the
 * CSRF token from the page; Laravel accepts either it or the browser's Sec-Fetch-Site: same-origin.
 * Errors arrive in the API's one error shape and are thrown as ApiProblem.
 */

export type Scope = 'search:read' | 'keys:manage';

export const SCOPES: Array<{ value: Scope; label: string; help: string }> = [
    { value: 'search:read', label: 'search:read', help: 'Search ads (REST and MCP).' },
    { value: 'keys:manage', label: 'keys:manage', help: 'Create, list and revoke keys through the API.' },
];

export interface ApiKey {
    id: number;
    name: string;
    prefix: string;
    scopes: Scope[];
    created_at: string;
    last_used_at: string | null;
    last_mcp_call_at: string | null;
    revoked_at: string | null;
    billable_calls_this_period: number;
}

export interface Usage {
    period_start: string;
    resets_at: string;
    billable_calls: number;
    quota: number;
    remaining: number;
    by_client: { rest: number; mcp: number };
}

export interface CreatedKey {
    data: ApiKey;
    secret: string;
    note: string;
}

interface ProblemDetail {
    param?: string;
    message?: string;
    did_you_mean?: string;
}

export class ApiProblem extends Error {
    constructor(
        public readonly code: string,
        message: string,
        public readonly nextStep: string,
        public readonly details: ProblemDetail[] = [],
    ) {
        super(message);
    }
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function call<T>(method: string, path: string, body?: unknown): Promise<T> {
    let response: Response;
    try {
        response = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
                ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': csrfToken() }),
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
    } catch {
        throw new ApiProblem('network_error', 'The API could not be reached.', 'Check that the stack is running (make up), then reload.');
    }

    const json = (await response.json().catch(() => null)) as { error?: { code: string; message: string; next_step: string; details?: ProblemDetail[] } } | null;
    if (!response.ok) {
        const error = json?.error;
        throw new ApiProblem(error?.code ?? 'unexpected', error?.message ?? `Unexpected response (HTTP ${response.status}).`, error?.next_step ?? 'Reload the page.', error?.details ?? []);
    }
    if (json === null || typeof json !== 'object') {
        // e.g. an HTML page from something in between. For a create, the key may exist anyway.
        throw new ApiProblem(
            'unexpected_response',
            `The API answered HTTP ${response.status} without the expected data.`,
            method === 'POST'
                ? 'Reload the page and check the key list: if a key appeared that you never saw in full, revoke it and create another.'
                : 'Reload the page. If it keeps happening, check that the stack is running (make up).',
        );
    }

    return json as T;
}

export const api = {
    listKeys: () => call<{ data: ApiKey[] }>('GET', '/developer/keys').then((r) => r.data),
    usage: () => call<{ data: Usage }>('GET', '/developer/usage').then((r) => r.data),
    createKey: (name: string, scopes: Scope[]) => call<CreatedKey>('POST', '/developer/keys', { name, scopes }),
    revokeKey: (id: number) => call<{ data: ApiKey }>('DELETE', `/developer/keys/${id}`).then((r) => r.data),
};
