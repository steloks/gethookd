import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from './App';
import { ErrorBoundary } from './components/ErrorBoundary';
import { apiKey, fakeServer, SECRET } from './test/fakeServer';

/** The row's cells by column header, so a swapped column fails. */
function cellsOf(row: HTMLElement): Record<string, string> {
    const headers = Array.from(row.closest('table')!.querySelectorAll('thead th')).map((th) => th.textContent ?? '');
    return Object.fromEntries(Array.from(row.querySelectorAll('td')).map((td, i) => [headers[i], td.textContent ?? '']));
}

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-123">';
    localStorage.clear();
    sessionStorage.clear();
});

describe('key list', () => {
    it('shows each key with prefix, scopes, created, last used and calls this month, in their columns', async () => {
        fakeServer({
            keys: [
                apiKey({ id: 2, name: 'Claude Desktop', prefix: 'ghk_zzzzzzzz', scopes: ['search:read', 'keys:manage'], last_used_at: '2026-10-31T08:30:00Z', billable_calls_this_period: 1234 }),
                apiKey({ id: 1, name: 'laptop' }),
            ],
        });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(cellsOf(await screen.findByTestId('key-2'))).toEqual({
            Name: 'Claude Desktop',
            Key: 'ghk_zzzzzzzz…',
            Scopes: 'search:readkeys:manage',
            Created: 'Oct 30, 2026, 9:00 AM UTC',
            'Last used': 'Oct 31, 2026, 8:30 AM UTC',
            'Calls this month': '1,234',
            Status: 'Revoke',
        });
        expect(cellsOf(screen.getByTestId('key-1'))['Last used']).toBe('Never');
    });

    it('shows revoked keys as revoked, with when, and without a revoke button', async () => {
        fakeServer({ keys: [apiKey({ id: 3, revoked_at: '2026-10-29T10:00:00Z' })] });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        const row = await screen.findByTestId('key-3');
        expect(cellsOf(row).Status).toBe('Revoked Oct 29, 2026, 10:00 AM UTC');
        expect(within(row).queryByRole('button', { name: /revoke/i })).toBeNull();
    });

    it('says when there are no keys yet', async () => {
        fakeServer();

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(await screen.findByText(/No keys yet/)).toBeInTheDocument();
    });

    it('shows a date it can\'t read as "—" instead of breaking the page', async () => {
        fakeServer({ keys: [apiKey({ id: 4, created_at: 'not a date' })], validate: false });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(cellsOf(await screen.findByTestId('key-4')).Created).toBe('—');
    });
});

describe('usage', () => {
    it('shows billable calls against the quota, from the meter, with the reset time in UTC', async () => {
        fakeServer();

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        const bar = await screen.findByRole('progressbar', { name: 'Billable calls used' });
        expect(bar).toHaveAttribute('aria-valuenow', '82');
        expect(bar).toHaveAttribute('aria-valuemax', '1000');
        expect((bar.firstChild as HTMLElement).style.width).toBe('8%');
        expect(bar.firstChild).toHaveAttribute('data-level', 'normal');
        const panel = within(screen.getByRole('region', { name: 'Usage this month' }));
        expect(panel.getByText(/of 1,000 billable calls/)).toHaveTextContent('82 of 1,000 billable calls · 918 left');
        expect(panel.getByText(/REST 2 · MCP 80/)).toHaveTextContent('UTC calendar month');
        expect(panel.getByText(/^Resets/)).toHaveTextContent('Resets Nov 1, 2026, 12:00 AM UTC');
        expect(panel.queryByRole('alert')).toBeNull();
    });

    it('marks usage from 80% of the quota as high', async () => {
        fakeServer({ usage: { billable_calls: 800, remaining: 200 } });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect((await screen.findByRole('progressbar')).firstChild).toHaveAttribute('data-level', 'high');
    });

    it('stays normal just under 80%', async () => {
        fakeServer({ usage: { billable_calls: 799, remaining: 201 } });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect((await screen.findByRole('progressbar')).firstChild).toHaveAttribute('data-level', 'normal');
    });

    it('warns when the quota is used up, with the reset time, and caps the bar', async () => {
        fakeServer({ usage: { billable_calls: 1500, remaining: 0 } });

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(await screen.findByRole('alert')).toHaveTextContent('quota is used up: searches are refused until Nov 1, 2026, 12:00 AM UTC');
        expect((screen.getByRole('progressbar').firstChild as HTMLElement).style.width).toBe('100%');
        expect(screen.getByRole('progressbar').firstChild).toHaveAttribute('data-level', 'exhausted');
    });
});

