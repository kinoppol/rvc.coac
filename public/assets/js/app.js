import { api, setCsrfToken } from './api.js';
import { renderShell, setActiveNav, updateThemeButton, updateBadges } from './layout.js';
import { initTheme, cycleTheme } from './theme.js';
import { escapeHtml, setAppTimeZone } from './util.js';
import { mountAssistant, unmountAssistant } from './assistant.js';
import { alertDialog } from './dialog.js';

const ROUTE_PERMS = {
    dashboard: 'dashboard.view', sales: 'sales.create', receive: 'receive.create',
    ret: 'return.create', count: 'stockcount.create', consignors: 'consignors.view',
    passbook: 'passbook.view', withdraw: 'withdraw.create', reports: 'reports.view',
    settings: 'settings.view', migrations: 'migrations.manage', audit: 'audit.view',
    ai: 'ai.manage', purchase: 'purchase.create', inventory: 'reports.view', aiusage: 'ai.usage',
    posimport: 'sales.import', drawer: 'sales.create',
    mypassbook: 'consignor.portal', myproducts: 'consignor.portal', myrequests: 'consignor.portal',
};

const state = { user: null, permissions: [] };
const root = document.getElementById('root');

initTheme();

function currentRoute() {
    const hash = location.hash.replace(/^#\/?/, '');
    const [key, queryStr] = hash.split('?');
    const params = Object.fromEntries(new URLSearchParams(queryStr || ''));
    return { key: key || homeRoute(), params };
}

/** Consignor-only menus have no dashboard; land on their own passbook. */
function homeRoute() {
    return state.permissions.includes('dashboard.view') || !state.permissions.includes('consignor.portal') ? 'dashboard' : 'mypassbook';
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
    try {
        if (state.permissions.includes('receive.create')) {
            const rq = await api.get('/receive/requests');
            badges.receive = { count: rq.pending_count, warn: true };
        }
    } catch (e) { /* ignore (e.g. migration not run yet) */ }
    updateBadges(badges);
}

// both | password | sso — set by the admin; unknown (API down) = offer both.
let loginMethods = 'both';
// In SSO-only mode the password form is the admin fallback, hidden behind a link.
let adminFormOpen = new URLSearchParams(location.search).get('login') === 'password';

async function showLogin(message) {
    try {
        loginMethods = (await api.get('/auth/methods')).login_methods || 'both';
    } catch (e) { /* keep last known */ }
    renderLogin(message);
}

function renderLogin(message) {
    const ssoBtn = (primary) => `<a class="btn ${primary ? '' : 'secondary'}" href="auth/login" style="display:block;text-align:center;text-decoration:none">ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE</a>`;
    const form = (note) => `
        ${note ? `<p class="muted" style="font-size:12.5px;margin:0 0 10px">${note}</p>` : ''}
        <form id="login-form" style="display:flex;flex-direction:column;gap:14px">
          <label class="field">ชื่อผู้ใช้ <input name="username" required autofocus></label>
          <label class="field">รหัสผ่าน <input name="password" type="password" required></label>
          <button type="submit" class="btn">เข้าสู่ระบบ</button>
        </form>`;

    let body;
    if (loginMethods === 'password') {
        body = form();
    } else if (loginMethods === 'sso') {
        body = adminFormOpen
            ? `${form('สำหรับผู้ดูแลระบบ กรณีเข้าสู่ระบบผ่าน RVC-ONE ไม่ได้')}
               <div style="text-align:center;margin-top:14px"><a href="#" id="admin-login-toggle" class="muted" style="font-size:12.5px">← กลับไปเข้าสู่ระบบผ่าน RVC-ONE</a></div>`
            : `${ssoBtn(true)}
               <div style="text-align:center;margin-top:16px"><a href="#" id="admin-login-toggle" class="muted" style="font-size:12.5px">ผู้ดูแลระบบ: ลงชื่อเข้าใช้ด้วยรหัสผ่าน</a></div>`;
    } else {
        body = `${form()}
        <div class="muted" style="text-align:center;font-size:12.5px;margin:14px 0">หรือ</div>
        ${ssoBtn(false)}`;
    }

    root.innerHTML = `
    <div class="auth-wrap">
      <div class="auth-card">
        <h1>เข้าสู่ระบบ</h1>
        <p class="sub">ระบบฝากขาย ร้านค้าสหการ</p>
        ${message ? `<div class="error-box" style="margin-bottom:14px">${escapeHtml(message)}</div>` : ''}
        ${body}
      </div>
    </div>`;

    document.getElementById('admin-login-toggle')?.addEventListener('click', (e) => {
        e.preventDefault();
        adminFormOpen = !adminFormOpen;
        renderLogin();
    });

    document.getElementById('login-form')?.addEventListener('submit', async (e) => {
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
    setAppTimeZone(data.timezone);

    renderShell(root, { user: state.user, permissions: state.permissions, impersonator: data.impersonator });

    document.getElementById('theme-toggle').addEventListener('click', () => {
        cycleTheme();
        updateThemeButton();
    });
    document.getElementById('logout-btn').addEventListener('click', async () => {
        let restored = false;
        try { restored = !!(await api.post('/auth/logout')).restored; } catch (e) { /* ignore */ }
        location.hash = '';
        // Leaving an impersonated account: reload straight back into the admin's own session.
        if (restored) { location.reload(); return; }
        unmountAssistant();
        showLogin();
    });
    document.getElementById('role-switch')?.addEventListener('change', async (e) => {
        try {
            await api.post('/auth/role', { role: e.target.value });
            location.hash = '';
            location.reload();
        } catch (err) {
            alertDialog(err.message, { tone: 'error' });
        }
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
        showLogin();
    }
})();
