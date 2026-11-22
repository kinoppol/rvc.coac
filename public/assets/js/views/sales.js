import { api } from '../api.js';
import { money, escapeHtml, fmtDate } from '../util.js';
import { alertDialog, choiceDialog, confirmDialog, isDialogOpen } from '../dialog.js';
import { thumbDataUrl, THUMB_SIZE } from '../images.js';
import { play, soundButtonLabel, openSoundPanel } from '../sound.js';
import { openKeyboard, closeKeyboard, isKeyboardOpen, keyboardEnabled } from '../osk.js';
import { loadThaiQr, thaiQrCard, thaiQrWarning } from '../thai-qr.js';

// After a sale is saved, the next one starts by itself this soon (the done screen counts it down).
const NEXT_SALE_MS = 2000;
// How long the cashier may change a sale's payment method alone (the server allows the same, plus a little).
const PAYMENT_FIX_MS = 5 * 60 * 1000;

const SORTS = [
    ['popular', 'ขายดี'],
    ['name', 'ชื่อ ก-ฮ'],
    ['price_asc', 'ราคาต่ำ → สูง'],
    ['price_desc', 'ราคาสูง → ต่ำ'],
    ['stock_desc', 'คงเหลือมาก → น้อย'],
    ['stock_asc', 'คงเหลือน้อย → มาก'],
];
const SORT_FN = {
    popular: (a, b) => b.sold_qty - a.sold_qty,
    name: () => 0,
    price_asc: (a, b) => a.sale_price - b.sale_price,
    price_desc: (a, b) => b.sale_price - a.sale_price,
    stock_desc: (a, b) => b.qty_stock - a.qty_stock,
    stock_asc: (a, b) => a.qty_stock - b.qty_stock,
};
const GROUPS = [
    ['all', 'สินค้าทั้งหมด'],
    ['nobarcode', 'ไม่มีบาร์โค้ด'],
    ['consign', 'สินค้าฝากขาย'],
    ['promo', 'สินค้าโปรโมชั่น'],
];
const VIEWS = [
    ['grid', 'รูปภาพ'],
    ['list', 'รายการ'],
];
// Compact toolbar dropdowns: the button shows only the current choice.
const DROPDOWNS = {
    view: { label: 'รูปแบบการแสดงสินค้า', options: VIEWS, icon: (v) => (v === 'list' ? '☰' : '▦') },
    sort: { label: 'เรียงสินค้า', options: SORTS, icon: () => '⇅', prefix: 'เรียง: ' },
    group: { label: 'กลุ่มสินค้า', options: GROUPS, icon: () => '⏷' },
};
const GROUP_FN = {
    all: () => true,
    nobarcode: (p) => !(p.barcode || '').trim(),
    consign: (p) => p.consignor_id != null,
};
const METHOD_ICON = { cash: '💵', transfer: '🏦', qr: '📱', card: '💳' };
// Touch screens: focusing the search box must not pop up the system on-screen
// keyboard every time (a barcode scanner types into it like a keyboard); the page's
// own keyboard (osk.js) opens instead when the cashier taps the box or ⌨.
// Tablets with a mouse/scanner attached may report a fine pointer, so any touch support counts.
const IS_TOUCH = matchMedia('(any-pointer: coarse)').matches || navigator.maxTouchPoints > 0;

/** Placeholder tiles/rows shaped like the product list, shown while it loads. */
function skeletonHtml(view) {
    const label = '<span class="sr-only">กำลังโหลดสินค้า…</span>';
    return view === 'list'
        ? `<div class="pos-list">${label}${'<div class="pos-row pos-sk-row"><span class="sk sk-line" style="width:55%"></span><span class="sk sk-line" style="width:60px"></span><span class="sk sk-line" style="width:70px;height:18px"></span></div>'.repeat(10)}</div>`
        : `<div class="pos-grid">${label}${'<div class="pos-tile pos-sk-tile"><span class="sk pos-tile-img"></span><span class="sk sk-line" style="width:90%"></span><span class="sk sk-line" style="width:60%"></span><span class="pos-tile-meta"><span class="sk sk-line" style="width:50px;height:18px"></span><span class="sk sk-line" style="width:44px"></span></span></div>'.repeat(12)}</div>`;
}

function pref(key, fallback) {
    try { return localStorage.getItem(`pos.${key}`) ?? fallback; } catch (e) { return fallback; }
}
function savePref(key, value) {
    try { localStorage.setItem(`pos.${key}`, value); } catch (e) { /* private mode */ }
}
function loadJson(key) {
    try { return JSON.parse(localStorage.getItem(key)) || []; } catch (e) { return []; }
}
function saveJson(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ }
}



