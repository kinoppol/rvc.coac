import { api, setCsrfToken } from './api.js';
import { renderShell, setActiveNav, updateThemeButton, updateBadges } from './layout.js';
import { initTheme, cycleTheme } from './theme.js';
import { escapeHtml } from './util.js';
import { mountAssistant, unmountAssistant } from './assistant.js';

const ROUTE_PERMS = {
    dashboard: 'dashboard.view', sales: 'sales.create', receive: 'receive.create',
    ret: 'return.create', count: 'stockcount.create', consignors: 'consignors.view',
    passbook: 'passbook.view', withdraw: 'withdraw.create', reports: 'reports.view',
    settings: 'settings.view', migrations: 'migrations.manage', audit: 'audit.view',
    ai: 'ai.manage', purchase: 'purchase.create', aiusage: 'ai.usage',
};

const state = { user: null, permissions: [] };
const root = document.getElementById('root');

initTheme();

function currentRoute() {
    const hash = location.hash.replace(/^#\/?/, '');
    const [key, queryStr] = hash.split('?');
    const params = Object.fromEntries(new URLSearchParams(queryStr || ''));
    return { key: key || 'dashboard', params };
}

async function loadView({ key, params }) {
    const view = document.getElementById('view');
    if (!view) return;

    if (state.permissions.length && ROUTE_PERMS[key] && !state.permissions.includes(ROUTE_PERMS[key])) {
        view.innerHTML = `<div class="card card-pad">ไม่มีสิทธิ์เข้าถึงหน้านี้</div>`;
        return;
    }

    setActiveNav(key);
    view.innerHTML = `<div class="empty">กำลังโหลด...</div>`;

    try {
        const mod = await import(`./views/${key}.js`);
        await mod.render(view, { user: state.user, permissions: state.permissions, navigate, params });
    } catch (e) {
        console.error(e);
        view.innerHTML = `<div class="card card-pad">ไม่พบหน้านี้ หรือเกิดข้อผิดพลาด: ${escapeHtml(e.message || '')}</div>`;
    }

    refreshBadges();
}

export function navigate(routeKey, params) {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    const target = `#/${routeKey}${qs}`;
    if (location.hash === target) {
        loadView(currentRoute());
    } else {
        location.hash = target;
    }
}

async function refreshBadges() {
    const badges = {};
    try {
        if (state.permissions.includes('withdraw.approve') || state.permissions.includes('withdraw.create')) {
            const wd = await api.get('/withdrawals');
            if (wd.pending_count) badges.withdraw = { count: wd.pending_count, warn: true };
        }
    } catch (e) { /* ignore */ }
    updateBadges(badges);
}

function renderLogin(message) {
    root.innerHTML = `
    <div class="auth-wrap">
      <div class="auth-card">
        <h1>เข้าสู่ระบบ</h1>
        <p class="sub">ระบบฝากขาย ร้านค้าสหการ</p>
        ${message ? `<div class="error-box" style="margin-bottom:14px">${escapeHtml(message)}</div>` : ''}
        <form id="login-form" style="display:flex;flex-direction:column;gap:14px">
          <label class="field">ชื่อผู้ใช้ <input name="username" required autofocus></label>
          <label class="field">รหัสผ่าน <input name="password" type="password" required></label>
          <button type="submit" class="btn">เข้าสู่ระบบ</button>
        </form>
      </div>
    </div>`;

    document.getElementById('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        try {
            const data = await api.post('/auth/login', {
                username: form.get('username'), password: form.get('password'),
            });
            onAuthenticated(data);
        } catch (err) {
            renderLogin(err.message);
        }
    });
}

function onAuthenticated(data) {
    state.user = data.user;
    state.permissions = data.permissions || [];
    setCsrfToken(data.csrf_token);

    renderShell(root, { user: state.user, permissions: state.permissions });

    document.getElementById('theme-toggle').addEventListener('click', () => {
        cycleTheme();
        updateThemeButton();
    });
    document.getElementById('logout-btn').addEventListener('click', async () => {
        try { await api.post('/auth/logout'); } catch (e) { /* ignore */ }
        location.hash = '';
        unmountAssistant();
        renderLogin();
    });
    document.getElementById('side-nav').addEventListener('click', (e) => {
        const btn = e.target.closest('.nav-btn');
        if (btn) navigate(btn.dataset.route);
    });

    window.addEventListener('hashchange', () => loadView(currentRoute()));
    loadView(currentRoute());

    if (state.permissions.includes('ai.use')) {
        mountAssistant({ user: state.user, navigate });
    }
}

window.addEventListener('app:unauthorized', () => { unmountAssistant(); renderLogin('เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่'); });

(async function boot() {
    try {
        const data = await api.get('/auth/me');
        onAuthenticated(data);
    } catch (e) {
        renderLogin();
    }
})();
