import { Component, type ReactNode } from 'react';

/** If rendering fails (say, unexpected data), the page says so instead of going blank. */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        if (this.state.failed) {
            return (
                <div role="alert" className="mx-auto mt-10 max-w-xl rounded-md border border-red-200 bg-red-50 p-4 text-red-800">
                    <p className="font-medium">This page hit an error while showing your keys.</p>
                    <p className="mt-1 text-sm">Reload the page. Your keys and usage are unaffected.</p>
                </div>
            );
        }

        return this.props.children;
    }
}
