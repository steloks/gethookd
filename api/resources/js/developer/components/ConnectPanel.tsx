import { useEffect, useState } from 'react';
import { api, ApiProblem, type ApiKey } from '../api';
import { formatDateTime } from '../format';
import { CopyButton } from './CopyButton';
import { ProblemMessage } from './ProblemMessage';

const FIRST_QUESTION = 'Find active skincare video ads that started in the last 30 days.';

type Step =
    | { kind: 'start' }
    | { kind: 'waiting'; key: ApiKey; secret: string }
    | { kind: 'timed-out'; key: ApiKey; secret: string }
    | { kind: 'connected'; key: ApiKey; secret: string; at: string };

/**
 * "Connect your AI tool" for Claude Code: create a key for it, paste one command, ask a question,
 * and see the first call arrive. The API notes each key's last call through the MCP server
 * (last_mcp_call_at); the panel polls the key list until it appears.
 */
export function ConnectPanel({
    mcpUrl,
    onKeyCreated,
    pollMs = 3000,
    maxWaitMs = 10 * 60_000,
}: {
    mcpUrl: string;
    onKeyCreated: () => Promise<void>;
    pollMs?: number;
    maxWaitMs?: number;
}) {
    const [step, setStep] = useState<Step>({ kind: 'start' });
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<ApiProblem | null>(null);

    async function createKey() {
        setBusy(true);
        setProblem(null);
        try {
            const created = await api.createKey('Claude Code', ['search:read']);
            setStep({ kind: 'waiting', key: created.data, secret: created.secret });
            void onKeyCreated();
        } catch (error) {
            setProblem(error instanceof ApiProblem ? error : new ApiProblem('unexpected', String(error), 'Try again.'));
        } finally {
            setBusy(false);
        }
    }

    // While waiting: re-read the key list until the key's first MCP call shows up, or give up.
    useEffect(() => {
        if (step.kind !== 'waiting') return;
        const { key, secret } = step;
        let stopped = false;
        const poll = setInterval(async () => {
            try {
                const current = (await api.listKeys()).find((k) => k.id === key.id);
                if (!stopped && current?.last_mcp_call_at) {
                    stopped = true;
                    setStep({ kind: 'connected', key: current, secret, at: current.last_mcp_call_at });
                    void onKeyCreated();
                }
            } catch {
                // A failed check is retried on the next tick.
            }
        }, pollMs);
        const giveUp = setTimeout(() => {
            if (!stopped) setStep({ kind: 'timed-out', key, secret });
        }, maxWaitMs);

        return () => {
            stopped = true;
            clearInterval(poll);
            clearTimeout(giveUp);
        };
    }, [step, pollMs, maxWaitMs, onKeyCreated]);

    const command = step.kind === 'start' ? null : `claude mcp add --transport http gethookd ${mcpUrl} --header "Authorization: Bearer ${step.secret}"`;

    return (
        <section aria-labelledby="connect-heading" className="rounded-lg bg-white p-5 shadow-sm">
            <h2 id="connect-heading" className="text-lg font-semibold">Connect your AI tool</h2>
            <p className="mt-1 text-sm text-slate-600">
                Search ads from Claude Code by asking in plain words. Takes about two minutes; you need{' '}
                <a className="underline" href="https://claude.com/claude-code" target="_blank" rel="noreferrer">Claude Code</a> installed.
            </p>

            <ol className="mt-4 space-y-4 text-sm">
                <li>
                    <p className="font-medium">1. Create a key for Claude Code</p>
                    {step.kind === 'start' ? (
                        <button type="button" disabled={busy} onClick={createKey} className="mt-2 rounded-md bg-slate-900 px-4 py-2 font-medium text-white disabled:opacity-50">
                            {busy ? 'Creating…' : 'Create a key for Claude Code'}
                        </button>
                    ) : (
                        <p className="mt-1 text-slate-600">
                            Created “{step.key.name}” (<code>{step.key.prefix}…</code>). It's in the command below and won't be shown again.
                        </p>
                    )}
                    {problem && <div className="mt-2"><ProblemMessage problem={problem} /></div>}
                </li>

                <li className={step.kind === 'start' ? 'opacity-50' : ''}>
                    <p className="font-medium">2. Paste this into a terminal and press Enter</p>
                    {command === null ? (
                        <p className="mt-1 text-slate-500">The command appears here once the key exists.</p>
                    ) : (
                        <>
                            <pre aria-label="Command to connect Claude Code" className="mt-2 overflow-x-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100">{command}</pre>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <CopyButton text={command} label="Copy command" />
                            </div>
                            <p className="mt-1 text-xs text-slate-500">
                                Already added before? Run <code>claude mcp remove gethookd</code> first.
                            </p>
                        </>
                    )}
                </li>

                <li className={step.kind === 'start' ? 'opacity-50' : ''}>
                    <p className="font-medium">3. Start Claude Code (type <code>claude</code>) and ask</p>
                    <p className="mt-1 italic text-slate-700">“{FIRST_QUESTION}”</p>
                </li>
            </ol>

            {step.kind === 'waiting' && (
                <p role="status" className="mt-4 rounded-md bg-slate-50 p-3 text-sm">
                    Waiting for Claude Code's first call… This updates by itself.
                </p>
            )}
            {step.kind === 'connected' && (
                <p role="status" className="mt-4 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">
                    ✓ Claude Code is connected. Its first call arrived <time dateTime={step.at}>{formatDateTime(step.at)}</time>.
                </p>
            )}
            {step.kind === 'timed-out' && (
                <div role="status" className="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    <p>No call from Claude Code yet. Check that:</p>
                    <ul className="mt-1 list-disc pl-5">
                        <li>the command above ran without an error, and <code>claude mcp list</code> shows <code>gethookd</code> as connected;</li>
                        <li>GetHookd is running (<code>make up</code>);</li>
                        <li>you asked Claude Code a question about ads.</li>
                    </ul>
                    <button type="button" onClick={() => setStep({ kind: 'waiting', key: step.key, secret: step.secret })} className="mt-2 rounded-md border border-amber-300 bg-white px-3 py-1">
                        Check again
                    </button>
                </div>
            )}
            {step.kind !== 'start' && (
                <button type="button" onClick={() => setStep({ kind: 'start' })} className="mt-4 rounded-md border px-3 py-1 text-sm">
                    {step.kind === 'connected' ? 'Done' : 'Hide the command'}
                </button>
            )}
        </section>
    );
}
