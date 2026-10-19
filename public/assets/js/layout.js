import { escapeHtml, fmtNow } from './util.js';
import { currentTheme, cycleTheme, themeMeta } from './theme.js';

const ICONS = {
    dashboard: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect></svg>',
    receive: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"></path><path d="M2 4h20v4H2z"></path><path d="M12 12v5"></path><path d="M9.5 14.5 12 17l2.5-2.5"></path></svg>',
    ret: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"></path><path d="M2 4h20v4H2z"></path><path d="M12 17v-5"></path><path d="M9.5 14.5 12 12l2.5 2.5"></path></svg>',
    count: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M8 8h8M8 12h8M8 16h4"></path></svg>',
    sales: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path></svg>',
    inventory: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"></path><path d="M3.3 7.5L12 12l8.7-4.5M12 12v9"></path></svg>',
    purchase: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><path d="M3 6h18"></path><path d="M12 10v6M9 13h6"></path></svg>',
    consignors: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path></svg>',
    passbook: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2z"></path><path d="M8 3v18"></path></svg>',
    withdraw: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="13" rx="2"></rect><path d="M2 10h20"></path><circle cx="17" cy="14.5" r="1.6"></circle></svg>',
    reports: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path></svg>',
    settings: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.05A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.33l-.06.06A2 2 0 1 1 4.24 16.9l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.05A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.33-1.87l-.06-.06A2 2 0 1 1 7.04 4.24l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.05A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v0c0 .7.42 1.32 1.05 1.55H21a2 2 0 1 1 0 4h-.05c-.63.23-1.05.85-1.05 1.45z"></path></svg>',
    audit: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>',
    ai: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"></path></svg>',
    aiusage: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M14.8 9.2a2.6 2.6 0 0 0-2.8-1.4c-1.3.2-2.2 1-2.2 2 0 2.6 5.4 1.4 5.4 4.2 0 1.1-1 2-2.4 2.1a2.8 2.8 0 0 1-3-1.5"></path><path d="M12 6.5v1.3M12 16.2v1.3"></path></svg>',
    migrations: '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path></svg>',
};

const PAGES = [
    { group: 'งานประจำวัน', items: [
        { key: 'dashboard', label: 'แดชบอร์ด', perm: 'dashboard.view' },
        { key: 'sales', label: 'ขายสินค้า (POS)', perm: 'sales.create' },
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
        { key: 'myrequests', label: 'แจ้งฝากสินค้า', perm: 'consignor.portal', icon: 'receive' },
        { key: 'mypassbook', label: 'สมุดบัญชีของฉัน', perm: 'consignor.portal', icon: 'passbook' },
        { key: 'myproducts', label: 'สินค้าที่ฝากขาย', perm: 'consignor.portal', icon: 'inventory' },
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

export function renderShell(root, { user, permissions, impersonator = null, badges = {} }) {
    const has = (perm) => permissions.includes(perm);
    const initials = (user.full_name || user.username).slice(0, 2);

    root.innerHTML = `
    <div class="app-shell">
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
                <button type="button" class="nav-btn" data-route="${it.key}">
                  ${ICONS[it.icon || it.key] || ''}<span>${escapeHtml(it.label)}</span>
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
