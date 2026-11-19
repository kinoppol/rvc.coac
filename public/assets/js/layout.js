import { escapeHtml, fmtNow } from './util.js';
import { currentTheme, cycleTheme, themeMeta, setTheme, THEMES } from './theme.js';
import { keyboardEnabled, setKeyboardEnabled } from './osk.js';

const svg = (inner) => `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;

// One icon per menu item (keyed by route), each distinct.
const ICONS = {
    dashboard: svg('<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>'),
    sales: svg('<circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"></path>'),
    mysales: svg('<path d="M4 2v20l2-1.5L8 22l2-1.5L12 22l2-1.5L16 22l2-1.5L20 22V2l-2 1.5L16 2l-2 1.5L12 2l-2 1.5L8 2 6 3.5z"></path><path d="M8 8h7M8 12h7M8 16h4"></path>'),
    saleedits: svg('<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path><path d="m15 5 3 3"></path>'),
    promos: svg('<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"></path><circle cx="7.5" cy="7.5" r="1.5"></circle><path d="M10 15l5-5"></path>'),
    closing: svg('<path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>'),
    monthclose: svg('<rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path><path d="M9 16l2 2 4-4"></path>'),
    drawer: svg('<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M2 10h20"></path><path d="M10 15h4"></path>'),
    wages: svg('<circle cx="9" cy="7" r="4"></circle><path d="M3 21v-2a4 4 0 0 1 4-4h4"></path><path d="M16 13h6M19 10v6"></path><path d="M15 19h7"></path>'),
    capital: svg('<path d="M3 21h18"></path><path d="M5 21V10l7-5 7 5v11"></path><path d="M12 11v6M10 13h4"></path>'),
    posimport: svg('<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M12 11v7"></path><path d="m9 15 3 3 3-3"></path>'),
    purchase: svg('<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"></path><path d="M15 18H9"></path><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"></path><circle cx="17" cy="18" r="2"></circle><circle cx="7" cy="18" r="2"></circle>'),
    reorder: svg('<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path><rect x="9" y="3" width="6" height="4" rx="1"></rect><path d="M9 12h6M9 16h4"></path>'),
    inventory: svg('<path d="M21 8l-9-5-9 5v8l9 5 9-5z"></path><path d="M3.3 7.5L12 12l8.7-4.5M12 12v9"></path>'),
    receive: svg('<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>'),
    ret: svg('<path d="M9 14 4 9l5-5"></path><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"></path>'),
    count: svg('<rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><path d="m8.5 13 1.5 1.5 3-3"></path><path d="M15 13h1M9 18h7"></path>'),
    consignors: svg('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>'),
    passbook: svg('<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2z"></path><path d="M8 3v18"></path>'),
    withdraw: svg('<rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2.5"></circle><path d="M6 12h.01M18 12h.01"></path>'),
    holds: svg('<path d="M3 10 12 4l9 6"></path><path d="M5 10v8M10 10v8M14 10v8M19 10v8"></path><path d="M3 21h18"></path>'),
    myrequests: svg('<path d="m22 2-7 20-4-9-9-4z"></path><path d="M22 2 11 13"></path>'),
    mypassbook: svg('<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2z"></path><path d="M22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7z"></path>'),
    myproducts: svg('<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"></path><circle cx="7.5" cy="7.5" r="1.2"></circle>'),
    reports: svg('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path>'),
    settings: svg('<circle cx="12" cy="12" r="3.2"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.05A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.33l-.06.06A2 2 0 1 1 4.24 16.9l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.05A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.33-1.87l-.06-.06A2 2 0 1 1 7.04 4.24l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.05A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v0c0 .7.42 1.32 1.05 1.55H21a2 2 0 1 1 0 4h-.05c-.63.23-1.05.85-1.05 1.45z"></path>'),
    ai: svg('<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"></path>'),
    aiusage: svg('<path d="m12 14 4-4"></path><path d="M3.34 19a10 10 0 1 1 17.32 0"></path>'),
    migrations: svg('<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path>'),
    security: svg('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path>'),
    backup: svg('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>'),
    devices: svg('<rect x="5" y="2" width="14" height="20" rx="2"></rect><path d="M11 18h2"></path><path d="M9 7h6M9 11h6"></path>'),
    mobileapp: svg('<rect x="6" y="2" width="12" height="20" rx="2.5"></rect><path d="M11 18h2"></path><path d="m9.5 9 2 2 3.5-3.5"></path>'),
    audit: svg('<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path>'),
};

ICONS.android = ICONS.mobileapp;
ICONS.suppliers = svg('<path d="M3 21V9l9-6 9 6v12"></path><path d="M9 21v-6h6v6"></path><path d="M3 21h18"></path>');
ICONS.ledger = svg('<path d="M4 4h12a2 2 0 0 1 2 2v14H6a2 2 0 0 1-2-2z"></path><path d="M18 8h2v12a2 2 0 0 1-2 2H6"></path><path d="M8 8h6M8 12h6M8 16h3"></path>');

// The menu: a few pages stand alone; the rest sit under main menus that open one at a time.
// A main menu with only one page the user may open shows that page on its own.
// hideIf: left out for users who also hold that permission (they reach the page another way).
const PAGES = [
    { key: 'dashboard', label: 'แดชบอร์ด', perm: 'dashboard.view' },
    { group: 'sell', label: 'งานขายหน้าร้าน', icon: 'sales', items: [
        { key: 'sales', label: 'ขายสินค้า (POS)', perm: 'sales.create' },
        { key: 'mysales', label: 'ประวัติการขาย', perm: 'sales.create' },
        { key: 'drawer', label: 'เงินในลิ้นชัก / ปิดยอด', perm: 'sales.create' },
        { key: 'promos', label: 'โปรโมชั่นจับคู่สินค้า', perm: 'reports.view' },
        { key: 'saleedits', label: 'อนุมัติแก้ไขการขาย', perm: 'sales.edit.approve' },
        { key: 'posimport', label: 'นำเข้ายอดขายจาก POS', perm: 'sales.import' },
    ]},
    { group: 'buy', label: 'ซื้อสินค้าเข้าร้าน', icon: 'purchase', items: [
        { key: 'purchase', label: 'บันทึกรายการซื้อสินค้า', perm: 'purchase.create' },
        { key: 'reorder', label: 'รายการซื้อสินค้าใกล้หมด', perm: 'purchase.create' },
        { key: 'suppliers', label: 'ทะเบียนผู้จำหน่ายสินค้า', perm: 'purchase.create' },
    ]},
    { group: 'stock', label: 'สต๊อกสินค้า', icon: 'inventory', items: [
        { key: 'inventory', label: 'สต๊อกสินค้าของร้าน', perm: 'reports.view' },
        { key: 'count', label: 'ตรวจนับสต๊อก', perm: 'stockcount.create' },
    ]},
    { group: 'books', label: 'บัญชีของร้าน', icon: 'ledger', items: [
        { key: 'closing', label: 'สรุปบัญชีรายวัน / ส่งเงิน', perm: 'closing.submit' },
        { key: 'monthclose', label: 'ปิดบัญชีประจำเดือน', perm: 'reports.view' },
        { key: 'capital', label: 'งบลงทุน / เบิกกำไร', perm: 'capital.manage' },
        { key: 'wages', label: 'เบิกเงินค่าแรงพนักงาน', perm: 'wages.manage' },
    ]},
    { group: 'consign', label: 'การรับฝากขายสินค้า', icon: 'consignors', items: [
        { key: 'consignors', label: 'ทะเบียนผู้ฝากขาย', perm: 'consignors.view' },
        { key: 'receive', label: 'รับฝากสินค้า', perm: 'receive.create' },
        { key: 'ret', label: 'คืนสินค้าผู้ฝาก', perm: 'return.create' },
        { key: 'withdraw', label: 'เบิก / จ่ายเงิน', perm: 'withdraw.create' },
        // Opened from each consignor's row in the register; on its own only for those without the register.
        { key: 'passbook', label: 'สมุดบัญชีผู้ฝาก', perm: 'passbook.view', hideIf: 'consignors.view' },
        { key: 'holds', label: 'บัญชีเพื่อผู้ฝากขาย', perm: 'hold.manage' },
    ]},
    { group: 'portal', label: 'ผู้ฝากขาย (ของฉัน)', icon: 'mypassbook', items: [
        { key: 'myrequests', label: 'แจ้งฝากสินค้า', perm: 'consignor.portal' },
        { key: 'mypassbook', label: 'สมุดบัญชีของฉัน', perm: 'consignor.portal' },
        { key: 'myproducts', label: 'สินค้าที่ฝากขาย', perm: 'consignor.portal' },
    ]},
    { key: 'reports', label: 'รายงานสรุป', perm: 'reports.view' },
    { group: 'system', label: 'ระบบ', icon: 'settings', items: [
        { key: 'settings', label: 'ตั้งค่าระบบ', perm: 'settings.view' },
        { key: 'ai', label: 'ตั้งค่าผู้ช่วย AI', perm: 'ai.manage' },
        { key: 'aiusage', label: 'การใช้งานผู้ช่วย AI', perm: 'ai.usage' },
        // Every signed-in user pairs their own phone (no permission needed); the POS tab needs devices.manage.
        { key: 'android', label: 'แอป Android', perm: null },
        { key: 'audit', label: 'ประวัติการแก้ไข', perm: 'audit.view' },
    ]},
];

// Routes shown under another menu item (tabs of one page).
const NAV_ALIAS = { mobileapp: 'android', devices: 'android' };

const ANDROID_TITLE = ['ระบบ', 'แอป Android: แอปผู้จัดการบนมือถือ และแอป POS'];
const PAGE_TITLES = {
    dashboard: ['หน้าแรก', 'แดชบอร์ดภาพรวม'],
    sales: ['งานขายหน้าร้าน', 'ขายสินค้า (POS)'],
    mysales: ['งานขายหน้าร้าน', 'ประวัติการขาย และขอแก้ไขการขาย'],
    saleedits: ['งานขายหน้าร้าน', 'อนุมัติคำขอแก้ไขการขาย'],
    promos: ['งานขายหน้าร้าน', 'โปรโมชั่นจับคู่สินค้า'],
    drawer: ['งานขายหน้าร้าน', 'เงินในลิ้นชักและปิดยอดประจำวัน'],
    posimport: ['งานขายหน้าร้าน', 'นำเข้ายอดขายจากระบบ POS ภายนอก'],
    closing: ['บัญชีของร้าน', 'สรุปบัญชีรายวันและส่งเงินสดให้วิทยาลัย'],
    monthclose: ['บัญชีของร้าน', 'สรุปยอดปิดบัญชีประจำเดือน'],
    wages: ['บัญชีของร้าน', 'เบิกจ่ายค่าแรงพนักงานและผู้จัดการ'],
    capital: ['บัญชีของร้าน', 'งบลงทุน เบิกเงินออกจากกำไร และกำไรสุทธิรายเดือน'],
    'qr-approve': ['เข้าสู่ระบบ', 'อนุมัติการเข้าสู่ระบบด้วย QR-Code'],
    scanlink: ['สต๊อกสินค้า', 'มือถือเป็นเครื่องสแกนบาร์โค้ดให้คอมพิวเตอร์'],
    purchase: ['ซื้อสินค้าเข้าร้าน', 'บันทึกรายการซื้อสินค้าเข้าร้าน'],
    reorder: ['ซื้อสินค้าเข้าร้าน', 'รายการซื้อสินค้าใกล้หมด เปรียบเทียบต้นทุนผู้ขาย'],
    suppliers: ['ซื้อสินค้าเข้าร้าน', 'ทะเบียนผู้จำหน่ายสินค้า และรวมชื่อผู้จำหน่ายที่ซ้ำกัน'],
    inventory: ['สต๊อกสินค้า', 'สต๊อก ต้นทุน และกำไรสินค้าของร้าน'],
    count: ['สต๊อกสินค้า', 'ตรวจนับสต๊อกสินค้า'],
    receive: ['การรับฝากขายสินค้า', 'รับฝากสินค้าเข้าร้าน'],
    ret: ['การรับฝากขายสินค้า', 'คืนสินค้าให้ผู้ฝากขาย'],
    consignors: ['การรับฝากขายสินค้า', 'ทะเบียนผู้ฝากขาย'],
    passbook: ['การรับฝากขายสินค้า', 'สมุดบัญชีผู้ฝากขาย'],
    withdraw: ['การรับฝากขายสินค้า', 'เบิกเงิน / จ่ายเงินผู้ฝากขาย'],
    holds: ['การรับฝากขายสินค้า', 'บัญชีเพื่อผู้ฝากขาย'],
    myrequests: ['ผู้ฝากขาย', 'แจ้งฝากสินค้า'],
    mypassbook: ['ผู้ฝากขาย', 'สมุดบัญชีของฉัน'],
    myproducts: ['ผู้ฝากขาย', 'สินค้าที่ฉันฝากขาย'],
    reports: ['รายงาน', 'รายงานสรุป'],
    settings: ['ระบบ', 'ตั้งค่าระบบบริหารบัญชี'],
    ai: ['ระบบ', 'ตั้งค่าผู้ช่วย AI'],
    aiusage: ['ระบบ', 'การใช้งานและค่าใช้จ่ายผู้ช่วย AI'],
    migrations: ['ระบบ', 'จัดการฐานข้อมูล (Migrations)'],
    security: ['ระบบ', 'ตรวจสอบความปลอดภัย / ค้นหา web shell'],
    backup: ['ระบบ', 'แบ็คอัพและกู้คืนข้อมูล (รูปภาพ ฐานข้อมูล ไฟล์อื่น ๆ)'],
    android: ANDROID_TITLE,
    devices: ANDROID_TITLE,
    mobileapp: ANDROID_TITLE,
    audit: ['ระบบ', 'ประวัติการแก้ไขข้อมูล'],
};

const CHEVRON = '<svg class="nav-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"></path></svg>';

function navItemHtml(it, badges, sub = false) {
    return `
                <button type="button" class="nav-btn${sub ? ' nav-sub-btn' : ''}" data-route="${it.key}" title="${escapeHtml(it.label)}">
                  ${ICONS[it.key] || ''}<span>${escapeHtml(it.label)}</span>
                  ${badges[it.key] ? `<span class="badge${badges[it.key].warn ? ' warn' : ''}">${badges[it.key].count}</span>` : ''}
                </button>`;
}

/** The menu for this user: pages they may open, main menus with their pages (one page = shown alone). */
function navHtml(visible, badges) {
    return PAGES.map((e) => {
        if (!e.group) return visible(e) ? navItemHtml(e, badges) : '';
        const items = e.items.filter(visible);
        if (!items.length) return '';
        if (items.length === 1) return navItemHtml(items[0], badges);
        return `
              <div class="nav-group" data-group="${e.group}">
                <button type="button" class="nav-group-btn" data-group-toggle="${e.group}" aria-expanded="false" title="${escapeHtml(e.label)}">
                  ${ICONS[e.icon] || ''}<span>${escapeHtml(e.label)}</span><span class="badge" data-group-badge hidden></span>${CHEVRON}
                </button>
                <div class="nav-sub">${items.map((it) => navItemHtml(it, badges, true)).join('')}</div>
              </div>`;
    }).join('');
}

/** Opens one main menu and closes the others (null closes all). */
function openGroup(name) {
    document.querySelectorAll('#side-nav .nav-group').forEach((g) => {
        const open = g.dataset.group === name;
        g.classList.toggle('open', open);
        g.querySelector('[data-group-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}

/** A closed main menu shows the total of its pages' badges, so nothing waiting is hidden. */
function markGroupBadges() {
    document.querySelectorAll('#side-nav .nav-group').forEach((g) => {
        let n = 0;
        let warn = false;
        g.querySelectorAll('.nav-sub .badge').forEach((b) => { n += Number(b.textContent) || 0; warn = warn || b.classList.contains('warn'); });
        const el = g.querySelector('[data-group-badge]');
        el.hidden = !n;
        el.textContent = n;
        el.classList.toggle('warn', warn);
    });
}

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย', closer: 'ผู้สรุปบัญชีรายวัน', executive: 'ผู้บริหาร', consignor: 'ผู้ฝากขาย' };

const SIDEBAR_KEY = 'coac.sidebarCollapsed';
// Phones and portrait tablets: the menu slides in over the page instead of taking width.
const DRAWER_MQ = window.matchMedia('(max-width: 900px)');
// ผู้บริหาร: sees everything, changes nothing (the server turns writes away too).
const READ_ONLY_ROLE = 'executive';
// Pages that only do things (selling): left out of a read-only menu.
const READ_ONLY_HIDDEN = ['sales', 'mysales'];

// Tablet mode (this device): the whole system laid out for a tablet held at arm's length —
// full screen, the menu as a drawer, bigger touch targets and text, whatever the width.
const TABLET_KEY = 'coac.tabletMode';
export function tabletMode() {
    try { return localStorage.getItem(TABLET_KEY) === '1'; } catch (e) { return false; }
}
function applyTabletMode() {
    document.body.classList.toggle('tablet-mode', tabletMode());
    markFullscreenButton();
}
function setTabletMode(on) {
    try { localStorage.setItem(TABLET_KEY, on ? '1' : '0'); } catch (e) { /* ignore */ }
    applyTabletMode();
    if (on) enterFullscreen();
    else if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
    closeDrawer();
}
function enterFullscreen() {
    const el = document.documentElement;
    (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el)?.catch?.(() => {});
}
function markFullscreenButton() {
    const b = document.getElementById('fullscreen-btn');
    if (b) b.hidden = !tabletMode() || !!document.fullscreenElement;
}
document.addEventListener('fullscreenchange', markFullscreenButton);
const drawerMode = () => DRAWER_MQ.matches || tabletMode();

// Remembered per browser; with no saved choice, start collapsed on narrow screens.
function sidebarCollapsed() {
    let saved = null;
    try { saved = localStorage.getItem(SIDEBAR_KEY); } catch (e) { /* ignore */ }
    if (saved !== null) return saved === '1';
    return drawerMode();
}

function setDrawer(open) {
    const shell = document.querySelector('.app-shell');
    if (!shell) return;
    shell.classList.toggle('nav-open', open);
    document.body.classList.toggle('nav-drawer-open', open);
    document.getElementById('sidebar-toggle')?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

export function closeDrawer() { setDrawer(false); }

export function renderShell(root, { user, permissions, impersonator = null, badges = {} }) {
    const readOnly = user.role === READ_ONLY_ROLE;
    const has = (perm) => permissions.includes(perm);
    document.body.classList.toggle('read-only', readOnly);
    applyTabletMode();
    const initials = (user.full_name || user.username).slice(0, 2);

    root.innerHTML = `
    <div class="app-shell${sidebarCollapsed() ? ' sidebar-collapsed' : ''}">
      <aside class="sidebar">
        <div class="brand">
          <div class="logo">สห</div>
          <div>
            <div class="name">ร้านค้าสหการ</div>
            <div class="sub">ระบบบริหารบัญชี</div>
          </div>
        </div>
        <nav id="side-nav">
          ${navHtml((it) => (it.perm === null || has(it.perm)) && !(it.hideIf && has(it.hideIf)) && !(readOnly && READ_ONLY_HIDDEN.includes(it.key)), badges)}
        </nav>
        <div class="footer-widget">
          <div class="box">
            <div class="lbl">เข้าสู่ระบบในชื่อ</div>
            <div class="val" style="font-size:14px">${escapeHtml(user.full_name)}</div>
          </div>
        </div>
      </aside>
      <div class="nav-backdrop" id="nav-backdrop"></div>
      <div class="main">
        <header class="topbar">
          <button type="button" class="btn-sidebar-toggle" id="sidebar-toggle" title="ย่อ / ขยายเมนู" aria-label="ย่อ / ขยายเมนู">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
          </button>
          <div class="topbar-titles">
            <div class="crumb" id="topbar-crumb"></div>
            <h1 id="topbar-title"></h1>
          </div>
          <div class="right">
            ${readOnly ? '<span class="pill warn ro-pill" title="บัญชีผู้บริหารดูข้อมูลได้อย่างเดียว">👁 ดูอย่างเดียว</span>' : ''}
            <button type="button" class="btn-theme" id="fullscreen-btn" title="เต็มจอ" aria-label="เต็มจอ" hidden>⛶</button>
            <div class="chip" id="topbar-date"></div>
            <button type="button" class="btn-theme" id="theme-toggle"></button>
            <button type="button" class="btn-theme btn-device" id="device-btn" title="ตั้งค่าเครื่องนี้ (แป้นพิมพ์บนจอ)" aria-label="ตั้งค่าเครื่องนี้" aria-haspopup="true">⌨</button>
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
        const shell = root.querySelector('.app-shell');
        if (drawerMode()) { setDrawer(!shell.classList.contains('nav-open')); return; }
        const collapsed = shell.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
    });
    document.getElementById('nav-backdrop').addEventListener('click', closeDrawer);
    document.getElementById('side-nav').addEventListener('click', (e) => {
        if (e.target.closest('.nav-btn')) { closeDrawer(); return; }
        const g = e.target.closest('[data-group-toggle]');
        if (g) openGroup(g.closest('.nav-group').classList.contains('open') ? null : g.dataset.groupToggle);
    });
    markGroupBadges();
    DRAWER_MQ.addEventListener?.('change', closeDrawer);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });
    document.getElementById('device-btn').addEventListener('click', (e) => toggleDevicePanel(e.currentTarget));
    document.getElementById('fullscreen-btn').addEventListener('click', enterFullscreen);
    markFullscreenButton();
    markDeviceButton();
    document.getElementById('topbar-date').textContent = fmtNow({
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
    });
}

