import { escapeHtml, fmtNow } from './util.js';
import { currentTheme, cycleTheme, themeMeta } from './theme.js';

const svg = (inner) => `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

// One icon per menu item (keyed by route), each distinct.
const ICONS = {
    dashboard: svg('<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>'),
    sales: svg('<circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path>'),
    drawer: svg('<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M2 10h20"></path><path d="M10 15h4"></path>'),
    wages: svg('<circle cx="9" cy="7" r="4"></circle><path d="M3 21v-2a4 4 0 0 1 4-4h4"></path><path d="M16 13h6M19 10v6"></path><path d="M15 19h7"></path>'),
    posimport: svg('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M12 11v7"></path><path d="m9 15 3 3 3-3"></path>'),
    purchase: svg('<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"></path><path d="M15 18H9"></path><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"></path><circle cx="17" cy="18" r="2"></circle><circle cx="7" cy="18" r="2"></circle>'),
    inventory: svg('<path d="M21 8l-9-5-9 5v8l9 5 9-5z"></path><path d="M3.3 7.5L12 12l8.7-4.5M12 12v9"></path>'),
    receive: svg('<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>'),
    ret: svg('<path d="M9 14 4 9l5-5"></path><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"></path>'),
    count: svg('<rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="m8.5 13 1.5 1.5 3-3"></path><path d="M15 13h1M9 18h7"></path>'),
    consignors: svg('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'),
    passbook: svg('<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2z"></path><path d="M8 3v18"></path>'),
    withdraw: svg('<rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2.5"></circle><path d="M6 12h.01M18 12h.01"></path>'),
    myrequests: svg('<path d="m22 2-7 20-4-9-9-4z"></path><path d="M22 2 11 13"></path>'),
    mypassbook: svg('<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2z"></path><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"></path>'),
    myproducts: svg('<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"></path><circle cx="7.5" cy="7.5" r="1.2"></circle>'),
    reports: svg('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path>'),
    settings: svg('<circle cx="12" cy="12" r="3.2"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.05A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.33l-.06.06A2 2 0 1 1 4.24 16.9l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.05A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.33-1.87l-.06-.06A2 2 0 1 1 7.04 4.24l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.05A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v0c0 .7.42 1.32 1.05 1.55H21a2 2 0 1 1 0 4h-.05c-.63.23-1.05.85-1.05 1.45z"></path>'),
    ai: svg('<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"></path>'),
    aiusage: svg('<path d="m12 14 4-4"></path><path d="M3.34 19a10 10 0 1 1 17.32 0"></path>'),
    migrations: svg('<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path>'),
    audit: svg('<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path>'),
};

const PAGES = [
    { group: 'งานประจำวัน', items: [
        { key: 'dashboard', label: 'แดชบอร์ด', perm: 'dashboard.view' },
        { key: 'sales', label: 'ขายสินค้า (POS)', perm: 'sales.create' },
        { key: 'drawer', label: 'เงินในลิ้นชัก / ปิดยอด', perm: 'sales.create' },
        { key: 'wages', label: 'ค่าแรงพนักงาน', perm: 'wages.manage' },
        { key: 'posimport', label: 'นำเข้ายอดขายจาก POS', perm: 'sales.import' },
        { key: 'purchase', label: 'ซื้อสินค้าเข้าร้าน', perm: 'purchase.create' },
        { key: 'inventory', label: 'สต๊อกสินค้าของร้าน', perm: 'reports.view' },
        { key: 'receive', label: 'รับฝากสินค้า', perm: 'receive.create' },
        { key: 'ret', label: 'คืนสินค้าผู้ฝาก', perm: 'return.create' },
        { key: 'count', label: 'ตรวจนับสต๊อก', perm: 'stockcount.create' },
    ]},
    { group: 'ผู้ฝากขาย', items: [
        { key: 'consignors', label: 'ทะเบียนผู้ฝากขาย', perm: 'consignors.view' },
        { key: 'passbook', label: 'สมุดบัญชีผู้ฝาก', perm: 'passbook.view' },
        { key: 'withdraw', label: 'เบิก / จ่ายเงิน', perm: 'withdraw.create' },
    ]},
    { group: 'ผู้ฝากขาย (ของฉัน)', items: [
        { key: 'myrequests', label: 'แจ้งฝากสินค้า', perm: 'consignor.portal' },
        { key: 'mypassbook', label: 'สมุดบัญชีของฉัน', perm: 'consignor.portal' },
        { key: 'myproducts', label: 'สินค้าที่ฝากขาย', perm: 'consignor.portal' },
    ]},
    { group: 'รายงานและระบบ', items: [
        { key: 'reports', label: 'รายงานสรุป', perm: 'reports.view' },
        { key: 'settings', label: 'ตั้งค่าระบบ', perm: 'settings.view' },
        { key: 'ai', label: 'ตั้งค่าผู้ช่วย AI', perm: 'ai.manage' },
        { key: 'aiusage', label: 'การใช้งานผู้ช่วย AI', perm: 'ai.usage' },
        { key: 'migrations', label: 'จัดการฐานข้อมูล (Migrations)', perm: 'migrations.manage' },
        { key: 'audit', label: 'ประวัติการแก้ไข', perm: 'audit.view' },
    ]},
];

const PAGE_TITLES = {
    dashboard: ['หน้าแรก', 'แดชบอร์ดภาพรวม'],
    sales: ['งานประจำวัน', 'ขายสินค้า (POS)'],
    drawer: ['งานประจำวัน', 'เงินในลิ้นชักและปิดยอดประจำวัน'],
    wages: ['งานประจำวัน', 'เบิกจ่ายค่าแรงพนักงานและผู้จัดการ'],
    posimport: ['งานประจำวัน', 'นำเข้ายอดขายจากระบบ POS ภายนอก'],
    purchase: ['งานประจำวัน', 'ซื้อสินค้าเข้าร้าน'],
    inventory: ['งานประจำวัน', 'สต๊อก ต้นทุน และกำไรสินค้าของร้าน'],
    receive: ['งานประจำวัน', 'รับฝากสินค้าเข้าร้าน'],
    ret: ['งานประจำวัน', 'คืนสินค้าให้ผู้ฝากขาย'],
    count: ['งานประจำวัน', 'ตรวจนับสต๊อกสินค้าฝากขาย'],
    consignors: ['ผู้ฝากขาย', 'ทะเบียนผู้ฝากขาย'],
    passbook: ['ผู้ฝากขาย', 'สมุดบัญชีผู้ฝากขาย'],
    withdraw: ['ผู้ฝากขาย', 'เบิกเงิน / จ่ายเงินผู้ฝากขาย'],
    myrequests: ['ผู้ฝากขาย', 'แจ้งฝากสินค้า'],
    mypassbook: ['ผู้ฝากขาย', 'สมุดบัญชีของฉัน'],
    myproducts: ['ผู้ฝากขาย', 'สินค้าที่ฉันฝากขาย'],
    reports: ['รายงานและระบบ', 'รายงานสรุป'],
    settings: ['รายงานและระบบ', 'ตั้งค่าระบบฝากขาย'],
    ai: ['รายงานและระบบ', 'ตั้งค่าผู้ช่วย AI'],
    aiusage: ['รายงานและระบบ', 'การใช้งานและค่าใช้จ่ายผู้ช่วย AI'],
    migrations: ['รายงานและระบบ', 'จัดการฐานข้อมูล (Migrations)'],
    audit: ['รายงานและระบบ', 'ประวัติการแก้ไขข้อมูล'],
};

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย', consignor: 'ผู้ฝากขาย' };

const SIDEBAR_KEY = 'coac.sidebarCollapsed';

// Remembered per browser; with no saved choice, start collapsed on narrow screens.
function sidebarCollapsed() {
    let saved = null;
    try { saved = localStorage.getItem(SIDEBAR_KEY); } catch (e) { /* ignore */ }
    if (saved !== null) return saved === '1';
    return window.matchMedia('(max-width: 900px)').matches;
}

export function renderShell(root, { user, permissions, impersonator = null, badges = {} }) {
    const has = (perm) => permissions.includes(perm);
    const initials = (user.full_name || user.username).slice(0, 2);

    root.innerHTML = `
    <div class="app-shell${sidebarCollapsed() ? ' sidebar-collapsed' : ''}">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo">สห</div>
          <div>
            <div class="name">ร้านค้าสหการ</div>
            <div class="sub">ระบบฝากขาย</div>
          </div>
        </div>
        <nav id="side-nav">
          ${PAGES.map((g) => {
              const items = g.items.filter((it) => has(it.perm));
              if (!items.length) return '';
              return `<div class="group-label">${escapeHtml(g.group)}</div>` + items.map((it) => `
                <button type="button" class="nav-btn" data-route="${it.key}" title="${escapeHtml(it.label)}">
                  ${ICONS[it.key] || ''}<span>${escapeHtml(it.label)}</span>
                  ${badges[it.key] ? `<span class="badge${badges[it.key].warn ? ' warn' : ''}">${badges[it.key].count}</span>` : ''}
                </button>`).join('');
          }).join('')}
        </nav>
        <div class="footer-widget">
          <div class="box">
            <div class="lbl">เข้าสู่ระบบในชื่อ</div>
            <div class="val" style="font-size:14px">${escapeHtml(user.full_name)}</div>
          </div>
        </div>
      </aside>
      <div class="main">
        <header class="topbar">
          <button type="button" class="btn-sidebar-toggle" id="sidebar-toggle" title="ย่อ / ขยายเมนู" aria-label="ย่อ / ขยายเมนู">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
          </button>
          <div>
            <div class="crumb" id="topbar-crumb"></div>
            <h1 id="topbar-title"></h1>
          </div>
          <div class="right">
            <div class="chip" id="topbar-date"></div>
            <button type="button" class="btn-theme" id="theme-toggle"></button>
            <div class="user-box">
              <div class="avatar">${escapeHtml(initials)}</div>
              <div>
                <div class="name">${escapeHtml(user.full_name)}</div>
                ${(user.roles || []).length > 1
                    ? `<select class="role-switch" id="role-switch" title="สลับบทบาท">${user.roles.map((r) =>
                        `<option value="${r}" ${r === user.role ? 'selected' : ''}>${escapeHtml(ROLE_LABEL[r] || r)}</option>`).join('')}</select>`
                    : `<div class="role">${escapeHtml(ROLE_LABEL[user.role] || user.role)}</div>`}
              </div>
              <span class="logout-link" id="logout-btn">${impersonator ? 'กลับเป็นผู้ดูแลระบบ' : 'ออกจากระบบ'}</span>
            </div>
          </div>
        </header>
        ${impersonator ? `<div class="impersonate-bar">
          กำลังสวมสิทธิ์เป็น <b>${escapeHtml(user.full_name)}</b> (${escapeHtml(ROLE_LABEL[user.role] || user.role)})
          — ผู้ดูแลระบบ ${escapeHtml(impersonator.full_name)} · กด «กลับเป็นผู้ดูแลระบบ» เพื่อออกและกลับสู่สิทธิ์เดิม
        </div>` : ''}
        <div class="content" id="view"></div>
      </div>
    </div>
  `;

    updateThemeButton();
    document.getElementById('sidebar-toggle').addEventListener('click', () => {
        const collapsed = root.querySelector('.app-shell').classList.toggle('sidebar-collapsed');
        try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
    });
    document.getElementById('topbar-date').textContent = fmtNow({
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    });
}

export function updateThemeButton() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const { icon, label } = themeMeta();
    btn.innerHTML = `<span>${icon}</span><span>${escapeHtml(label)}</span>`;
}

export function setActiveNav(routeKey) {
    document.querySelectorAll('.nav-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.route === routeKey);
    });
    const [crumb, title] = PAGE_TITLES[routeKey] || ['', ''];
    const crumbEl = document.getElementById('topbar-crumb');
    const titleEl = document.getElementById('topbar-title');
    if (crumbEl) crumbEl.textContent = crumb;
    if (titleEl) titleEl.textContent = title;
}

export function updateBadges(badges) {
    for (const [key, info] of Object.entries(badges)) {
        const btn = document.querySelector(`.nav-btn[data-route="${key}"]`);
        if (!btn) continue;
        let el = btn.querySelector('.badge');
        if (!info || !info.count) {
            if (el) el.remove();
            continue;
        }
        if (!el) {
            el = document.createElement('span');
            el.className = 'badge';
            btn.appendChild(el);
        }
        el.classList.toggle('warn', !!info.warn);
        el.textContent = info.count;
    }
}
