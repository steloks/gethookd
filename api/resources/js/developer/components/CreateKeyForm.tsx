import { useState, type FormEvent } from 'react';
import { api, ApiProblem, SCOPES, type CreatedKey, type Scope } from '../api';
import { ProblemMessage } from './ProblemMessage';

export function CreateKeyForm({ onCreated }: { onCreated: (key: CreatedKey) => void }) {
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<Scope[]>(['search:read']);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<ApiProblem | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setBusy(true);
        setProblem(null);
        try {
            onCreated(await api.createKey(name, scopes));
            setName('');
        } catch (error) {
            setProblem(error instanceof ApiProblem ? error : new ApiProblem('unexpected', String(error), 'Try again.'));
        } finally {
            setBusy(false);
        }
    }

    const toggle = (scope: Scope) => setScopes((current) => (current.includes(scope) ? current.filter((s) => s !== scope) : [...current, scope]));

    return (
        <section aria-labelledby="create-heading" className="rounded-lg bg-white p-5 shadow-sm">
            <h2 id="create-heading" className="text-lg font-semibold">Create a key</h2>
            <form onSubmit={submit} className="mt-3 space-y-3">
                <div>
                    <label htmlFor="key-name" className="block text-sm font-medium">Name</label>
                    <input
                        id="key-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="e.g. Claude Desktop, laptop"
                        maxLength={100}
                        className="mt-1 w-full max-w-sm rounded-md border border-slate-300 px-3 py-2"
                    />
                </div>
                <fieldset>
                    <legend className="text-sm font-medium">Scopes</legend>
                    {SCOPES.map((scope) => (
                        <label key={scope.value} className="mt-1 flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={scopes.includes(scope.value)} onChange={() => toggle(scope.value)} />
                            <code>{scope.label}</code>
                            <span className="text-slate-500">{scope.help}</span>
                        </label>
                    ))}
                </fieldset>
                {problem && <ProblemMessage problem={problem} />}
                <button type="submit" disabled={busy} className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                    {busy ? 'Creating…' : 'Create key'}
                </button>
            </form>
        </section>
    );
}
