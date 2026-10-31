import { useEffect, useState } from 'react';

/**
 * Copies text to the clipboard. If the browser refuses (no permission, or not a secure context),
 * it says so and the text stays selectable next to it.
 */
export function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    useEffect(() => {
        if (state !== 'copied') return;
        const timer = setTimeout(() => setState('idle'), 2000);
        return () => clearTimeout(timer);
    }, [state]);

    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
            setState('copied');
        } catch {
            setState('failed');
        }
    }

    return (
        <>
            <button type="button" onClick={copy} className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium hover:bg-slate-50">
                {state === 'copied' ? 'Copied' : label}
            </button>
            <span role="status" className="text-sm text-slate-600">
                {state === 'copied' ? 'Copied to the clipboard.' : state === 'failed' ? 'Copying was blocked: select the text and copy it by hand.' : ''}
            </span>
        </>
    );
}
