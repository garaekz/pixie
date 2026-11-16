export function money(value: string | null): string {
    if (value === null) return '—';

    const normalized = value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');

    return normalized === '' || normalized === '0' ? '$0' : `$${normalized}`;
}

export function dateTime(value: string | null): string {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(date);
}

export function statusLabel(status: string): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}
