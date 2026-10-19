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

export function qs(obj) {
    const parts = [];
    for (const [k, v] of Object.entries(obj)) {
        if (v === undefined || v === null || v === '') continue;
        parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
    }
    return parts.length ? '?' + parts.join('&') : '';
}
