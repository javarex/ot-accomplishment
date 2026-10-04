const pesoFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatPeso(amount: number | string | null | undefined): string {
    if (amount === null || amount === undefined || amount === '') return '—';
    const value = Number(amount);
    return Number.isFinite(value) ? pesoFormatter.format(value) : '—';
}
