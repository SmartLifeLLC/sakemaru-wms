/**
 * Format date string to YYYY/MM/DD
 */
export function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}/${m}/${day}`;
}

/**
 * Format number with commas
 */
export function formatNumber(num) {
    if (num === null || num === undefined) return '0';
    return Number(num).toLocaleString('ja-JP');
}

/**
 * Format quantity with unit
 */
export function formatQuantity(qty, unit) {
    return `${formatNumber(qty)} ${unit || ''}`.trim();
}
