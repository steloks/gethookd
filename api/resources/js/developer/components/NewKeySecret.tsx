import type { CreatedKey } from '../api';
import { CopyButton } from './CopyButton';

/** The new key, shown once. Dismissing it drops it from memory; it can never be shown again. */
export function NewKeySecret({ created, onDismiss }: { created: CreatedKey; onDismiss: () => void }) {
    return (
        <section aria-labelledby="secret-heading" className="rounded-lg border-2 border-amber-300 bg-amber-50 p-5 shadow-sm">
            <h2 id="secret-heading" className="text-lg font-semibold">Copy your new key now</h2>
            <p className="mt-1 text-sm">
                This is the only time “{created.data.name}” is shown in full. We store only a hash of it; later you'll see just its prefix,{' '}
                <code>{created.data.prefix}</code>.
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <input
                    aria-label="Your new API key"
                    readOnly
                    value={created.secret}
                    onFocus={(e) => e.currentTarget.select()}
                    className="w-full max-w-xl rounded-md border border-slate-300 bg-white px-3 py-2 font-mono text-sm"
                />
                <CopyButton text={created.secret} label="Copy key" />
            </div>
            <p className="mt-3 text-sm text-slate-600">
                Use it as <code>Authorization: Bearer &lt;key&gt;</code> on <code>/api/v1</code>, or as <code>?api_key=</code> on <code>/api/v0</code>.
            </p>
            <button type="button" onClick={onDismiss} className="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white">
                I've stored it
            </button>
        </section>
    );
}
