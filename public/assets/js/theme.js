const KEY = 'rvc_coac_theme';
const ORDER = ['light', 'dark', 'auto'];
const LABEL = { light: 'โหมดสว่าง', dark: 'โหมดมืด', auto: 'ตามระบบ' };
const ICON = { light: '☀️', dark: '🌙', auto: '🖥️' };

export function currentTheme() {
    return localStorage.getItem(KEY) || 'auto';
}

export function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'auto') {
        root.removeAttribute('data-theme');
    } else {
        root.setAttribute('data-theme', theme);
    }
}

export function initTheme() {
    // Opened from the mobile app (MobileController::openWeb): follow the app's light/dark choice, then tidy the address.
    const params = new URLSearchParams(location.search);
    const fromApp = params.get('app_theme');
    if (ORDER.includes(fromApp)) {
        try { localStorage.setItem(KEY, fromApp); } catch (e) { /* private mode */ }
        params.delete('app_theme');
        const q = params.toString();
        history.replaceState(null, '', location.pathname + (q ? '?' + q : '') + location.hash);
        applyTheme(fromApp);
        return;
    }
    applyTheme(currentTheme());
}

export function setTheme(theme) {
    localStorage.setItem(KEY, theme);
    applyTheme(theme);
}

export const THEMES = ORDER;

export function cycleTheme() {
    const cur = currentTheme();
    const next = ORDER[(ORDER.indexOf(cur) + 1) % ORDER.length];
    localStorage.setItem(KEY, next);
    applyTheme(next);
    return next;
}

export function themeMeta(theme = currentTheme()) {
    return { label: LABEL[theme], icon: ICON[theme] };
}