export async function render(container, { user } = {}) {
    // The open cart and held carts live in this browser per user, so they survive
    // leaving the page or a reload until the sale is finished or cleared.
    const CART_KEY = `pos.cart.${user?.id ?? 0}`;
    const HELD_KEY = `pos.held.${user?.id ?? 0}`;
    let products = [];
    let cart = loadJson(CART_KEY);
    let held = loadJson(HELD_KEY);
    let view = pref('view', 'grid');
    let sort = pref('sort', 'popular');
    let group = pref('group', 'all');
    // Also on any device set to use the page's keyboard (the button beside the theme switch).
    const TOUCH = IS_TOUCH || keyboardEnabled();
    const keyboard = !TOUCH; // system keyboard allowed in the search box
    let options = { payment_methods: [{ key: 'cash', label: 'เงินสด' }], payment_mixed: false, sell_out_of_stock: false, sell_out_of_stock_always: false };
    // Admin switch: sell products whose recorded stock is below 1 (stock goes negative).
    // The user's own switch (remembered on this device); only roles the admin allows get it.
    const SHORT_KEY = `short.${user?.id ?? 0}`;
    let shortMode = pref(SHORT_KEY, '0') === '1';
    const canShort = () => !!options.sell_out_of_stock;
    // Admin may also turn it on for everyone, with no switch on this page.
    const allowShort = () => !!options.sell_out_of_stock_always || (canShort() && shortMode);
    // A discontinued product reaches the cart only by its exact barcode, and holds no stock on record.
    const mayShort = (p) => allowShort() || !!p.discontinued;
    let modal = null; // the payment dialog element while open
    // Cash drawer of the current accounting day: counted before selling, or skipped for a few minutes.
    let drawer = null; // state from /sales/options and /drawer/*
    let skipEnds = 0; // Date.now() when this cashier's skip runs out
    let drawerModal = null; // the opening-count dialog while open
    let msgTimer;
    // The sale just made: its change stays on screen, and its payment method can be put right
    // for a few minutes (a wrong button) without an approval. { id, doc_no, total, change, methods, until }
    let last = null;
    let lastTimer = null;
    // Products matching the search that cannot be sold (stock below 1, switch off).
    let outHints = { term: '', items: [] };
    let outTimer;
    // Search box: a barcode scanner types a burst of keys; the product list redraws only after
    // a short pause so a fast scan is never slowed (or keys lost) by redrawing on every key.
    let drawTimer;
    let lastKeyAt = 0;
    let burst = 0;
    // Digits alone are most likely a barcode, which names one product or none, so the list isn't
    // filtered until there are 13 of them, a letter joins in, or the cashier asks to search.
    let searched = ''; // the digits the cashier asked to search (Enter / the search button)
    let drawnTerm = ''; // the term the product list on screen was filtered by
    let firstLoad = null; // the opening product load; a scan that comes before it waits for it
    let loaded = false;
    // Bundle promotions (โปรโมชั่นจับคู่สินค้า): the server works out the cart's discount as it changes.
    // key = the sale lines it was worked out for; a cart that no longer matches waits for a new quote.
    let promo = { key: '[]', total: 0, lines: {}, applied: [] };
    let promoTimer;
    let promoAsk = null; // the quote in flight: { key, done: Promise }
    // Promotions running now (GET /sales/promos): which goods are on one, and what a cart still lacks.
    let promos = [];
    // A POS item's key in a promotion slot: a sale group (id = -group id) or a product.
    const itemKey = (p) => (p.id < 0 ? `g${-p.id}` : `p${p.id}`);
    const promosOf = (p) => promos.filter((r) => r.slots.some((s) => s.keys.includes(itemKey(p))));
    const promoName = (id) => promos.find((r) => r.id === Number(id))?.name || promo.applied.find((a) => a.promo_id === Number(id))?.name || 'โปรโมชั่น';
    // The buyer (member card from /sales/members); null = ลูกค้าทั่วไป, no points. Kept with the open cart.
    // A cart line that uses points carries line.hold = {id, points, discount, name}: points held on the server.
    const MEMBER_KEY = `pos.member.${user?.id ?? 0}`;
    let member = (() => { try { return JSON.parse(localStorage.getItem(MEMBER_KEY)); } catch (e) { return null; } })();
    let redeems = []; // GET /sales/redeems: goods points can come off now
    const redeemOf = (p) => (p.pack ? null : redeems.find((r) => r.keys.includes(itemKey(p))));

    const life = new AbortController();
    const on = (target, type, fn, capture = false) => target.addEventListener(type, fn, { signal: life.signal, capture });

    container.innerHTML = `
      <div class="pos">
        <div class="pos-bar card">
          <label class="pos-search">
            <span aria-hidden="true">🔍</span>
            <input data-el="q" data-osk="manual" placeholder="สแกนบาร์โค้ด หรือพิมพ์ชื่อสินค้า" autocomplete="off" enterkeyhint="search" spellcheck="false">
            <button type="button" class="pos-search-go" data-act="search-q" hidden>ค้นหา</button>
            <button type="button" class="pos-icon-btn" data-act="clear-q" aria-label="ล้างคำค้น" hidden>✕</button>
          </label>
          ${TOUCH ? '<button type="button" class="pos-tool" data-act="kb" aria-label="แป้นพิมพ์บนจอ" title="แป้นพิมพ์บนจอ">⌨</button>' : ''}
          ${['group', 'view', 'sort'].map((k) => `
            <div class="pos-dd" data-dd="${k}">
              <button type="button" class="pos-dd-btn" data-dd-toggle="${k}" aria-haspopup="true" aria-expanded="false" aria-label="${DROPDOWNS[k].label}" title="${DROPDOWNS[k].label}"></button>
              <div class="pos-dd-menu" role="menu" hidden>
                ${DROPDOWNS[k].options.map(([v, l]) => `<button type="button" role="menuitemradio" data-dd-pick="${k}" data-value="${v}"><span class="pos-dd-ico">${DROPDOWNS[k].icon(v)}</span>${DROPDOWNS[k].prefix || ''}${l}</button>`).join('')}
              </div>
            </div>`).join('')}
          <button type="button" class="pos-tool snd-btn" data-act="sound" aria-label="ระดับเสียง" title="ระดับเสียง">${soundButtonLabel()}</button>
          <button type="button" class="pos-tool" data-act="full" aria-label="เต็มหน้าจอ" title="เต็มหน้าจอ">⛶</button>
        </div>
        <div data-el="hours"></div>
        <div data-el="promobar"></div>
        <div data-el="drawerbar"></div>
        <div data-el="shortbar"></div>
        <div data-el="msg"></div>
        <div class="pos-seek" data-el="seek" role="status" aria-live="polite">
          <div class="pos-seek-card">
            <div class="pos-seek-scan" aria-hidden="true"><i></i></div>
            <div class="pos-seek-title">กำลังค้นหาสินค้า<span class="pos-seek-dots" aria-hidden="true"><i>.</i><i>.</i><i>.</i></span></div>
            <div class="pos-seek-term" data-el="seek-term"></div>
            <div class="pos-seek-bar" aria-hidden="true"><i></i></div>
          </div>
        </div>
        <div class="pos-main">
          <section class="pos-products card" data-el="products" aria-busy="true">${skeletonHtml(view)}</section>
          <aside class="pos-cart card">
            <div class="pos-cart-head"><h2 data-el="cart-title">ตะกร้าสินค้า</h2>
              <div class="pos-cart-tools">
                <button type="button" class="btn secondary sm" data-act="member" title="ระบุสมาชิกผู้ซื้อ (เลขบัตรประชาชน รหัสนักศึกษา เบอร์โทร หรือชื่อ)">👤 ระบุสมาชิก</button>
                <button type="button" class="btn secondary sm" data-act="hold" title="พักตะกร้านี้ไว้ แล้วเปิดรายการขายใหม่">⏸ พักตะกร้า</button>
                <button type="button" class="btn secondary sm" data-act="clear-cart">ล้างตะกร้า</button>
              </div>
            </div>
            <div class="pos-member" data-el="member"></div>
            <div class="pos-held" data-el="held" hidden></div>
            <div class="pos-cart-list" data-el="cart"></div>
            <div class="pos-last" data-el="last" hidden></div>
            <div class="pos-cart-foot">
              <div class="pos-promos" data-el="promos" hidden></div>
              <div class="pos-total"><span>ยอดรวม <small data-el="cart-qty"></small></span><b data-el="total">฿ 0.00</b></div>
              <button type="button" class="btn pos-pay" data-act="pay">ชำระเงิน</button>
            </div>
          </aside>
        </div>
      </div>`;

    const root = container.querySelector('.pos');
    const el = (name) => root.querySelector(`[data-el="${name}"]`);
    const q = el('q');

    function setKeyboard() {
        q.inputMode = keyboard ? 'text' : 'none';
        root.querySelector('[data-act="kb"]')?.classList.toggle('active', isKeyboardOpen());
    }

    const showKeyboard = () => { if (TOUCH && !isKeyboardOpen()) openKeyboard(q, { onClose: setKeyboard }); setKeyboard(); };

    /** Puts the cursor back in the search box unless the cashier is typing somewhere else. */
    function focusSearch() {
        if (modal || drawerModal || isDialogOpen() || !root.isConnected) return;
        const a = document.activeElement;
        if (a && a !== q && root.contains(a) && a.matches('input, select, textarea')) return;
        if (a === q || !TOUCH || keyboard) { q.focus({ preventScroll: true }); return; }
        // Browsers that ignore inputmode="none" still keep the keyboard down for a read-only field.
        q.readOnly = true;
        q.focus({ preventScroll: true });
        setTimeout(() => { q.readOnly = false; }, 60);
    }

    // Covers the screen while a search is under way, so the cashier sees the scan arrived and waits.
    function seeking(term) {
        const box = el('seek');
        if (term == null) { box.classList.remove('on'); return; }
        el('seek-term').textContent = term;
        el('seek-term').hidden = !term;
        box.classList.add('on');
    }

    function message(text, type = 'ok') {
        clearTimeout(msgTimer);
        el('msg').innerHTML = text ? `<div class="pos-msg ${type}">${escapeHtml(text)}</div>` : '';
        if (text && type === 'ok') msgTimer = setTimeout(() => message(''), 4000);
    }

    const PAY_LABEL = (k) => options.payment_methods.find((m) => m.key === k)?.label || { cash: 'เงินสด', transfer: 'โอนเงิน', qr: 'สแกน QR-Code', card: 'บัตรเครดิต' }[k] || k;
    const mmss = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

    /** The last sale's strip under the cart: what it was paid with, its change, and the fix button while it may be used. */
    function drawLast() {
        const box = el('last');
        clearInterval(lastTimer);
        if (!last) { box.hidden = true; box.innerHTML = ''; return; }
        const left = () => Math.max(0, Math.floor((last.until - Date.now()) / 1000));
        box.hidden = false;
        box.innerHTML = `
          <div class="pos-last-main">
            <div><b>ขายล่าสุด</b> ฿${money(last.total)} · ${escapeHtml(last.methods.map(PAY_LABEL).join(' + '))}${last.fixed ? ' <span class="pill warn">แก้วิธีชำระแล้ว</span>' : ''}</div>
            <div class="pos-last-doc">${escapeHtml(last.doc_no)}${last.change > 0 ? ` · <span class="pos-last-change">เงินทอน ฿${money(last.change)}</span>` : ''}</div>
          </div>
          ${left() > 0 ? `<button type="button" class="btn secondary sm" data-fix-pay title="เลือกวิธีชำระผิด แก้ได้ภายใน 5 นาทีหลังขาย ไม่ต้องรออนุมัติ">แก้วิธีชำระ (<span data-left>${mmss(left())}</span>)</button>` : ''}`;
        if (left() > 0) {
            lastTimer = setInterval(() => {
                const s = left();
                const span = box.querySelector('[data-left]');
                if (!s || !span) { drawLast(); return; }
                span.textContent = mmss(s);
            }, 1000);
        }
    }

    async function fixLastPayment() {
        if (!last || modal) return;
        const sale = last;
        const choices = options.payment_methods
            .filter((m) => !(sale.methods.length === 1 && sale.methods[0] === m.key))
            .map((m) => ({ key: m.key, label: `${m.label} ฿${money(sale.total)}` }));
        if (!choices.length) return;
        const method = await choiceDialog(
            `${sale.doc_no} · ยอดขาย ฿${money(sale.total)}\nเดิม: ${sale.methods.map(PAY_LABEL).join(' + ')}\nเลือกวิธีที่ลูกค้าชำระจริง (ทั้งยอด)`,
            choices,
            { title: 'แก้วิธีชำระเงิน' },
        );
        if (!method) { focusSearch(); return; }
        try {
            const res = await api.post(`/sales/${sale.id}/payment-fix`, { method });
            if (last === sale) {
                last = { ...sale, methods: (res.data.payments || []).map((p) => p.method), change: Number(res.data.change_amount) || 0, fixed: true };
                drawLast();
            }
            message(`แก้วิธีชำระ ${sale.doc_no} เป็น${PAY_LABEL(method)}แล้ว`);
            refreshDrawer();
        } catch (e) {
            await alertDialog(e.message, { title: 'แก้วิธีชำระไม่ได้', tone: 'error' });
            if (e.status === 422 && last === sale && /เกิน/.test(e.message)) { last = { ...sale, until: 0 }; drawLast(); }
        }
        focusSearch();
    }

    function photoUrl(p) {
        // A sale group (id < 0) shows one member's photo.
        return p.image_token ? api.downloadUrl(`/products/${p.photo_id ?? p.id}/photo?size=thumb&v=${p.image_token}`) : null;
    }

    /** <img> for a product photo; a photo without a server thumbnail is marked so its first load makes one. */
    function photoImg(p) {
        const url = photoUrl(p);
        return url ? `<img src="${escapeHtml(url)}" alt="" loading="lazy" decoding="async"${p.has_thumb ? '' : ` data-thumbless="${p.id}"`}>` : '<span class="pos-nopic">no pic</span>';
    }

    // The server could not shrink these photos (no GD), so it sent the original:
    // shrink it here once and upload the copy, and every device gets the small file next time.
    const thumbing = new Set();
    async function uploadThumb(img) {
        const p = products.find((x) => x.id === Number(img.dataset.thumbless));
        if (!p || p.has_thumb || thumbing.has(p.id)) return;
        p.has_thumb = true;
        if (Math.max(img.naturalWidth, img.naturalHeight) <= THUMB_SIZE) return; // already a thumbnail
        thumbing.add(p.id);
        const dataUrl = await thumbDataUrl(img);
        if (dataUrl) await api.post(`/products/${p.id}/photo/thumb`, { data_url: dataUrl }).catch(() => {});
        thumbing.delete(p.id);
    }

    /** Digits still waiting: fewer than 13 and not yet searched for. */
    function digitsHeld() {
        const v = q.value.trim();
        return /^\d{1,12}$/.test(v) && v !== searched;
    }

    /** What the product list filters by; digits still waiting leave it unfiltered. */
    function listTerm() {
        return digitsHeld() ? '' : q.value.trim().toLowerCase();
    }

    function drawSearchButtons() {
        root.querySelector('[data-act="clear-q"]').hidden = !q.value;
        root.querySelector('[data-act="search-q"]').hidden = !digitsHeld();
    }

    function drawProductsIfTermChanged() {
        if (listTerm() !== drawnTerm) drawProducts();
    }

    function matches() {
        const term = listTerm();
        const inGroup = group === 'promo' ? (p) => promosOf(p).length > 0 : (GROUP_FN[group] || GROUP_FN.all);
        const list = products.filter((p) => inGroup(p)
            && (!term || p.name.toLowerCase().includes(term) || (p.barcode || '').includes(term)));
        const fn = SORT_FN[sort] || SORT_FN.name;
        // Out-of-stock products (shown only when the admin allows selling them) go last.
        return list.sort((a, b) => (a.qty_stock < 1) - (b.qty_stock < 1) || fn(a, b) || a.name.localeCompare(b.name, 'th'));
    }

    function drawDropdowns() {
        const current = { view, sort, group };
        for (const [k, dd] of Object.entries(DROPDOWNS)) {
            const box = root.querySelector(`[data-dd="${k}"]`);
            const label = (dd.options.find(([v]) => v === current[k]) || dd.options[0])[1];
            box.querySelector('.pos-dd-btn').innerHTML = `<span class="pos-dd-ico">${dd.icon(current[k])}</span><span class="pos-dd-label">${escapeHtml(label)}</span><span class="pos-dd-caret">▾</span>`;
            // Filtering hides products: show it even when the labels are hidden on small screens.
            box.classList.toggle('on', k === 'group' && group !== 'all');
            box.querySelectorAll('[data-dd-pick]').forEach((b) => {
                b.classList.toggle('active', b.dataset.value === current[k]);
                b.setAttribute('aria-checked', String(b.dataset.value === current[k]));
            });
        }
    }

    function toggleDropdown(k) {
        root.querySelectorAll('[data-dd]').forEach((box) => {
            const open = box.dataset.dd === k && box.querySelector('.pos-dd-menu').hidden;
            const menu = box.querySelector('.pos-dd-menu');
            menu.hidden = !open;
            box.querySelector('.pos-dd-btn').setAttribute('aria-expanded', String(open));
            box.classList.toggle('open', open);
            if (open) {
                // Right-aligned under its button, unless that pushes it off the left edge of a small screen.
                menu.style.right = '0';
                menu.style.left = 'auto';
                if (menu.getBoundingClientRect().left < 8) {
                    menu.style.right = 'auto';
                    menu.style.left = `${8 - box.getBoundingClientRect().left}px`;
                }
            }
        });
    }

    function pickDropdown(k, value) {
        if (k === 'view') view = value;
        else if (k === 'sort') sort = value;
        else group = value;
        savePref(k, value);
        toggleDropdown(null);
        drawProducts();
    }

    /** Pieces one cart line takes from stock (a whole pack is pack_size pieces) and its price per line unit. */
    const lineSize = (it) => it.pack?.pack_size || 1;
    const linePrice = (it) => (it.pack ? it.pack.sale_price : it.sale_price);

    /** Pieces of this product already in the cart, in every line (pieces and whole packs). */
    function inCart(id) {
        return cart.filter((c) => c.id === id).reduce((s, c) => s + c.qty * lineSize(c), 0);
    }

    /** A cart line rebuilt from fresh product data; a pack no longer sold whole falls back to pieces. */
    function freshLine(c, p) {
        if (!c.pack) return { ...p, qty: c.qty };
        const pack = (p.packs || []).find((k) => k.pack_size === c.pack.pack_size);
        return pack ? { ...p, qty: c.qty, pack } : { ...p, qty: c.qty * c.pack.pack_size };
    }

    function drawProducts() {
        drawDropdowns();
        drawSearchButtons();
        drawnTerm = listTerm();
        const list = matches();
        const box = el('products');
        box.removeAttribute('aria-busy');
        if (!list.length) {
            const term = drawnTerm ? q.value.trim() : '';
            box.innerHTML = `<div class="empty">${term ? 'ไม่พบสินค้าที่ค้นหา' : group === 'promo' ? 'ไม่มีสินค้าที่ร่วมโปรโมชั่นตอนนี้' : group !== 'all' ? 'ไม่มีสินค้าในกลุ่มนี้' : 'ไม่มีสินค้าคงเหลือ'}</div>`;
            if (term && !allowShort()) {
                if (outHints.term !== term) {
                    clearTimeout(outTimer);
                    outTimer = setTimeout(async () => {
                        const res = await api.get(`/sales/products?out=1&q=${encodeURIComponent(term)}`).catch(() => ({ data: [] }));
                        outHints = { term, items: res.data };
                        if (q.value.trim() === term) drawProducts();
                    }, 250);
                } else if (outHints.items.length) {
                    box.innerHTML = `
                      <div class="pos-out-note">ไม่มีสินค้าที่ขายได้ตรงกับคำค้น — พบสินค้าที่คงเหลือในระบบต่ำกว่า 1
                        ${canShort()
                            ? `<label class="pos-switch"><input type="checkbox" data-out-switch><span aria-hidden="true"></span> เปิดขายสินค้าที่มีจำนวนคงเหลือต่ำกว่า 1</label>
                               <small>สต๊อกของสินค้าที่ขายจะติดลบ ควรบันทึกรับสินค้า/ตรวจนับให้ถูกต้องภายหลัง</small>`
                            : '<small>บทบาทของคุณยังไม่ได้รับสิทธิ์ขายสินค้าที่คงเหลือต่ำกว่า 1 แจ้งผู้ดูแลระบบ (ตั้งค่า &gt; หน้าขายสินค้า (POS)) หรือบันทึกรับสินค้า/ตรวจนับให้สต๊อกถูกต้อง</small>'}</div>
                      <div class="pos-list">${outHints.items.map((p) => `
                        <div class="pos-row out" aria-disabled="true">
                          <span class="pos-row-name">${escapeHtml(p.name)}<small>${escapeHtml(p.consignor_name)}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}</small></span>
                          <span class="pos-row-stock">คงเหลือ ${Number(p.qty_stock)}</span>
                          <b>฿${money(p.sale_price)}</b>
                        </div>`).join('')}</div>`;
                }
            }
            return;
        }
        // Products sold whole by the pack get a "ยกแพ็ค" button beside them (one per pack size).
        const packBtns = (p) => {
            const sizes = [...new Map((p.packs || []).map((k) => [k.pack_size, k])).values()];
            return `<div class="pos-pack-btns">${sizes.map((k) => {
                const save = p.sale_price * k.pack_size - k.sale_price;
                return `<button type="button" class="pos-pack-btn" data-pick-pack="${p.id}" data-size="${k.pack_size}" title="ซื้อยกแพ็ค ${k.pack_size} ชิ้น">
                  📦 ยกแพ็ค ×${k.pack_size} <b>฿${money(k.sale_price)}</b>${save >= 0.005 ? `<small>ลด ฿${money(save)}</small>` : ''}</button>`;
            }).join('')}</div>`;
        };
        const withPacks = (p, html) => (p.packs?.length ? `<div class="pos-item">${html}${packBtns(p)}</div>` : html);
        box.innerHTML = view === 'grid'
            ? `<div class="pos-grid">${list.map((p) => {
                const n = inCart(p.id);
                return withPacks(p, `<button type="button" class="pos-tile${n ? ' picked' : ''}${p.qty_stock < 1 ? ' out' : ''}" data-pick="${p.id}">
                  <span class="pos-tile-img">${photoImg(p)}</span>
                  ${promoTag(p)}
                  <span class="pos-tile-name">${escapeHtml(p.name)}</span>
                  ${p.consignor_id != null ? `<span class="pos-tile-consignor">ผู้ฝาก: ${escapeHtml(p.consignor_name)}</span>` : ''}
                  <span class="pos-tile-meta"><b>฿${money(p.sale_price)}</b><span>เหลือ ${p.qty_stock - n}</span></span>
                  ${n ? `<span class="pos-tile-badge">${n}</span>` : ''}
                </button>`);
            }).join('')}</div>`
            : `<div class="pos-list">${list.map((p) => {
                const n = inCart(p.id);
                return withPacks(p, `<button type="button" class="pos-row${n ? ' picked' : ''}${p.qty_stock < 1 ? ' out' : ''}" data-pick="${p.id}">
                  <span class="pos-row-name">${escapeHtml(p.name)}${promoTag(p, true)}<small>${escapeHtml(p.consignor_name)}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}</small></span>
                  <span class="pos-row-stock">เหลือ ${p.qty_stock - n}</span>
                  <b>฿${money(p.sale_price)}</b>
                  ${n ? `<span class="pos-tile-badge">${n}</span>` : ''}
                </button>`);
            }).join('')}</div>`;
    }

    /** The cart as POST /sales takes it; a sale group (id = -group id) is sold as one item. */
    function saleItems() {
        return cart.map((i) => (i.id < 0 ? { group_id: -i.id, qty: i.qty }
            : { product_id: i.id, qty: i.qty * lineSize(i), ...(i.pack ? { pack_size: i.pack.pack_size } : {}) }));
    }

    /** The "โปร" tag on a product tile / row: the promotions it is part of. */
    function promoTag(p, inline = false) {
        const list = promosOf(p);
        if (!list.length) return '';
        const names = list.map((r) => r.name).join(' · ');
        return `<span class="pos-promo-tag${inline ? ' inline' : ''}" title="ร่วมโปรโมชั่น: ${escapeHtml(names)}">🏷 ${escapeHtml(list.length > 1 ? `โปร ${list.length} รายการ` : list[0].name)}</span>`;
    }

    /** The promotions running now, above the products; tapping it lists the goods on promotion. */
    function drawPromoBar() {
        const box = el('promobar');
        if (!promos.length) { box.innerHTML = ''; return; }
        box.innerHTML = `
          <div class="pos-promobar">
            <span class="pos-promobar-title">🏷 โปรโมชั่นตอนนี้</span>
            <span class="pos-promobar-list">${promos.map((r) => `<span class="pos-promobar-chip" title="${escapeHtml(r.slots.map((s) => `${s.qty} ชิ้นจาก ${s.names.join(', ')}`).join(' + '))}${r.ends_on ? ` · ถึง ${escapeHtml(r.ends_on.slice(0, 16))}` : ''}">${escapeHtml(r.name)}</span>`).join('')}</span>
            <button type="button" class="btn secondary sm" data-act="promo-filter">${group === 'promo' ? 'ดูสินค้าทั้งหมด' : 'ดูสินค้าที่ร่วมโปร'}</button>
          </div>`;
    }

    /**
     * Promotions the cart has started but not filled: what is still missing for one set
     * (e.g. "เพิ่ม กาโต้ 1 ชิ้น"). Promotions already given are left out.
     */
    function promoHints(applied) {
        const got = new Set(applied.map((a) => a.promo_id));
        const pieces = {};
        cart.forEach((c) => { if (!c.pack) pieces[itemKey(c)] = (pieces[itemKey(c)] || 0) + c.qty; });
        const out = [];
        for (const r of promos) {
            if (got.has(r.id)) continue;
            let started = false;
            const missing = [];
            for (const s of r.slots) {
                const have = s.keys.reduce((n, k) => n + (pieces[k] || 0), 0);
                if (have > 0) started = true;
                if (have < s.qty) {
                    const what = s.label || (s.names.length > 2 ? `${s.names.slice(0, 2).join(' / ')} ฯลฯ` : s.names.join(' / '));
                    missing.push(`${what} ${s.qty - have} ชิ้น`);
                }
            }
            if (started && missing.length) out.push({ name: r.name, missing });
        }
        return out;
    }

    function cartSubtotal() {
        return Math.round(cart.reduce((s, i) => s + linePrice(i) * i.qty, 0) * 100) / 100;
    }

    /** The bundle discount worked out for this very cart (0 while a new quote is on its way). */
    function promoNow() {
        return promo.key === JSON.stringify(saleItems()) ? promo : { total: 0, lines: {}, applied: [] };
    }

    function pointsOff() {
        return Math.round(cart.reduce((s, c) => s + (c.hold ? c.hold.discount : 0), 0) * 100) / 100;
    }

    function cartTotal() {
        return Math.max(0, Math.round((cartSubtotal() - promoNow().total - pointsOff()) * 100) / 100);
    }

    // ---------- Members and points ----------

    /** A 13-digit Thai national ID (check digit right) or a 10-digit mobile number: worth asking the member list. */
    function memberRef(code) {
        if (/^0\d{9}$/.test(code)) return true;
        if (!/^\d{13}$/.test(code)) return false;
        let sum = 0;
        for (let i = 0; i < 12; i += 1) sum += Number(code[i]) * (13 - i);
        return (11 - (sum % 11)) % 10 === Number(code[12]);
    }

    function saveMember() {
        try {
            if (member) localStorage.setItem(MEMBER_KEY, JSON.stringify(member));
            else localStorage.removeItem(MEMBER_KEY);
        } catch (e) { /* private mode */ }
    }

    /** Gives the points held by these lines back (fire and forget; an expired hold is gone anyway). */
    function releaseHolds(lines) {
        const ids = lines.filter((c) => c?.hold).map((c) => c.hold.id);
        lines.forEach((c) => { if (c) delete c.hold; });
        if (!ids.length || !member) return;
        const id = member.member_id;
        api.post('/sales/points/release', { member_id: id, hold_ids: ids }).then((r) => {
            if (member?.member_id === id) { member = r.data; saveMember(); drawMember(); }
        }).catch(() => {});
    }

    function setMember(card) {
        if (member && card?.member_id !== member.member_id) releaseHolds(cart);
        member = card;
        saveMember();
        play('scan');
        message(card ? `ผู้ซื้อ: ${card.name} · แต้มใช้ได้ ${card.available} แต้ม` : 'เปลี่ยนเป็นลูกค้าทั่วไป (ไม่สะสมแต้ม)');
        drawCart();
    }

    function drawMember() {
        const box = el('member');
        root.querySelector('.pos-cart-tools [data-act="member"]').hidden = !options.members;
        if (!options.members) { box.innerHTML = ''; return; }
        if (!member) {
            box.innerHTML = `<div class="pos-member-none"><span>👥 ลูกค้าทั่วไป <small>(ไม่สะสมแต้ม)</small></span>
              <button type="button" class="link-btn" data-act="member">ระบุสมาชิก</button></div>`;
            return;
        }
        const earn = member.earning ? Math.floor(cartTotal() / member.baht_per_point + 1e-9) : 0;
        box.innerHTML = `<div class="pos-member-card">
            <div class="pos-member-info"><b>👤 ${escapeHtml(member.name)}</b>
              <small>${escapeHtml(member.type_label)}${member.student_code ? ` · ${escapeHtml(member.student_code)}` : ''}${member.department ? ` · ${escapeHtml(member.department)}` : ''}</small></div>
            <div class="pos-member-pts"><b>${member.available}</b><small>แต้มใช้ได้${member.held ? ` (จอง ${member.held})` : ''}</small></div>
            <div class="pos-member-tools">
              ${member.surveys?.length ? `<button type="button" class="btn secondary sm" data-act="survey">📝 แบบสอบถาม +${member.surveys.reduce((s, x) => s + x.points, 0)}</button>` : ''}
              <button type="button" class="btn secondary sm" data-act="member">เปลี่ยน</button>
              <button type="button" class="pos-icon-btn" data-act="member-off" aria-label="ไม่ระบุสมาชิก" title="ไม่ระบุสมาชิก (ลูกค้าทั่วไป)">✕</button>
            </div>
            ${cart.length && member.earning ? `<div class="pos-member-earn">บิลนี้ได้ <b>${earn}</b> แต้ม${member.first_purchase ? ` + ซื้อครั้งแรก <b>${member.first_purchase}</b> แต้ม` : ''}${pointsOff() ? ` · ใช้แต้มลด <b>฿${money(pointsOff())}</b>` : ''}</div>` : ''}
          </div>`;
    }

    /** "ระบุสมาชิก": find the buyer by national ID, student code, phone or name. */
    function openMemberSearch(prefill = '') {
        closeKeyboard();
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        root.appendChild(box);
        modal = box;
        let people = [];
        let note = '';
        let busy = false;
        const close = () => { box.remove(); if (modal === box) modal = null; focusSearch(); };
        box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:560px">
          <div class="modal-head"><h2>ระบุสมาชิกผู้ซื้อ</h2><button type="button" class="modal-close" data-m="close" aria-label="ปิด">✕</button></div>
          <div class="modal-body">
            <div class="pos-search" style="margin-bottom:8px"><span aria-hidden="true">🔍</span>
              <input data-m="q" placeholder="เลขบัตรประชาชน รหัสนักศึกษา เบอร์โทร หรือชื่อ" autocomplete="off" inputmode="search" value="${escapeHtml(prefill)}">
              <button type="button" class="pos-search-go" data-m="go">ค้นหา</button></div>
            <div data-m="list"></div>
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-m="close">ยกเลิก</button>
            ${member ? '<button type="button" class="btn secondary" data-m="none">ลูกค้าทั่วไป (ไม่ระบุ)</button>' : ''}</div></div>`;
        const input = box.querySelector('[data-m="q"]');
        const drawList = () => {
            box.querySelector('[data-m="list"]').innerHTML = busy ? '<div class="muted">กำลังค้นหา...</div>'
                : people.length ? `<div class="pos-list">${people.map((p) => `<button type="button" class="pos-row" data-oa="${p.oa_user_id}">
                    <span class="pos-row-name">${escapeHtml(p.name)}<small>${escapeHtml(p.type_label)}${p.student_code ? ` · ${escapeHtml(p.student_code)}` : ''}${p.department ? ` · ${escapeHtml(p.department)}` : ''}</small></span>
                    <span class="muted" style="font-size:12px">${escapeHtml(p.national_id_masked || '')}</span></button>`).join('')}</div>`
                : `<div class="muted">${escapeHtml(note || 'พิมพ์ข้อมูลอ้างอิง แล้วกด Enter · สแกนบัตรหรือพิมพ์เลข 13 หลัก/เบอร์โทร 10 หลักที่ช่องค้นหาสินค้าก็ได้')}</div>`;
        };
        const pick = async (oa) => {
            try {
                setMember((await api.get(`/sales/members/${oa}`)).data);
                close();
            } catch (e) {
                note = e.message;
                people = [];
                drawList();
            }
        };
        const search = async () => {
            const term = input.value.trim();
            if (!term) return;
            busy = true;
            drawList();
            try {
                const r = (await api.get(`/sales/members?q=${encodeURIComponent(term)}`)).data;
                busy = false;
                if (r.card) { setMember(r.card); close(); return; }
                people = r.people;
                note = people.length ? '' : `ไม่พบสมาชิก "${term}"`;
                if (people.length === 1 && people[0].matched_by !== 'name') return pick(people[0].oa_user_id);
            } catch (e) {
                busy = false;
                people = [];
                note = e.message;
            }
            drawList();
        };
        box.addEventListener('click', (e) => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.m === 'close') close();
            else if (t.dataset.m === 'go') search();
            else if (t.dataset.m === 'none') { setMember(null); close(); }
            else if (t.dataset.oa) pick(Number(t.dataset.oa));
        });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'Enter' && e.target === input) { e.preventDefault(); search(); }
        });
        drawList();
        input.focus();
        if (prefill) search();
    }

    /** "ใช้แต้ม" on a cart line: holds the points on the server at once, or gives them back. */
    async function togglePoints(i) {
        const line = cart[i];
        if (!line || !member) return;
        if (line.hold) {
            releaseHolds([line]);
            message(`ยกเลิกการใช้แต้มของ ${line.name} แล้ว`);
            drawCart();
            return;
        }
        await quotePromo();
        const idx = cart.indexOf(line);
        if (idx < 0) return;
        const promoLine = promoNow().lines[idx];
        try {
            const r = (await api.post('/sales/points/hold', {
                member_id: member.member_id, qty: line.qty, ...(line.id < 0 ? { group_id: -line.id } : { product_id: line.id, unit_price: line.sale_price }),
                line_value: Math.round((linePrice(line) * line.qty - (promoLine?.discount || 0)) * 100) / 100,
            })).data;
            if (!cart.includes(line) || member?.member_id !== r.member.member_id) {
                api.post('/sales/points/release', { member_id: r.member.member_id, hold_ids: [r.hold_id] }).catch(() => {});
                return;
            }
            line.hold = { id: r.hold_id, points: r.points, discount: r.discount, name: r.redeem_name };
            member = r.member;
            saveMember();
            play('pack');
            message(`ใช้ ${r.points} แต้ม ลด ${line.name} ฿${money(r.discount)} · แต้มคงเหลือใช้ได้ ${member.available}`);
        } catch (e) {
            play('error');
            message(e.message, 'error');
        }
        drawCart();
    }

    /** แบบสอบถาม: the member answers on this screen and gets the points at once. */
    function openSurvey() {
        const list = member?.surveys || [];
        if (!list.length) return;
        closeKeyboard();
        let s = list.length === 1 ? list[0] : null;
        let answers = [];
        let error = null;
        let busy = false;
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        root.appendChild(box);
        modal = box;
        const close = () => { box.remove(); if (modal === box) modal = null; focusSearch(); };
        const draw = () => {
            box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:640px">
              <div class="modal-head"><h2>${s ? escapeHtml(s.title) : 'แบบสอบถามรับแต้ม'}</h2><button type="button" class="modal-close" data-s="close" aria-label="ปิด">✕</button></div>
              <div class="modal-body">${!s ? list.map((x) => `<button type="button" class="pos-row" data-pick-survey="${x.id}" style="width:100%">
                  <span class="pos-row-name">${escapeHtml(x.title)}<small>${x.questions.length} ข้อ</small></span><b>+${x.points} แต้ม</b></button>`).join('')
                : `<div class="muted" style="margin-bottom:8px">${escapeHtml(member.name)} · ตอบครบรับ <b>${s.points} แต้ม</b>${s.description ? ` · ${escapeHtml(s.description)}` : ''}</div>
                  ${s.questions.map((q, i) => `<div class="pos-survey-q"><b>${i + 1}. ${escapeHtml(q.label)}${q.required ? ' <span style="color:var(--bad)">*</span>' : ''}</b>
                    <div class="pos-survey-opts">${q.kind === 'rating' ? [1, 2, 3, 4, 5].map((n) => `<button type="button" class="pos-method${answers[i] === n ? ' active' : ''}" data-q="${i}" data-v="${n}">${'★'.repeat(n)}</button>`).join('')
                        : q.kind === 'text' ? `<textarea rows="2" data-qt="${i}" style="width:100%">${escapeHtml(answers[i] || '')}</textarea>`
                        : q.options.map((o) => `<button type="button" class="pos-method${(q.kind === 'multi' ? (answers[i] || []).includes(o) : answers[i] === o) ? ' active' : ''}" data-q="${i}" data-v="${escapeHtml(o)}">${escapeHtml(o)}</button>`).join('')}</div></div>`).join('')}
                  ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}`}</div>
              <div class="modal-foot"><button type="button" class="btn secondary pos-big" data-s="close">ปิด</button>
                ${s ? `<button type="button" class="btn pos-big" data-s="send" ${busy ? 'disabled' : ''}>${busy ? 'กำลังส่ง...' : `ส่งคำตอบ รับ ${s.points} แต้ม`}</button>` : ''}</div></div>`;
        };
        box.addEventListener('input', (e) => { if (e.target.dataset.qt !== undefined) answers[Number(e.target.dataset.qt)] = e.target.value; });
        box.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); close(); } });
        box.addEventListener('click', async (e) => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.s === 'close') return close();
            if (t.dataset.pickSurvey) { s = list.find((x) => x.id === Number(t.dataset.pickSurvey)); answers = []; draw(); return; }
            if (t.dataset.q !== undefined) {
                const i = Number(t.dataset.q);
                const q = s.questions[i];
                const v = q.kind === 'rating' ? Number(t.dataset.v) : t.dataset.v;
                if (q.kind === 'multi') {
                    const a = answers[i] || [];
                    answers[i] = a.includes(v) ? a.filter((x) => x !== v) : [...a, v];
                } else answers[i] = v;
                error = null;
                draw();
                return;
            }
            if (t.dataset.s !== 'send' || busy) return;
            busy = true;
            error = null;
            draw();
            try {
                const r = (await api.post(`/sales/surveys/${s.id}/answer`, { member_id: member.member_id, answers: s.questions.map((q, i) => answers[i] ?? null) })).data;
                member = r.member;
                saveMember();
                play('pay');
                message(`ขอบคุณที่ตอบแบบสอบถาม ได้รับ ${r.points} แต้ม · แต้มสะสม ${r.balance} แต้ม`);
                drawCart();
                close();
            } catch (err) {
                busy = false;
                error = err.message;
                draw();
            }
        });
        draw();
    }

    /** Asks the server for the cart's bundle discount; resolves once the quote for the current cart is in. */
    function quotePromo() {
        const items = saleItems();
        const key = JSON.stringify(items);
        if (promo.key === key) return Promise.resolve();
        if (promoAsk?.key === key) return promoAsk.done;
        const done = (items.length ? api.post('/sales/promo-quote', { items }).then((r) => r.data)
            : Promise.resolve({ total: 0, lines: {}, applied: [] }))
            .catch(() => ({ total: 0, lines: {}, applied: [] }))
            .then((d) => {
                if (promoAsk?.key === key) promoAsk = null;
                promo = { key, total: Number(d.total) || 0, lines: d.lines || {}, applied: d.applied || [] };
                if (key === JSON.stringify(saleItems()) && root.isConnected) drawCart();
            });
        promoAsk = { key, done };
        return done;
    }

    function drawPromos() {
        const p = promoNow();
        const box = el('promos');
        const waiting = promo.key !== JSON.stringify(saleItems()) && cart.length;
        const hints = waiting ? [] : promoHints(p.applied);
        box.hidden = !p.applied.length && !waiting && !hints.length;
        box.innerHTML = `${p.applied.length ? `<div class="pos-promo-got">
              <div class="pos-promo-head">🎉 ได้ส่วนลดโปรโมชั่น <b>−฿${money(p.total)}</b></div>
              ${p.applied.map((a) => `
              <div class="pos-promo-line"><span>🏷 ${escapeHtml(a.name)}${a.sets > 1 ? ` <b>×${a.sets} ชุด</b>` : ''}</span><b>−฿${money(a.discount)}</b></div>`).join('')}
              <div class="pos-promo-line muted"><span>ราคาปกติ</span><span>฿${money(cartSubtotal())}</span></div>
            </div>` : ''}
            ${waiting ? '<div class="pos-promo-line muted"><span>กำลังตรวจโปรโมชั่น...</span></div>' : ''}
            ${hints.map((h) => `<div class="pos-promo-hint">💡 <b>${escapeHtml(h.name)}</b>: เพิ่ม ${escapeHtml(h.missing.join(' + '))} รับส่วนลด</div>`).join('')}`;
    }

    function drawCart() {
        const qty = cart.reduce((s, i) => s + i.qty * lineSize(i), 0);
        el('cart-title').textContent = `ตะกร้าสินค้า (${cart.length})`;
        el('cart-qty').textContent = qty ? `${qty} ชิ้น` : '';
        el('total').textContent = `฿ ${money(cartTotal())}`;
        drawPromos();
        clearTimeout(promoTimer);
        if (promo.key !== JSON.stringify(saleItems())) promoTimer = setTimeout(quotePromo, 150);
        const promoLines = promoNow().lines;
        root.querySelector('[data-act="pay"]').disabled = !cart.length;
        root.querySelector('[data-act="clear-cart"]').disabled = !cart.length;
        root.querySelector('[data-act="hold"]').disabled = !cart.length;
        saveJson(CART_KEY, cart);
        drawHeld();
        drawMember();
        const pointsBtn = (it, i) => {
            if (!member || !redeemOf(it)) return '';
            if (it.hold) return `<button type="button" class="pos-points-btn on" data-points="${i}" title="กดเพื่อยกเลิกและคืนแต้ม">⭐ ใช้ ${it.hold.points} แต้ม −฿${money(it.hold.discount)} ✕</button>`;
            return member.available >= redeemOf(it).points ? `<button type="button" class="pos-points-btn" data-points="${i}" title="${escapeHtml(redeemOf(it).name)}: ${redeemOf(it).points} แต้ม ลด ฿${money(redeemOf(it).baht)}">⭐ ใช้แต้ม</button>` : '';
        };
        el('cart').innerHTML = cart.map((it, i) => `
          <div class="pos-line">
            <span class="pos-line-img">${photoImg(it)}</span>
            <div class="pos-line-name">${escapeHtml(it.name)}${it.pack ? ` <span class="pill ok pos-pack-pill">ยกแพ็ค ×${it.pack.pack_size}</span>` : ''}${promoLines[i]?.discount > 0 ? ` <span class="pos-promo-tag inline">🏷 ${escapeHtml(promoName(promoLines[i].promo_id))}</span>` : ''}${pointsBtn(it, i)}
              <small>${it.pack ? `แพ็คละ ฿${money(it.pack.sale_price)} (${it.pack.pack_size} ชิ้น)` : `฿${money(it.sale_price)}`} · ${escapeHtml(it.consignor_name)}</small>
              ${!it.pack && it.packs?.length ? `<span class="pos-pack-adds">${it.packs.map((k) => `<button type="button" class="link-btn" data-add-pack="${i}" data-size="${k.pack_size}" title="เพิ่มยกแพ็ค ${k.pack_size} ชิ้น ราคาแพ็ค">+ ยกแพ็ค ×${k.pack_size} ฿${money(k.sale_price)}</button>`).join(' ')}</span>` : ''}</div>
            <div class="pos-qty">
              <button type="button" data-dec="${i}" aria-label="ลดจำนวน">−</button>
              <input data-qty="${i}" inputmode="numeric" value="${it.qty}" aria-label="${it.pack ? 'จำนวนแพ็ค' : 'จำนวน'}">
              <button type="button" data-inc="${i}" aria-label="เพิ่มจำนวน" ${inCart(it.id) + lineSize(it) > it.qty_stock && !mayShort(it) ? 'disabled' : ''}>+</button>
            </div>
            <b class="pos-line-total">฿${money(linePrice(it) * it.qty)}${promoLines[i]?.discount > 0 ? `<small class="pos-line-promo">โปร −฿${money(promoLines[i].discount)}</small>` : ''}${it.hold ? `<small class="pos-line-promo">แต้ม −฿${money(it.hold.discount)}</small>` : ''}</b>
            <button type="button" class="pos-line-del" data-remove="${i}" aria-label="เอาออกจากตะกร้า">✕</button>
          </div>`).join('') || '<div class="empty">ยังไม่มีสินค้าในตะกร้า<br><small class="muted">สแกนบาร์โค้ด หรือแตะสินค้าเพื่อเพิ่ม</small></div>';
    }

    function drawHeld() {
        const box = el('held');
        box.hidden = !held.length;
        box.innerHTML = held.length ? `<span class="pos-held-label">ตะกร้าที่พักไว้</span>${held.map((h) => {
            const total = h.items.reduce((s, i) => s + linePrice(i) * i.qty, 0);
            const pieces = h.items.reduce((s, i) => s + i.qty * lineSize(i), 0);
            return `<span class="pos-held-chip">
              <button type="button" data-resume="${h.id}" title="${escapeHtml(h.items.map((i) => `${i.name}${i.pack ? ` (แพ็ค ×${i.pack.pack_size})` : ''} x${i.qty}`).join(', '))}">
                <b>#${h.no}</b> ${pieces} ชิ้น · ฿${money(total)}${h.member ? ` · 👤 ${escapeHtml(h.member.name)}` : ''}<small>พักเมื่อ ${escapeHtml(h.at)}</small></button>
              <button type="button" class="pos-held-del" data-drop="${h.id}" aria-label="ลบตะกร้าที่พัก #${h.no}">✕</button>
            </span>`;
        }).join('')}` : '';
    }

    /** Puts the current cart aside; returns its number. */
    function holdCart() {
        const no = held.reduce((m, h) => Math.max(m, h.no), 0) + 1;
        const at = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
        // Held points go back; the buyer stays with the held cart and presses "ใช้แต้ม" again on resume.
        releaseHolds(cart);
        held.push({ id: Date.now(), no, at, items: cart, member: member ? { member_id: member.member_id, name: member.name } : null });
        saveJson(HELD_KEY, held);
        cart = [];
        member = null;
        saveMember();
        return no;
    }

    function resumeCart(id) {
        const h = held.find((x) => x.id === id);
        if (!h) return;
        // A cart in progress is held in its place, so nothing is lost by switching.
        const swapped = cart.length ? holdCart() : null;
        held = held.filter((x) => x !== h);
        cart = h.items.map((c) => freshLine(c, products.find((p) => p.id === c.id) || c));
        saveJson(HELD_KEY, held);
        if (h.member) {
            api.get(`/sales/member-card/${h.member.member_id}`).then((r) => { member = r.data; saveMember(); drawCart(); }).catch(() => {});
        }
        message(`เปิดตะกร้าที่พัก #${h.no}${swapped ? ` (พักตะกร้าเดิมไว้เป็น #${swapped})` : ''}`);
        redraw();
    }

    /** Outside store hours: sales still go through but are booked on the next opening day. */
    function drawHours() {
        const d = options.business_day;
        const h = options.store_hours || {};
        const hours = h.open && h.close ? ` (เวลาทำการ ${h.open}–${h.close} น.)` : '';
        el('hours').innerHTML = d?.after_hours ? `
          <div class="pos-hours">🌙 <span><b>${d.holiday ? `วันนี้ร้านปิด (${escapeHtml(d.holiday)})` : `นอกเวลาทำการ${escapeHtml(hours)}`}</b>
            ${d.date !== options.today
                ? `รายการที่ขายตอนนี้จะลงบัญชีเป็นยอดขายวันที่ <b>${escapeHtml(fmtDate(d.date))}</b> (ไม่กระทบยอดสรุปของวันนี้)`
                : 'ยังไม่ถึงเวลาเปิดร้าน รายการที่ขายตอนนี้ลงบัญชีเป็นยอดขายวันนี้'}
            · บันทึกเวลาขายจริงไว้ตรวจสอบกับการนับสต๊อก</span></div>` : '';
    }

    function drawShortBar() {
        el('shortbar').innerHTML = allowShort() && !options.sell_out_of_stock_always ? `
          <div class="pos-short-bar"><label class="pos-switch"><input type="checkbox" data-out-switch checked><span aria-hidden="true"></span>
            กำลังเปิดขายสินค้าที่มีจำนวนคงเหลือต่ำกว่า 1 <small>แตะเพื่อปิด</small></label></div>` : '';
    }

    // ---------- Cash drawer ----------

    function setDrawer(state) {
        drawer = state || null;
        if (!drawer) return;
        // Count the skip from this device's clock so a server clock difference doesn't matter.
        skipEnds = drawer.skip_until ? Date.now() + (Date.parse(drawer.skip_until.replace(' ', 'T')) - Date.parse(drawer.now.replace(' ', 'T'))) : 0;
    }
    const drawerNeeded = () => !!drawer && drawer.required && !drawer.open;
    const skipping = () => drawerNeeded() && skipEnds > Date.now();
    const skipMinutes = () => Math.round((drawer?.skip_seconds || 300) / 60);
    const clock = (sec) => `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;

    function drawDrawerBar() {
        const box = el('drawerbar');
        // Once the drawer is counted there is nothing to show (the summary is in the "เงินในลิ้นชัก / ปิดยอด" menu).
        if (!drawerNeeded()) { box.innerHTML = ''; return; }
        const left = Math.max(0, Math.ceil((skipEnds - Date.now()) / 1000));
        box.innerHTML = `<div class="pos-drawer warn">⏳ <span><b>ยังไม่ได้ระบุเงินในลิ้นชัก</b>
            ${left ? `ขายได้ชั่วคราวอีก <b data-el="skipleft">${clock(left)}</b> นาที` : ''} · ระบบบันทึกการข้ามไว้</span>
          <button type="button" class="btn sm" data-drawer-act="show">ระบุเงินในลิ้นชัก</button></div>`;
    }

    function showDrawer(reason = '') {
        if (drawerModal || !drawerNeeded()) return;
        let busy = false;
        let error = reason;
        let amount = '';
        let note = '';
        drawerModal = document.createElement('div');
        drawerModal.className = 'modal-backdrop pos-pay-modal';
        root.appendChild(drawerModal);
        function draw() {
            const late = drawer.cash_sales > 0;
            drawerModal.innerHTML = `
              <div class="modal pos-modal" role="dialog" aria-modal="true" aria-label="ระบุเงินในลิ้นชัก">
                <div class="modal-head"><h2>💰 ระบุเงินในลิ้นชักก่อนเริ่มขาย</h2></div>
                <div class="modal-body">
                  <p style="margin:0 0 12px">นับเงินสดทั้งหมดในลิ้นชักตอนนี้ แล้วใส่จำนวนเงิน ระบบใช้ยอดนี้คำนวณเงินสดที่ควรมีในลิ้นชักเมื่อปิดร้าน</p>
                  ${late ? `<div class="pos-msg warn" style="margin-bottom:12px">วันนี้ขายเป็นเงินสดไปแล้ว ฿${money(drawer.cash_sales)} — นับเงินทั้งหมดที่อยู่ในลิ้นชักตอนนี้ ระบบจะหักยอดนี้ออกให้อัตโนมัติ</div>` : ''}
                  <div class="pos-pay-line"><label>จำนวนเงินในลิ้นชัก (บาท)
                    <input data-drawer-amt inputmode="decimal" placeholder="0.00" autocomplete="off" value="${escapeHtml(amount)}"></label></div>
                  <label class="field" style="margin-top:10px">หมายเหตุ (ถ้ามี) <input data-drawer-note maxlength="200" value="${escapeHtml(note)}"></label>
                  ${error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(error)}</div>` : ''}
                  <div class="muted" style="font-size:12.5px;margin-top:10px">ปกติต้องระบุเงินในลิ้นชักก่อนขายทุกวัน การข้ามจะถูกบันทึกไว้เพื่อใช้ประเมินการทำงาน
                    และระบบจะเตือนอีกครั้งใน ${skipMinutes()} นาที</div>
                </div>
                <div class="modal-foot">
                  <button type="button" class="btn secondary pos-big" data-drawer-act="skip" ${busy ? 'disabled' : ''}>ข้ามไปก่อน ${skipMinutes()} นาที</button>
                  <button type="button" class="btn pos-big" data-drawer-act="save" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึกเงินในลิ้นชัก'}</button>
                </div>
              </div>`;
            drawerModal.querySelector('[data-drawer-amt]').focus();
        }
        async function act(kind) {
            if (busy) return;
            amount = drawerModal.querySelector('[data-drawer-amt]').value.trim();
            note = drawerModal.querySelector('[data-drawer-note]').value;
            const value = Number(amount.replace(/,/g, ''));
            if (kind === 'save' && (amount === '' || !(value >= 0))) {
                error = 'กรุณาใส่จำนวนเงินในลิ้นชัก (ถ้าไม่มีเงินให้ใส่ 0)';
                draw();
                return;
            }
            busy = true;
            error = '';
            draw();
            try {
                const res = kind === 'save'
                    ? await api.post('/drawer/open', { amount: value, note })
                    : await api.post('/drawer/skip');
                setDrawer(res.data);
                closeDrawer();
                message(kind === 'save' ? 'บันทึกเงินในลิ้นชักแล้ว' : `ข้ามการระบุเงินในลิ้นชักชั่วคราว ${skipMinutes()} นาที (บันทึกการข้ามไว้แล้ว)`, kind === 'save' ? 'ok' : 'warn');
            } catch (e) {
                busy = false;
                error = e.message;
                draw();
            }
        }
        drawerModal.addEventListener('click', (e) => {
            e.stopPropagation();
            const b = e.target.closest('[data-drawer-act]');
            if (b) act(b.dataset.drawerAct);
        });
        drawerModal.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.matches('[data-drawer-amt], [data-drawer-note]')) {
                e.preventDefault();
                act('save');
            }
        });
        draw();
    }

    function closeDrawer() {
        drawerModal?.remove();
        drawerModal = null;
        drawDrawerBar();
        focusSearch();
    }

    /** Asks for the count when it is needed and no skip is running (waits for an open payment). */
    function checkDrawer() {
        if (!root.isConnected || !drawerNeeded()) return;
        if (!skipping()) {
            if (!modal) showDrawer();
            drawDrawerBar();
            return;
        }
        const t = el('drawerbar').querySelector('[data-el="skipleft"]');
        if (t) t.textContent = clock(Math.max(0, Math.ceil((skipEnds - Date.now()) / 1000)));
        else drawDrawerBar();
    }

    async function refreshDrawer() {
        try {
            setDrawer((await api.get('/drawer/state')).data);
        } catch (e) { /* keep the last state */ }
        drawDrawerBar();
    }

    function redraw() {
        drawHours();
        drawPromoBar();
        drawDrawerBar();
        drawShortBar();
        drawProducts();
        drawCart();
    }

    /**
     * After adding to the cart: the product list keeps its drawing and only this product's tile changes,
     * so a run of scans stays quick on a slow machine. A list filtered by other words is drawn anew.
     */
    function drawAfterAdd(p) {
        drawHours();
        drawDrawerBar();
        drawShortBar();
        drawCart();
        drawSearchButtons();
        if (listTerm() !== drawnTerm) drawProducts();
        else drawPickedTile(p);
    }

    function drawPickedTile(p) {
        const tile = el('products').querySelector(`[data-pick="${p.id}"]`);
        if (!tile) return;
        const n = inCart(p.id);
        tile.classList.toggle('picked', n > 0);
        tile.querySelector('.pos-tile-meta span, .pos-row-stock').textContent = `เหลือ ${p.qty_stock - n}`;
        let badge = tile.querySelector('.pos-tile-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'pos-tile-badge';
            tile.append(badge);
        }
        badge.textContent = n;
    }

    /**
     * One more piece, or with pack one more whole pack at the pack price. A pack takes in the loose pieces
     * of the same product already in the cart (1 piece then "ยกแพ็ค" = 1 pack, not 1 piece + 1 pack).
     */
    function add(p, pack = null) {
        closeKeyboard(); // show the cart again once a product is picked
        const size = pack?.pack_size || 1;
        const line = cart.find((c) => c.id === p.id && lineSize(c) === size);
        const pieces = size > 1 ? cart.find((c) => c.id === p.id && lineSize(c) === 1) : null;
        const absorbed = pieces ? Math.min(pieces.qty, size) : 0;
        const short = inCart(p.id) - absorbed + size > p.qty_stock;
        if (short && !mayShort(p)) {
            play('error');
            message(`${p.name} มีคงเหลือเพียง ${p.qty_stock} ชิ้น${size > 1 ? ` (ยกแพ็คใช้ ${size} ชิ้น)` : ''}`, 'error');
            return;
        }
        // Points held for a line are worked out for its quantity: a new quantity gives them back.
        if (pieces) {
            releaseHolds([pieces]);
            pieces.qty -= absorbed;
            if (pieces.qty < 1) cart.splice(cart.indexOf(pieces), 1);
        }
        if (line?.hold) {
            releaseHolds([line]);
            message(`${line.name}: จำนวนเปลี่ยน คืนแต้มที่จองไว้แล้ว กด "ใช้แต้ม" อีกครั้งได้`, 'warn');
        }
        if (line) line.qty += 1;
        else {
            const { pack: _old, ...base } = p;
            cart.push(pack ? { ...base, qty: 1, pack } : { ...base, qty: 1 });
        }
        play(short ? 'warn' : size > 1 ? 'pack' : 'scan');
        if (p.discontinued) message(`${p.name}: เลิกจำหน่ายแล้ว ราคา ฿${money(pack ? pack.sale_price : p.sale_price)} คงเหลือในระบบ ${p.qty_stock} ชิ้น`, 'warn');
        else message(short ? `${p.name}: คงเหลือในระบบ ${p.qty_stock} ชิ้น ขายได้ แต่สต๊อกจะติดลบ ควรตรวจนับ/บันทึกรับสินค้าภายหลัง` : '', 'warn');
        drawAfterAdd(p);
        const idx = cart.findIndex((c) => c.id === p.id && lineSize(c) === size);
        el('cart').children[idx]?.scrollIntoView({ block: 'nearest' });
    }

    /** Enter in the search box: a scanned barcode, or the only product matching the text. */
    async function submitSearch() {
        clearTimeout(drawTimer);
        const code = q.value.trim();
        if (!code) { seeking(null); return; }
        searched = code;
        message('');
        seeking(code);
        try {
            await findAndAdd(code);
        } finally {
            seeking(null);
        }
    }

    async function findAndAdd(code) {
        // Scanned while the product list is still loading: wait for it rather than miss.
        if (!loaded && firstLoad) await firstLoad.catch(() => {});
        // A pack barcode with a whole-pack price sells the pack.
        const byCode = (list) => {
            for (const x of list) {
                if (x.barcode && x.barcode === code) return [x, null];
                const k = (x.packs || []).find((pk) => pk.barcode === code);
                if (k) return [x, k];
            }
            return [null, null];
        };
        let [p, pack] = byCode(products);
        // Not a product barcode on this page: a national ID (13 digits) or phone (10 digits) names the buyer.
        if (!p && options.members && memberRef(code)) {
            const r = await api.get(`/sales/members?q=${code}`).then((x) => x.data).catch(() => null);
            if (r?.card) {
                q.value = '';
                searched = '';
                drawProducts();
                setMember(r.card);
                return;
            }
        }
        if (!p) {
            const list = matches();
            if (list.length === 1) p = list[0];
        }
        if (!p && /^\S{4,}$/.test(code)) {
            // Not in the loaded list (stock arrived after the page was opened, or a discontinued product).
            const res = await api.get(`/sales/products?groups=1&q=${encodeURIComponent(code)}`).catch(() => ({ data: [] }));
            [p, pack] = byCode(res.data.map(normalize));
            if (p && !p.discontinued && !products.some((x) => x.id === p.id)) products.push(p);
        }
        if (!p) {
            drawProducts();
            // Several matches is a result, not a mistake: the list now shows them to pick from.
            const found = matches().length;
            if (found > 1) {
                message(`พบสินค้า ${found} รายการ แตะเลือกสินค้าที่ต้องการ`, 'warn');
                return;
            }
            play('error');
            const isCode = /^\d{4,}$/.test(code);
            message(isCode ? `ไม่พบสินค้าบาร์โค้ด ${code} (หรือสินค้าหมด)` : `ไม่พบสินค้า "${code}"`, 'error');
            if (isCode) q.select();
            return;
        }
        q.value = '';
        searched = '';
        add(p, pack);
    }

    function normalize(p) {
        return {
            ...p, sale_price: Number(p.sale_price), qty_stock: Number(p.qty_stock), sold_qty: Number(p.sold_qty),
            packs: (p.packs || []).map((k) => ({ ...k, pack_size: Number(k.pack_size), sale_price: Number(k.sale_price) })),
        };
    }

    async function loadProducts() {
        try {
            const [res, running, points] = await Promise.all([
                api.get(`/sales/products?all=1&groups=1&sort=popular${allowShort() ? '&short=1' : ''}`),
                api.get('/sales/promos').catch(() => ({ data: [] })),
                options.members ? api.get('/sales/redeems').catch(() => ({ data: [] })) : { data: [] },
            ]);
            products = res.data.map(normalize);
            promos = running.data || [];
            redeems = points.data || [];
            loaded = true;
        } catch (e) {
            message(e.message, 'error');
            redraw();
            return;
        }
        // Keep cart lines in step with the new prices and stock figures.
        const gone = [];
        const dropped = [];
        cart = cart.filter((c) => c.discontinued || products.some((p) => p.id === c.id) || !gone.push(c.name) || !dropped.push(c)).map((c) => {
            const line = freshLine(c, products.find((x) => x.id === c.id) || c);
            const out = { ...line, qty: mayShort(line) ? line.qty : Math.min(line.qty, Math.floor(line.qty_stock / lineSize(line))) };
            if (c.hold && out.qty === c.qty && !out.pack) out.hold = c.hold;
            else if (c.hold) dropped.push(c);
            return out;
        }).filter((c) => c.qty > 0);
        releaseHolds(dropped);
        if (gone.length) message(`เอาออกจากตะกร้าเพราะสินค้าหมด: ${gone.join(', ')}`, 'warn');
        redraw();
    }

    // ---------- Payment ----------

    function openPayment() {
        if (!cart.length) return;
        closeKeyboard();
        const total = cartTotal();
        const methods = options.payment_methods;
        const mixed = options.payment_mixed;
        const first = methods.find((m) => m.key === 'cash') || methods[0];
        let amounts = { [first.key]: total };
        let selected = [first.key];
        let busy = false;
        let error = null;
        let done = null;
        let nextTimer = null;
        let nextTick = null;
        let paidWith = [];
        life.signal.addEventListener('abort', () => { clearTimeout(nextTimer); clearInterval(nextTick); }, { once: true });
        // Until the cashier types a cash amount, cash follows whatever the other methods leave.
        let cashTouched = false;
        // Normally one method pays the whole bill; the cashier turns this on to split it.
        let mixMode = false;
        const payWithOnly = (k) => { selected = [k]; amounts = { [k]: total }; cashTouched = false; };
        const followCash = () => {
            if (!cashTouched && selected.includes('cash')) amounts.cash = restFor('cash');
        };

        modal = document.createElement('div');
        modal.className = 'modal-backdrop pos-pay-modal';
        root.appendChild(modal);
        const thisModal = modal;
        if (options.promptpay) {
            loadThaiQr().then(() => { if (modal === thisModal && !done) refreshFigures(); });
        }

        const sumOf = (keys) => keys.reduce((s, k) => s + (Number(amounts[k]) || 0), 0);
        const round2 = (n) => Math.round(n * 100) / 100;
        function figures() {
            const cash = selected.includes('cash') ? Number(amounts.cash) || 0 : 0;
            const nonCash = round2(sumOf(selected.filter((k) => k !== 'cash')));
            const cashDue = round2(total - nonCash);
            const remaining = round2(Math.max(0, cashDue - cash));
            const change = selected.includes('cash') ? round2(Math.max(0, cash - Math.max(0, cashDue))) : 0;
            const over = nonCash > total + 0.004;
            return { paid: round2(cash + nonCash), remaining, change, over, ok: !over && remaining <= 0.004 && sumOf(selected) > 0 };
        }
        function restFor(key) {
            return round2(Math.max(0, total - sumOf(selected.filter((k) => k !== key))));
        }
        /** The code is always on screen: the QR row's amount, else what the other non-cash rows leave, else the whole bill. */
        function qrAmount() {
            const own = selected.includes('qr') ? Number(amounts.qr) || 0 : 0;
            if (own > 0) return own;
            const rest = round2(total - sumOf(selected.filter((k) => k !== 'cash' && k !== 'qr')));
            return rest > 0 ? rest : total;
        }
        function quickCash() {
            const due = restFor('cash');
            const set = new Set([due]);
            for (const step of [10, 20, 50, 100, 500, 1000]) {
                const v = Math.ceil(due / step) * step;
                if (v > due) set.add(v);
            }
            return [...set].sort((a, b) => a - b).slice(0, 6);
        }

        function draw() {
            if (done) {
                const secs = Math.ceil(NEXT_SALE_MS / 1000);
                modal.innerHTML = `
                  <div class="modal pos-modal" role="dialog" aria-modal="true" style="--pos-next-ms:${NEXT_SALE_MS}ms">
                    <div class="modal-body pos-done">
                      <div class="pos-done-icon">✓</div>
                      <h2>บันทึกการขายแล้ว</h2>
                      <div class="muted">เลขที่ ${escapeHtml(done.doc_no)} · ยอดขาย ฿${money(done.total_value)}</div>
                      ${done.after_hours ? `<div class="pos-hours-note">ขายนอกเวลาทำการ · ลงบัญชีวันที่ ${escapeHtml(fmtDate(done.business_date))}</div>` : ''}
                      ${done.change > 0 ? `<div class="pos-change">เงินทอน <b>฿${money(done.change)}</b></div>` : ''}
                      ${done.member ? `<div class="pos-done-points">⭐ ${escapeHtml(member?.name || 'สมาชิก')}: ได้ <b>${done.member.earned + done.member.welcome}</b> แต้ม${done.member.welcome ? ` (รวมซื้อครั้งแรก ${done.member.welcome})` : ''}${done.member.used ? ` · ใช้ ${done.member.used} แต้ม ลด ฿${money(done.member.discount)}` : ''} · คงเหลือ <b>${done.member.balance}</b> แต้ม</div>` : ''}
                      <button type="button" class="btn pos-big pos-next" data-pay="next">
                        <svg class="pos-next-ring" viewBox="0 0 26 26" aria-hidden="true"><circle class="track" cx="13" cy="13" r="10"/><circle class="run" cx="13" cy="13" r="10"/></svg>
                        เริ่มขายรายการถัดไปใน <span data-secs>${secs}</span> วินาที</button>
                      <div class="pos-done-hint">ไม่ต้องกดปุ่ม ระบบเริ่มการขายใหม่ให้เอง${done.change > 0 ? ' · เงินทอนยังแสดงใต้ตะกร้า' : ''}</div>
                    </div>
                  </div>`;
                modal.querySelector('[data-pay="next"]').focus();
                // Both hands stay on the next customer's goods: the next sale starts by itself.
                const started = Date.now();
                nextTick = setInterval(() => {
                    const s = modal?.querySelector('[data-secs]');
                    if (s) s.textContent = String(Math.max(1, Math.ceil((NEXT_SALE_MS - (Date.now() - started)) / 1000)));
                }, 250);
                nextTimer = setTimeout(close, NEXT_SALE_MS);
                return;
            }
            const f = figures();
            const scrolled = modal.querySelector('.modal-body')?.scrollTop || 0;
            modal.innerHTML = `
              <div class="modal pos-modal" role="dialog" aria-modal="true" aria-label="ชำระเงิน">
                <div class="modal-head"><h2>ชำระเงิน</h2><button type="button" class="modal-close" data-pay="close" aria-label="ปิด">✕</button></div>
                <div class="modal-body pos-pay-grid">
                  <div class="pos-due"><span>ยอดที่ต้องชำระ${member ? `<small>👤 ${escapeHtml(member.name)}${pointsOff() ? ` · ใช้แต้มลด ฿${money(pointsOff())}` : ''}</small>` : ''}</span><b>฿${money(total)}</b></div>
                  <div class="pos-pay-left">
                  ${options.promptpay ? thaiQrWarning(options.promptpay) + thaiQrCard(options.promptpay, qrAmount()) : ''}
                  <div class="pos-methods">
                    ${methods.map((m) => `<button type="button" class="pos-method${selected.includes(m.key) ? ' active' : ''}" data-method="${m.key}">
                      <span>${METHOD_ICON[m.key] || ''}</span>${escapeHtml(m.label)}</button>`).join('')}
                  </div>
                  ${mixed && methods.length > 1 ? `
                  <label class="pos-switch"><input type="checkbox" data-mix ${mixMode ? 'checked' : ''}><span aria-hidden="true"></span>
                    ชำระแบบผสม <small class="muted">${mixMode ? 'แตะเลือกหลายวิธี แล้วใส่จำนวนเงินของแต่ละวิธี' : '(เช่น เงินสดบางส่วน + โอนส่วนที่เหลือ)'}</small></label>` : ''}
                  </div>
                  <div class="pos-pay-rest">
                  <div class="pos-pay-lines">
                    ${selected.map((k) => {
                        const m = methods.find((x) => x.key === k);
                        return `<div class="pos-pay-line">
                          <label>${METHOD_ICON[k] || ''} ${escapeHtml(m.label)}${k === 'cash' ? ' (รับมา)' : ''}
                            <input data-amt="${k}" inputmode="decimal" value="${amounts[k] ?? ''}" placeholder="0.00"></label>
                          ${selected.length > 1 ? `<button type="button" class="btn secondary" data-rest="${k}">ยอดที่เหลือ</button>` : ''}
                          ${k === 'cash' ? `<div class="pos-notes">${quickCash().map((v) => `<button type="button" data-note="${v}">${v === restFor('cash') ? 'พอดี ' : ''}฿${money(v)}</button>`).join('')}</div>` : ''}
                        </div>`;
                    }).join('') || '<div class="empty">เลือกวิธีชำระเงิน</div>'}
                  </div>
                  <div class="pos-pay-sum">
                    <div><span>รับชำระแล้ว</span><b>฿${money(f.paid)}</b></div>
                    ${f.remaining > 0 ? `<div class="due"><span>คงเหลือต้องชำระ</span><b>฿${money(f.remaining)}</b></div>` : ''}
                    ${f.change > 0 ? `<div class="change"><span>เงินทอน</span><b>฿${money(f.change)}</b></div>` : ''}
                  </div>
                  ${f.over ? '<div class="error-box">ยอดที่ไม่ใช่เงินสดเกินยอดที่ต้องชำระ</div>' : ''}
                  ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
                  </div>
                </div>
                <div class="modal-foot">
                  <button type="button" class="btn secondary pos-big" data-pay="close">ยกเลิก</button>
                  <button type="button" class="btn pos-big" data-pay="confirm" ${f.ok && !busy ? '' : 'disabled'}>${busy ? 'กำลังบันทึก...' : 'ยืนยันการชำระเงิน'}</button>
                </div>
              </div>`;
            modal.querySelector('.modal-body').scrollTop = scrolled;
        }

        /** Refresh only the totals and button while typing, so the amount box keeps focus. */
        function refreshFigures() {
            const keep = document.activeElement?.dataset?.amt;
            const pos = document.activeElement?.selectionStart;
            draw();
            if (keep) {
                const inp = modal.querySelector(`[data-amt="${keep}"]`);
                inp?.focus();
                try { inp?.setSelectionRange(pos, pos); } catch (e) { /* not a text input */ }
            }
        }

        function close() {
            clearTimeout(nextTimer);
            clearInterval(nextTick);
            if (!modal) return;
            modal.remove();
            modal = null;
            if (done) {
                cart = [];
                member = null;
                saveMember();
                message(`บันทึกการขาย ${done.doc_no} แล้ว${done.member ? ` · สมาชิกได้ ${done.member.earned + done.member.welcome} แต้ม` : ''}`);
                last = {
                    id: done.id, doc_no: done.doc_no, total: Number(done.total_value) || 0, change: Number(done.change) || 0,
                    methods: paidWith, until: Date.now() + PAYMENT_FIX_MS, fixed: false,
                };
                drawLast();
                loadProducts();
            }
            focusSearch();
            checkDrawer();
        }

        async function confirm() {
            if (busy || !figures().ok) return;
            busy = true;
            error = null;
            draw();
            try {
                const res = await api.post('/sales', {
                    // A sale group (id = -group id) is sold as one item; the server takes the pieces from its members in turn.
                    items: saleItems().map((it, i) => (cart[i]?.hold ? { ...it, points_hold_id: cart[i].hold.id } : it)),
                    member_id: member?.member_id || null,
                    // Bundle promotions: the server works them out again and refuses if they no longer match.
                    promos: true,
                    promo_discount: promoNow().total,
                    allow_short: allowShort(),
                    payments: selected.map((k) => ({ method: k, amount: Number(amounts[k]) || 0 })),
                });
                done = res.data;
                paidWith = selected.filter((k) => (Number(amounts[k]) || 0) > 0);
                play('pay');
                refreshDrawer();
            } catch (e) {
                error = e.message;
                if (e.status === 409 && e.data?.code === 'points_hold') {
                    // A points hold ran out (or was given back on another till): drop them all, the cashier presses again.
                    busy = false;
                    cart.forEach((c) => { delete c.hold; });
                    close();
                    message(e.message, 'warn');
                    if (member) api.get(`/sales/member-card/${member.member_id}`).then((r) => { member = r.data; saveMember(); drawCart(); }).catch(() => {});
                    drawCart();
                    return;
                }
                if (e.status === 409) {
                    // The bundle discount changed (a promotion or price was changed meanwhile): new figures, pay again.
                    busy = false;
                    promo = { key: '', total: 0, lines: {}, applied: [] };
                    close();
                    message(e.message, 'warn');
                    await quotePromo();
                    return;
                }
                if (e.status === 428) {
                    await refreshDrawer();
                    skipEnds = 0;
                    showDrawer('ระบุเงินในลิ้นชักก่อน แล้วกดยืนยันการชำระเงินอีกครั้ง');
                }
            }
            busy = false;
            draw();
        }

        modal.addEventListener('click', (e) => {
            if (done) return close(); // a tap anywhere starts the next sale at once
            const t = e.target.closest('button');
            if (!t) return;
            const act = t.dataset.pay;
            if (act === 'close' || act === 'next') return close();
            if (act === 'confirm') return confirm();
            if (t.dataset.method) {
                const k = t.dataset.method;
                if (!mixMode) {
                    payWithOnly(k);
                } else if (selected.includes(k)) {
                    if (selected.length > 1) {
                        selected = selected.filter((x) => x !== k);
                        delete amounts[k];
                    }
                } else {
                    selected.push(k);
                    amounts[k] = restFor(k);
                }
            } else if (t.dataset.rest) {
                amounts[t.dataset.rest] = restFor(t.dataset.rest);
                if (t.dataset.rest === 'cash') cashTouched = true;
            } else if (t.dataset.note) {
                amounts.cash = Number(t.dataset.note);
                cashTouched = true;
            }
            if (t.dataset.method || t.dataset.rest) followCash();
            error = null;
            draw();
        });
        modal.addEventListener('change', (e) => {
            if (!e.target.matches('[data-mix]')) return;
            mixMode = e.target.checked;
            if (!mixMode) payWithOnly(selected[0] || first.key);
            error = null;
            draw();
        });
        modal.addEventListener('input', (e) => {
            const k = e.target.dataset.amt;
            if (!k) return;
            amounts[k] = e.target.value.replace(/[^\d.]/g, '');
            if (k === 'cash') cashTouched = true;
            else followCash();
            error = null;
            refreshFigures();
        });
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            else if (e.key === 'Enter') { e.preventDefault(); done ? close() : confirm(); }
        });

        draw();
        modal.querySelector('[data-amt]')?.select();
    }

    // ---------- Full screen ----------

    function setFull(full) {
        root.classList.toggle('pos-full', full);
        document.body.classList.toggle('pos-full-open', full);
        root.querySelector('[data-act="full"]').classList.toggle('active', full);
        if (full && document.fullscreenEnabled && !document.fullscreenElement) {
            document.documentElement.requestFullscreen({ navigationUI: 'hide' }).catch(() => {});
        } else if (!full && document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    }

    // ---------- Events ----------

    on(q, 'input', () => {
        message('');
        if (searched && q.value.trim() !== searched) searched = '';
        drawSearchButtons();
        const now = performance.now();
        burst = now - lastKeyAt < 35 ? burst + 1 : 0;
        lastKeyAt = now;
        clearTimeout(drawTimer);
        if (burst >= 3) {
            // Keys this fast come from a scanner: show the search screen and wait for its Enter.
            seeking(q.value.trim());
            drawTimer = setTimeout(() => { seeking(null); drawProductsIfTermChanged(); }, 400);
            return;
        }
        drawTimer = setTimeout(drawProductsIfTermChanged, q.value ? 120 : 0);
    });
    on(q, 'keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); submitSearch(); }
        else if (e.key === 'Escape') { clearTimeout(drawTimer); q.value = ''; drawProducts(); }
    });
    // A tap on the search screen puts it away (e.g. a slow network).
    on(el('seek'), 'pointerdown', () => seeking(null));
    // Tapping the search box itself means the cashier wants to type.
    on(q, 'pointerdown', showKeyboard);
    on(q, 'blur', () => { if (TOUCH) setTimeout(setKeyboard, 0); });

    on(root, 'change', async (e) => {
        if (!e.target.matches('[data-out-switch]')) return;
        shortMode = e.target.checked;
        savePref(SHORT_KEY, shortMode ? '1' : '0');
        outHints = { term: '', items: [] };
        await loadProducts();
        message(shortMode ? 'เปิดขายสินค้าที่มีจำนวนคงเหลือต่ำกว่า 1 แล้ว' : 'ปิดการขายสินค้าที่มีจำนวนคงเหลือต่ำกว่า 1 แล้ว');
        focusSearch();
    });

    on(root, 'load', (e) => {
        if (e.target.matches?.('img[data-thumbless]')) uploadThumb(e.target);
    }, true);

    // A tap anywhere outside an open dropdown closes it.
    on(document, 'pointerdown', (e) => {
        if (!e.target.closest?.('.pos-dd')) toggleDropdown(null);
    });
    on(document, 'keydown', (e) => { if (e.key === 'Escape') toggleDropdown(null); });

    on(el('last'), 'click', (e) => { if (e.target.closest('[data-fix-pay]')) fixLastPayment(); });
    life.signal.addEventListener('abort', () => clearInterval(lastTimer));

    on(el('cart'), 'change', (e) => {
        const i = e.target.dataset.qty;
        if (i === undefined) return;
        const line = cart[Number(i)];
        const n = Math.floor(Number(e.target.value));
        // Most of this line the stock allows, after the product's other lines (pieces / packs).
        const size = lineSize(line);
        const max = Math.floor((line.qty_stock - (inCart(line.id) - line.qty * size)) / size);
        if (n !== line.qty) releaseHolds([line]);
        if (!(n > 0)) cart.splice(Number(i), 1);
        else {
            if (n > max) {
                message(mayShort(line)
                    ? `${line.name}: คงเหลือในระบบ ${line.qty_stock} ชิ้น ขายได้ แต่สต๊อกจะติดลบ`
                    : `${line.name} มีคงเหลือเพียง ${line.qty_stock} ชิ้น`, mayShort(line) ? 'warn' : 'error');
            }
            line.qty = mayShort(line) ? n : Math.min(n, max);
            if (line.qty < 1) cart.splice(Number(i), 1);
        }
        redraw();
        q.focus({ preventScroll: true });
    });
    on(el('cart'), 'keydown', (e) => {
        if (e.key === 'Enter' && e.target.dataset.qty !== undefined) e.target.blur();
    });
    on(el('cart'), 'focusin', (e) => { if (e.target.dataset.qty !== undefined) e.target.select(); });

    on(root, 'click', (e) => {
        if (modal && modal.contains(e.target)) return;
        const t = e.target.closest('button');
        if (t) {
            if (t.dataset.drawerAct === 'show') {
                skipEnds = 0;
                showDrawer();
            } else if (t.dataset.pick) {
                const p = products.find((x) => x.id === Number(t.dataset.pick));
                if (p) add(p);
            } else if (t.dataset.pickPack) {
                const p = products.find((x) => x.id === Number(t.dataset.pickPack));
                const k = p?.packs?.find((x) => x.pack_size === Number(t.dataset.size));
                if (k) add(p, k);
            } else if (t.dataset.ddToggle) {
                toggleDropdown(t.dataset.ddToggle);
                return; // keep the menu open (no refocus of the search box)
            } else if (t.dataset.ddPick) {
                pickDropdown(t.dataset.ddPick, t.dataset.value);
            } else if (t.dataset.inc) {
                const line = cart[Number(t.dataset.inc)];
                if (line) add(line, line.pack || null);
            } else if (t.dataset.addPack) {
                const line = cart[Number(t.dataset.addPack)];
                const k = line?.packs?.find((x) => x.pack_size === Number(t.dataset.size));
                if (k) add(line, k);
            } else if (t.dataset.dec) {
                const i = Number(t.dataset.dec);
                releaseHolds([cart[i]]);
                if (cart[i].qty > 1) cart[i].qty -= 1;
                else cart.splice(i, 1);
                redraw();
            } else if (t.dataset.points) {
                togglePoints(Number(t.dataset.points));
            } else if (t.dataset.act === 'member') {
                openMemberSearch();
                return;
            } else if (t.dataset.act === 'member-off') {
                setMember(null);
            } else if (t.dataset.act === 'survey') {
                openSurvey();
                return;
            } else if (t.dataset.remove) {
                releaseHolds([cart[Number(t.dataset.remove)]]);
                cart.splice(Number(t.dataset.remove), 1);
                play('remove');
                redraw();
            } else if (t.dataset.act === 'search-q') {
                submitSearch();
            } else if (t.dataset.act === 'clear-q') {
                q.value = '';
                drawProducts();
            } else if (t.dataset.act === 'hold') {
                if (cart.length) {
                    const no = holdCart();
                    play('remove');
                    message(`พักตะกร้าไว้เป็น #${no} แล้ว เริ่มรายการขายใหม่ได้`);
                    redraw();
                }
            } else if (t.dataset.resume) {
                resumeCart(Number(t.dataset.resume));
            } else if (t.dataset.drop) {
                const h = held.find((x) => x.id === Number(t.dataset.drop));
                const pieces = h ? h.items.reduce((s, i) => s + i.qty * lineSize(i), 0) : 0;
                if (h) confirmDialog(`สินค้า ${pieces} ชิ้นในตะกร้านี้จะถูกนำออกทั้งหมด`, { title: `ลบตะกร้าที่พัก #${h.no}?`, okText: 'ลบตะกร้า', danger: true }).then((yes) => {
                    if (!yes) return;
                    held = held.filter((x) => x !== h);
                    saveJson(HELD_KEY, held);
                    drawHeld();
                });
            } else if (t.dataset.act === 'clear-cart') {
                if (cart.length) confirmDialog(`สินค้า ${cart.length} รายการในตะกร้าจะถูกนำออกทั้งหมด`, { title: 'ล้างตะกร้าสินค้า?', okText: 'ล้างตะกร้า', danger: true }).then((yes) => {
                    if (yes) { releaseHolds(cart); cart = []; member = null; saveMember(); redraw(); }
                });
            } else if (t.dataset.act === 'promo-filter') {
                group = group === 'promo' ? 'all' : 'promo';
                savePref('group', group);
                drawPromoBar();
                drawProducts();
            } else if (t.dataset.act === 'pay') {
                if (!cart.length) return;
                clearTimeout(promoTimer);
                t.disabled = true;
                quotePromo().then(() => { t.disabled = !cart.length; if (!modal) openPayment(); });
                return;
            } else if (t.dataset.act === 'sound') {
                openSoundPanel(t, () => { t.innerHTML = soundButtonLabel(); });
                return;
            } else if (t.dataset.act === 'full') {
                setFull(!root.classList.contains('pos-full'));
            } else if (t.dataset.act === 'kb') {
                if (isKeyboardOpen()) closeKeyboard();
                else showKeyboard();
            }
        }
        setTimeout(focusSearch, 0);
    });

    // A scanner keeps typing even when the cashier last tapped somewhere else.
    on(document, 'keydown', (e) => {
        if (modal || drawerModal || isDialogOpen() || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
        const a = document.activeElement;
        if (a && a.matches('input, select, textarea') ) return;
        q.focus({ preventScroll: true });
    });
    on(document, 'fullscreenchange', () => {
        if (!document.fullscreenElement && root.classList.contains('pos-full')) setFull(false);
    });
    on(window, 'focus', () => setTimeout(focusSearch, 0));
    on(window, 'hashchange', () => {
        if (root.classList.contains('pos-full')) setFull(false);
        closeKeyboard();
        life.abort();
    });

    setKeyboard();
    drawDropdowns();
    drawCart();
    focusSearch();
    const loadOptions = () => api.get('/sales/options').then((o) => {
        if (o.payment_methods?.length) options = o;
        if (o.promptpay) loadThaiQr(); // ready before the payment screen opens
        if ('drawer' in o) setDrawer(o.drawer);
    }).catch(() => {});
    firstLoad = loadOptions().then(loadProducts);
    await firstLoad;
    // The buyer kept from before a reload: their points may have moved since.
    if (member) {
        api.get(`/sales/member-card/${member.member_id}`).then((r) => { member = r.data; saveMember(); drawCart(); })
            .catch(() => { member = null; saveMember(); cart.forEach((c) => { delete c.hold; }); drawCart(); });
    }
    focusSearch();
    checkDrawer();
    // Asks again when a skip runs out, and keeps its countdown moving.
    const drawerTimer = setInterval(checkDrawer, 1000);
    life.signal.addEventListener('abort', () => {
        clearInterval(drawerTimer);
        drawerModal?.remove();
    });
    // Store hours pass while the page stays open (e.g. closing time arrives).
    const hoursTimer = setInterval(() => loadOptions().then(drawHours), 60000);
    life.signal.addEventListener('abort', () => clearInterval(hoursTimer));
}
