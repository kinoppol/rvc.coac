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
