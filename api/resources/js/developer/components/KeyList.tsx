import { useState } from 'react';
import { api, ApiProblem, type ApiKey } from '../api';
import { formatDateTime, formatNumber } from '../format';
import { ProblemMessage } from './ProblemMessage';

export function KeyList({ keys, onChanged }: { keys: ApiKey[] | null; onChanged: () => Promise<void> }) {
    return (
        <section aria-labelledby="keys-heading" className="rounded-lg bg-white p-5 shadow-sm">
            <h2 id="keys-heading" className="text-lg font-semibold">Your keys</h2>
            {keys === null ? (
                <p className="mt-3 text-sm text-slate-500">Loading…</p>
            ) : keys.length === 0 ? (
                <p className="mt-3 text-sm text-slate-500">No keys yet. Create one above.</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b text-slate-500">
                            <tr>
                                <th className="py-2 pr-4 font-medium">Name</th>
                                <th className="py-2 pr-4 font-medium">Key</th>
                                <th className="py-2 pr-4 font-medium">Scopes</th>
                                <th className="py-2 pr-4 font-medium">Created</th>
                                <th className="py-2 pr-4 font-medium">Last used</th>
                                <th className="py-2 pr-4 font-medium">Calls this month</th>
                                <th className="py-2 font-medium"><span className="sr-only">Status</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {keys.map((key) => (
                                <KeyRow key={key.id} apiKey={key} onChanged={onChanged} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

function KeyRow({ apiKey, onChanged }: { apiKey: ApiKey; onChanged: () => Promise<void> }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<ApiProblem | null>(null);
    const revoked = apiKey.revoked_at !== null;

    async function revoke() {
        setBusy(true);
        setProblem(null);
        try {
            await api.revokeKey(apiKey.id);
            setConfirming(false);
            await onChanged();
        } catch (error) {
            setProblem(error instanceof ApiProblem ? error : new ApiProblem('unexpected', String(error), 'Try again.'));
            // The key may have changed elsewhere (e.g. revoked in another tab): show its real state.
            await onChanged();
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <tr data-testid={`key-${apiKey.id}`} className={`border-b last:border-0 ${revoked ? 'text-slate-400' : ''}`}>
                <td className="py-2 pr-4">{apiKey.name}</td>
                <td className="py-2 pr-4 font-mono">{apiKey.prefix}…</td>
                <td className="py-2 pr-4">
                    {apiKey.scopes.map((scope) => (
                        <code key={scope} className="mr-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">{scope}</code>
                    ))}
                </td>
                <td className="py-2 pr-4"><time dateTime={apiKey.created_at}>{formatDateTime(apiKey.created_at)}</time></td>
                <td className="py-2 pr-4">
                    {apiKey.last_used_at === null ? 'Never' : <time dateTime={apiKey.last_used_at}>{formatDateTime(apiKey.last_used_at)}</time>}
                </td>
                <td className="py-2 pr-4">{formatNumber(apiKey.billable_calls_this_period)}</td>
                <td className="py-2 text-right whitespace-nowrap">
                    {revoked ? (
                        <span>
                            Revoked <time dateTime={apiKey.revoked_at!}>{formatDateTime(apiKey.revoked_at!)}</time>
                        </span>
                    ) : confirming ? (
                        <span className="inline-flex gap-2">
                            <button type="button" disabled={busy} onClick={revoke} className="rounded-md bg-red-600 px-3 py-1 text-white disabled:opacity-50">
                                {busy ? 'Revoking…' : `Revoke ${apiKey.prefix}`}
                            </button>
                            <button type="button" disabled={busy} onClick={() => setConfirming(false)} className="rounded-md border px-3 py-1">Cancel</button>
                        </span>
                    ) : (
                        <button type="button" onClick={() => setConfirming(true)} className="rounded-md border border-red-300 px-3 py-1 text-red-700 hover:bg-red-50">
                            Revoke
                        </button>
                    )}
                </td>
            </tr>
            {(confirming || problem) && (
                <tr>
                    <td colSpan={7} className="pb-3 text-sm">
                        {problem ? <ProblemMessage problem={problem} /> : <span className="text-red-700">Revoking stops this key on its very next request, on REST and MCP. It can't be undone.</span>}
                    </td>
                </tr>
            )}
        </>
    );
}
