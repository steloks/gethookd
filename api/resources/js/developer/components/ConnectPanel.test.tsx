import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { App } from '../App';
import { fakeServer, SECRET } from '../test/fakeServer';
import { ConnectPanel } from './ConnectPanel';

const MCP_URL = 'http://localhost:3001/mcp';
const COMMAND = `claude mcp add --transport http gethookd ${MCP_URL} --header "Authorization: Bearer ${SECRET}"`;

function renderPanel(options: { pollMs?: number; maxWaitMs?: number } = {}) {
    const onKeyCreated = vi.fn().mockResolvedValue(undefined);
    render(<ConnectPanel mcpUrl={MCP_URL} onKeyCreated={onKeyCreated} pollMs={options.pollMs ?? 10} maxWaitMs={options.maxWaitMs ?? 60_000} />);
    return onKeyCreated;
}

describe('connect your AI tool', () => {
    it('creates a search:read key named for Claude Code and gives the command with it, ready to copy', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const onKeyCreated = renderPanel();

        expect(screen.queryByLabelText('Command to connect Claude Code')).toBeNull();
        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));

        const post = server.sent.find((r) => r.method === 'POST')!;
        expect(post.body).toEqual({ name: 'Claude Code', scopes: ['search:read'] });
        expect(post.headers['X-CSRF-TOKEN']).toBeDefined();
        expect(await screen.findByLabelText('Command to connect Claude Code')).toHaveTextContent(COMMAND);
        expect(onKeyCreated).toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Copy command' }));
        expect(writeText).toHaveBeenCalledWith(COMMAND);
    });

    it('waits for the first MCP call, then says Claude Code is connected and stops checking', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));
        expect(await screen.findByText(/Waiting for Claude Code's first call/)).toBeInTheDocument();
        await waitFor(() => expect(server.sent.filter((r) => r.method === 'GET').length).toBeGreaterThan(1));
        expect(screen.queryByText(/is connected/)).toBeNull();

        // Claude Code makes its first call: the API notes it on the key.
        server.keys[0]!.last_mcp_call_at = '2026-11-01T14:02:00Z';

        expect(await screen.findByText(/Claude Code is connected/)).toHaveTextContent('first call arrived Nov 1, 2026, 2:02 PM UTC');
        const checks = server.sent.length;
        await new Promise((r) => setTimeout(r, 60));
        expect(server.sent.length).toBe(checks);
    });

    it('only counts a call made with the key it created', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel();
        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));
        await screen.findByText(/Waiting/);

        server.keys.push({ ...server.keys[0]!, id: 1, prefix: 'ghk_otherkey', last_mcp_call_at: '2026-11-01T14:00:00Z' });
        await new Promise((r) => setTimeout(r, 60));

        expect(screen.queryByText(/is connected/)).toBeNull();
    });

    it('keeps checking when a check fails', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel();
        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));
        await screen.findByText(/Waiting/);
        server.replyOnce(() => new Response('oops', { status: 500 }));

        server.keys[0]!.last_mcp_call_at = '2026-11-01T14:02:00Z';

        expect(await screen.findByText(/Claude Code is connected/)).toBeInTheDocument();
    });

    it('gives troubleshooting steps if no call arrives, and checks again on request', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel({ maxWaitMs: 50 });
        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));

        expect(await screen.findByText(/No call from Claude Code yet/)).toBeInTheDocument();
        expect(screen.getByText(/claude mcp list/)).toBeInTheDocument();
        expect(screen.getByText(/make up/)).toBeInTheDocument();
        const checks = server.sent.length;
        await new Promise((r) => setTimeout(r, 40));
        expect(server.sent.length).toBe(checks);

        server.keys[0]!.last_mcp_call_at = '2026-11-01T14:02:00Z';
        await user.click(screen.getByRole('button', { name: 'Check again' }));

        expect(await screen.findByText(/Claude Code is connected/)).toBeInTheDocument();
    });

    it('forgets the key when done: the command is gone and can\'t be shown again', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel();
        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));
        await screen.findByLabelText('Command to connect Claude Code');
        server.keys[0]!.last_mcp_call_at = '2026-11-01T14:02:00Z';
        await screen.findByText(/is connected/);

        await user.click(screen.getByRole('button', { name: 'Done' }));

        expect(document.body.innerHTML).not.toContain(SECRET);
        expect(JSON.stringify({ ...localStorage, ...sessionStorage })).not.toContain(SECRET);
        expect(screen.getByRole('button', { name: 'Create a key for Claude Code' })).toBeInTheDocument();
    });

    it('shows the API\'s problem when the key can\'t be created', async () => {
        const server = fakeServer();
        const user = userEvent.setup();
        renderPanel();
        server.replyOnce(() => server.json(403, { error: { code: 'forbidden', message: 'This request did not come from the developer page.', next_step: 'Reload the developer page and try again.', retryable: false } }));

        await user.click(screen.getByRole('button', { name: 'Create a key for Claude Code' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Reload the developer page');
        expect(screen.queryByLabelText('Command to connect Claude Code')).toBeNull();
    });

    it('is on the developer page, with the MCP server address the server configured', async () => {
        fakeServer();
        const user = userEvent.setup();
        render(<App mcpUrl="http://localhost:3999/mcp" />);

        await user.click(await screen.findByRole('button', { name: 'Create a key for Claude Code' }));

        expect(await screen.findByLabelText('Command to connect Claude Code')).toHaveTextContent('gethookd http://localhost:3999/mcp --header');
    });
});