describe('creating a key', () => {
    it('sends the name and chosen scopes with the CSRF token, then shows the key once with a copy button', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        render(<App mcpUrl="http://localhost:3001/mcp" />);

        await user.type(await screen.findByLabelText('Name'), 'Claude Desktop');
        await user.click(screen.getByRole('checkbox', { name: /keys:manage/ }));
        await user.click(screen.getByRole('button', { name: 'Create key' }));

        const post = server.sent.find((r) => r.method === 'POST')!;
        expect(post.path).toBe('/developer/keys');
        expect(post.body).toEqual({ name: 'Claude Desktop', scopes: ['search:read', 'keys:manage'] });
        expect(post.headers['X-CSRF-TOKEN']).toBe('csrf-123');

        const secret = await screen.findByLabelText('Your new API key');
        expect(secret).toHaveValue(SECRET);
        expect(screen.getByText(/only time/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Copy key' }));
        expect(writeText).toHaveBeenCalledWith(SECRET);
        expect(screen.getByRole('status')).toHaveTextContent('Copied to the clipboard.');

        // The list is refreshed and shows the new key by its prefix only.
        expect(await screen.findByTestId('key-100')).toHaveTextContent(`${SECRET.slice(0, 12)}…`);
        expect(screen.getByTestId('key-100')).not.toHaveTextContent(SECRET);
    });

    it('forgets the key once dismissed: it is not in the page, storage or the URL', async () => {
        fakeServer();
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);

        await user.type(await screen.findByLabelText('Name'), 'laptop');
        await user.click(screen.getByRole('button', { name: 'Create key' }));
        await screen.findByLabelText('Your new API key');
        await user.click(screen.getByRole('button', { name: "I've stored it" }));

        expect(screen.queryByLabelText('Your new API key')).toBeNull();
        expect(document.body.innerHTML).not.toContain(SECRET);
        expect(JSON.stringify({ ...localStorage })).not.toContain(SECRET);
        expect(JSON.stringify({ ...sessionStorage })).not.toContain(SECRET);
        expect(window.location.href).not.toContain(SECRET);
        expect(screen.getByRole('button', { name: 'Create key' })).toBeInTheDocument();
    });

    it('creates one key however often the button is pressed while the first request runs', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);
        await user.type(await screen.findByLabelText('Name'), 'laptop');
        const release = server.hold('POST', '/developer/keys');

        await user.click(screen.getByRole('button', { name: 'Create key' }));
        await user.click(screen.getByRole('button', { name: 'Creating…' }));
        await user.type(screen.getByLabelText('Name'), '{Enter}');
        release();

        await screen.findByLabelText('Your new API key');
        expect(server.sent.filter((r) => r.method === 'POST')).toHaveLength(1);
    });

    it('says when the browser blocks copying, leaving the key selectable', async () => {
        fakeServer();
        const user = userEvent.setup();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) }, configurable: true });
        render(<App mcpUrl="http://localhost:3001/mcp" />);

        await user.type(await screen.findByLabelText('Name'), 'laptop');
        await user.click(screen.getByRole('button', { name: 'Create key' }));
        await user.click(await screen.findByRole('button', { name: 'Copy key' }));

        expect(screen.getByRole('status')).toHaveTextContent('Copying was blocked');
        expect(screen.getByLabelText('Your new API key')).toHaveValue(SECRET);
    });

    it('shows the API\'s problems with their suggested fixes, and no key', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);
        await user.type(await screen.findByLabelText('Name'), 'laptop');
        server.replyOnce(() =>
            server.json(422, {
                error: {
                    code: 'invalid_parameters',
                    message: '1 problem with the new key. See details.',
                    next_step: 'Fix each field listed, then send it again.',
                    retryable: false,
                    details: [{ param: 'scopes', issue: 'invalid_value', message: '"search_read" is not a scope.', allowed: ['search:read', 'keys:manage'], did_you_mean: 'search:read' }],
                },
            }),
        );

        await user.click(screen.getByRole('button', { name: 'Create key' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('1 problem with the new key');
        expect(alert).toHaveTextContent('"search_read" is not a scope. Did you mean “search:read”?');
        expect(alert).toHaveTextContent('Fix each field listed');
        expect(screen.queryByLabelText('Your new API key')).toBeNull();
    });

    it('says to check the list when a create answers without the key', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);
        await user.type(await screen.findByLabelText('Name'), 'laptop');
        server.replyOnce(() => new Response('<html>proxy page</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));

        await user.click(screen.getByRole('button', { name: 'Create key' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('without the expected data');
        expect(alert).toHaveTextContent('revoke it');
        expect(screen.queryByLabelText('Your new API key')).toBeNull();
    });
});

describe('revoking a key', () => {
    it('asks to confirm, sends DELETE with the CSRF token, and shows the key as revoked', async () => {
        const server = fakeServer({ keys: [apiKey({ id: 7, prefix: 'ghk_seven777' })] });
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);

        await user.click(await screen.findByRole('button', { name: 'Revoke' }));
        expect(server.sent.some((r) => r.method === 'DELETE')).toBe(false);
        expect(screen.getByText(/stops this key on its very next request/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Revoke ghk_seven777' }));

        const del = server.sent.find((r) => r.method === 'DELETE')!;
        expect(del.path).toBe('/developer/keys/7');
        expect(del.headers['X-CSRF-TOKEN']).toBe('csrf-123');
        await waitFor(() => expect(cellsOf(screen.getByTestId('key-7')).Status).toMatch(/^Revoked /));
    });

    it('sends nothing when cancelled', async () => {
        const server = fakeServer({ keys: [apiKey({ id: 7 })] });
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);

        await user.click(await screen.findByRole('button', { name: 'Revoke' }));
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(server.sent.some((r) => r.method === 'DELETE')).toBe(false);
        expect(screen.getByRole('button', { name: 'Revoke' })).toBeInTheDocument();
    });

    it('shows the error and the key\'s real state when revoking fails', async () => {
        const server = fakeServer({ keys: [apiKey({ id: 7, prefix: 'ghk_seven777' })] });
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);
        await user.click(await screen.findByRole('button', { name: 'Revoke' }));
        // Meanwhile the key was revoked elsewhere (another tab, or the API).
        server.keys[0]!.revoked_at = '2026-10-31T11:00:00Z';
        server.replyOnce(() => server.json(404, { error: { code: 'not_found', message: 'This account has no key with id "7".', next_step: 'List the keys and use the id of the key to revoke.', retryable: false } }));

        await user.click(screen.getByRole('button', { name: 'Revoke ghk_seven777' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('no key with id "7"');
        await waitFor(() => expect(cellsOf(screen.getByTestId('key-7')).Status).toBe('Revoked Oct 31, 2026, 11:00 AM UTC'));
    });

    it('keeps a revoked key revoked when an older refresh answers last', async () => {
        const server = fakeServer({ keys: [apiKey({ id: 7, prefix: 'ghk_seven777' })] });
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3001/mcp" />);
        await screen.findByTestId('key-7');
        await user.type(screen.getByLabelText('Name'), 'laptop');
        // The create's refresh reads the list while key 7 is still active, and answers last.
        const releaseOldList = server.hold('GET', '/developer/keys');
        await user.click(screen.getByRole('button', { name: 'Create key' }));
        await screen.findByLabelText('Your new API key');

        await user.click(within(await screen.findByTestId('key-7')).getByRole('button', { name: 'Revoke' }));
        await user.click(screen.getByRole('button', { name: 'Revoke ghk_seven777' }));
        await waitFor(() => expect(cellsOf(screen.getByTestId('key-7')).Status).toMatch(/^Revoked /));
        releaseOldList();
        await new Promise((r) => setTimeout(r, 20));

        expect(cellsOf(screen.getByTestId('key-7')).Status).toMatch(/^Revoked /);
    });
});

describe('loading', () => {
    it('says what to do when the API can\'t be reached', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(await screen.findByRole('alert')).toHaveTextContent('make up');
    });

    it('says what went wrong when the API answers an error that isn\'t JSON', async () => {
        const server = fakeServer();
        server.replyOnce(() => new Response('<html>Server Error</html>', { status: 500, headers: { 'Content-Type': 'text/html' } }));

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Unexpected response (HTTP 500).');
    });

    it('says what went wrong when a list answers 200 without data', async () => {
        const server = fakeServer();
        server.replyOnce(() => new Response('<html>proxy page</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }));

        render(<App mcpUrl="http://localhost:3001/mcp" />);

        expect(await screen.findByRole('alert')).toHaveTextContent('without the expected data');
    });
});

it('shows a message instead of a blank page if rendering fails', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const Broken = () => {
        throw new Error('render failed');
    };

    render(
        <ErrorBoundary>
            <Broken />
        </ErrorBoundary>,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Reload the page');
});
