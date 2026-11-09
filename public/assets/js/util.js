export function money(n) {
    const v = Number(n || 0);
    return v.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function count(n) {
    return Number(n || 0).toLocaleString('th-TH');
}

export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

export function fmtDateTime(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
}

export function fmtDate(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString('th-TH', { dateStyle: 'medium' });
}

/** The system's time zone (set by the admin); undefined = the browser's own. */
let appTimeZone;

export function setAppTimeZone(tz) {
    try {
        new Intl.DateTimeFormat('en', { timeZone: tz });
        appTimeZone = tz;
    } catch (e) {
        appTimeZone = undefined;
    }
}

export function getAppTimeZone() {
    return appTimeZone;
}

/** Today's date (Y-m-d) in the system's time zone, not UTC. */
export function todayISO() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: appTimeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** The current date/time in the system's time zone, formatted like fmtDateTime. */
export function fmtNow(opts = { dateStyle: 'medium', timeStyle: 'short' }) {
    return new Date().toLocaleString('th-TH', { ...opts, timeZone: appTimeZone });
}

/** The period choices of a list, and each one's first and last day up to today. Custom dates are the page's own. */
export const DATE_PERIODS = [['today', 'วันนี้'], ['week', 'สัปดาห์นี้'], ['month', 'เดือนนี้'], ['custom', 'ระบุช่วงเวลา']];

/** Weeks start on Monday, worked out in UTC so no time zone can shift the date. */
export function periodRange(period, today) {
    if (period === 'month') return [`${today.slice(0, 7)}-01`, today];
    if (period !== 'week') return [today, today];
    const d = new Date(`${today}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7));
    return [d.toISOString().slice(0, 10), today];
}

export function qs(obj) {
    const parts = [];
    for (const [k, v] of Object.entries(obj)) {
        if (v === undefined || v === null || v === '') continue;
        parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
    }
    return parts.length ? '?' + parts.join('&') : '';
}

/** 1,284 → "1.3K": y-axis ticks and the cap label stay short. */
export function compact(v) {
    if (Math.abs(v) >= 1e6) return `${+(v / 1e6).toFixed(1)}M`;
    if (Math.abs(v) >= 1e3) return `${+(v / 1e3).toFixed(1)}K`;
    return String(Math.round(v));
}

/** Clean axis top and step (1/2/2.5/5 × 10^k) for about four gridlines. */
export function niceScale(max) {
    if (max <= 0) return { top: 100, step: 25 };
    const raw = max / 4;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
    return { top: Math.ceil(max / step) * step, step };
}
