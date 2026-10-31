import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiProblem, type ApiKey, type CreatedKey, type Usage } from './api';
import { ConnectPanel } from './components/ConnectPanel';
import { CreateKeyForm } from './components/CreateKeyForm';
import { KeyList } from './components/KeyList';
import { NewKeySecret } from './components/NewKeySecret';
import { ProblemMessage } from './components/ProblemMessage';
import { UsagePanel } from './components/UsagePanel';

export function App({ mcpUrl }: { mcpUrl: string }) {
    const [keys, setKeys] = useState<ApiKey[] | null>(null);
    const [usage, setUsage] = useState<Usage | null>(null);
    const [loadProblem, setLoadProblem] = useState<ApiProblem | null>(null);
    // The new key's secret lives only here, in memory, until it is dismissed: never in storage or the URL.
    const [created, setCreated] = useState<CreatedKey | null>(null);

    // Refreshes can overlap (create, then revoke at once); only the latest one may update the page,
    // or an older answer arriving last would show a revoked key as active again.
    const latestRefresh = useRef(0);

    const refresh = useCallback(async () => {
        const id = ++latestRefresh.current;
        try {
            const [nextKeys, nextUsage] = await Promise.all([api.listKeys(), api.usage()]);
            if (id !== latestRefresh.current) return;
            setKeys(nextKeys);
            setUsage(nextUsage);
            setLoadProblem(null);
        } catch (error) {
            if (id !== latestRefresh.current) return;
            setLoadProblem(error instanceof ApiProblem ? error : new ApiProblem('unexpected', String(error), 'Reload the page.'));
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    return (
        <main className="mx-auto max-w-5xl space-y-8 px-4 py-10">
            <header>
                <h1 className="text-2xl font-semibold">API keys</h1>
                <p className="mt-1 text-slate-600">Keys for the GetHookd ad search API (REST v0/v1) and the MCP server.</p>
            </header>

            {loadProblem && <ProblemMessage problem={loadProblem} />}

            <UsagePanel usage={usage} />

            {created ? (
                <NewKeySecret created={created} onDismiss={() => setCreated(null)} />
            ) : (
                <CreateKeyForm
                    onCreated={(key) => {
                        setCreated(key);
                        void refresh();
                    }}
                />
            )}

            <ConnectPanel mcpUrl={mcpUrl} onKeyCreated={refresh} />

            <KeyList keys={keys} onChanged={refresh} />
        </main>
    );
}
