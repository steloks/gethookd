/**
 * Dates and numbers in the reader's language and time zone, with the zone shown, because the
 * billing period is a UTC calendar month and its reset is midnight UTC, not local midnight.
 * Formatters are made per call so the locale and zone are read when shown, not at load time.
 */
const locale = () => (typeof navigator === 'undefined' ? undefined : navigator.language);

export function formatDateTime(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    // dateStyle/timeStyle can't be combined with timeZoneName, so the fields are spelled out.
    return new Intl.DateTimeFormat(locale(), { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(date);
}

export const formatNumber = (n: number) => new Intl.NumberFormat(locale()).format(n);
