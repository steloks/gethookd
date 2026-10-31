import type { Usage } from '../api';
import { formatDateTime, formatNumber } from '../format';

/** Billable calls this month against the quota, as the billing meter counts them. */
export function UsagePanel({ usage }: { usage: Usage | null }) {
    if (usage === null) {
        return <section aria-label="Usage" className="h-28 animate-pulse rounded-lg bg-white shadow-sm" />;
    }

    const percent = Math.min(100, Math.round((usage.billable_calls / usage.quota) * 100));
    const exhausted = usage.remaining === 0;
    // From the exact share, not the rounded percent: 799 of 1,000 is not yet 80%.
    const high = usage.billable_calls >= usage.quota * 0.8;

    return (
        <section aria-labelledby="usage-heading" className="rounded-lg bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="usage-heading" className="text-lg font-semibold">Usage this month</h2>
                <p className="text-sm text-slate-600">
                    Resets <time dateTime={usage.resets_at}>{formatDateTime(usage.resets_at)}</time>
                </p>
            </div>
            <p className="mt-2 text-sm">
                <span className="text-xl font-semibold">{formatNumber(usage.billable_calls)}</span> of {formatNumber(usage.quota)} billable calls ·{' '}
                {formatNumber(usage.remaining)} left
            </p>
            <div
                role="progressbar"
                aria-label="Billable calls used"
                aria-valuemin={0}
                aria-valuemax={usage.quota}
                aria-valuenow={usage.billable_calls}
                className="mt-3 h-3 overflow-hidden rounded-full bg-slate-100"
            >
                <div data-level={exhausted ? 'exhausted' : high ? 'high' : 'normal'} className={`h-full ${exhausted ? 'bg-red-500' : high ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${percent}%` }} />
            </div>
            <p className="mt-2 text-xs text-slate-500">
                REST {formatNumber(usage.by_client.rest)} · MCP {formatNumber(usage.by_client.mcp)}. The month is a UTC calendar month. A billable call is a
                successful ad search or ad lookup; niches, errors and repeats of an Idempotency-Key are free.
            </p>
            {exhausted && (
                <p role="alert" className="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-800">
                    The monthly quota is used up: searches are refused until <time dateTime={usage.resets_at}>{formatDateTime(usage.resets_at)}</time>.
                </p>
            )}
        </section>
    );
}