function markDeviceButton() {
    document.getElementById('device-btn')?.classList.toggle('on', keyboardEnabled());
}

// Settings kept in this browser only, for whoever signs in on this device: the page's
// own keyboard (instead of the tablet's) and the colour theme.
let devicePanel = null;
function closeDevicePanel() {
    devicePanel?.remove();
    devicePanel = null;
    document.removeEventListener('pointerdown', outsideDevicePanel, true);
}
function outsideDevicePanel(e) {
    if (devicePanel && !devicePanel.contains(e.target) && !e.target.closest('#device-btn')) closeDevicePanel();
}
function toggleDevicePanel(anchor) {
    if (devicePanel) { closeDevicePanel(); return; }
    devicePanel = document.createElement('div');
    devicePanel.className = 'dev-panel';
    devicePanel.setAttribute('role', 'dialog');
    devicePanel.setAttribute('aria-label', 'ตั้งค่าเครื่องนี้');
    const draw = () => {
        devicePanel.innerHTML = `
          <div class="dev-title">ตั้งค่าเครื่องนี้</div>
          <label class="pos-switch dev-switch">
            <input type="checkbox" data-dev-kb ${keyboardEnabled() ? 'checked' : ''}><span></span>
            <div>ใช้แป้นพิมพ์บนจอของระบบ<small class="muted">ปิดแป้นพิมพ์ของแท็บเล็ต/มือถือ ช่องข้อความเปิดแป้นพิมพ์เล็ก ช่องตัวเลขเปิดแป้นตัวเลข</small></div>
          </label>
          <label class="pos-switch dev-switch">
            <input type="checkbox" data-dev-tablet ${tabletMode() ? 'checked' : ''}><span></span>
            <div>โหมดแท็บเล็ต (เต็มจอ)<small class="muted">แสดงผลเต็มจอทั้งระบบ เมนูเลื่อนออกจากด้านซ้าย ปุ่มและตัวอักษรใหญ่ขึ้น แตะใช้งานง่าย</small></div>
          </label>
          <div class="dev-sub">ธีมหน้าจอ</div>
          <div class="dev-themes">${THEMES.map((t) => {
              const m = themeMeta(t);
              return `<button type="button" data-dev-theme="${t}" class="${t === currentTheme() ? 'on' : ''}">${m.icon} ${escapeHtml(m.label)}</button>`;
          }).join('')}</div>
          <div class="dev-note">จดจำไว้เฉพาะเครื่องนี้ ไม่มีผลกับเครื่องอื่น</div>`;
    };
    draw();
    devicePanel.addEventListener('change', (e) => {
        if (e.target.matches('[data-dev-tablet]')) { setTabletMode(e.target.checked); return; }
        if (!e.target.matches('[data-dev-kb]')) return;
        setKeyboardEnabled(e.target.checked);
        markDeviceButton();
    });
    devicePanel.addEventListener('click', (e) => {
        const b = e.target.closest('[data-dev-theme]');
        if (!b) return;
        setTheme(b.dataset.devTheme);
        updateThemeButton();
        draw();
    });
    document.body.appendChild(devicePanel);
    const r = anchor.getBoundingClientRect();
    devicePanel.style.top = `${r.bottom + 8}px`;
    devicePanel.style.right = `${Math.max(8, window.innerWidth - r.right)}px`;
    document.addEventListener('pointerdown', outsideDevicePanel, true);
}

export function updateThemeButton() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const { icon, label } = themeMeta();
    btn.innerHTML = `<span>${icon}</span><span class="btn-theme-label">${escapeHtml(label)}</span>`;
    btn.title = label;
}

export function setActiveNav(routeKey) {
    const navKey = NAV_ALIAS[routeKey] || routeKey;
    let group = null;
    document.querySelectorAll('.nav-btn').forEach((btn) => {
        const on = btn.dataset.route === navKey;
        btn.classList.toggle('active', on);
        if (on) group = btn.closest('.nav-group')?.dataset.group ?? null;
    });
    // The main menu of the page shown opens (the others close); a page on its own closes them all.
    openGroup(group);
    document.querySelectorAll('#side-nav .nav-group').forEach((g) => g.classList.toggle('has-active', g.dataset.group === group));
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
    markGroupBadges();
}
