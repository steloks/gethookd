import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { ErrorBoundary } from './components/ErrorBoundary';

const root = document.getElementById('developer-page')!;

createRoot(root).render(
    <StrictMode>
        <ErrorBoundary>
            <App mcpUrl={root.dataset.mcpUrl ?? 'http://localhost:3001/mcp'} />
        </ErrorBoundary>
    </StrictMode>,
);
