import { api } from '../api.js';
import { money, escapeHtml, fmtDate } from '../util.js';
import { confirmDialog, isDialogOpen } from '../dialog.js';
import { thumbDataUrl, THUMB_SIZE } from '../images.js';

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
];
const GROUP_FN = {
    all: () => true,
    nobarcode: (p) => !(p.barcode || '').trim(),
    consign: (p) => p.consignor_id != null,
};
const METHOD_ICON = { cash: '💵', transfer: '🏦', qr: '📱', card: '💳' };
// Touch screens: focusing the search box must not pop up the on-screen
// keyboard every time (a barcode scanner types into it like a keyboard).
const TOUCH = matchMedia('(pointer: coarse)').matches;

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

let audio;
function beep(ok) {
    try {
        audio ||= new AudioContext();
        const o = audio.createOscillator();
        const g = audio.createGain();
        o.frequency.value = ok ? 1250 : 330;
        g.gain.value = 0.08;
        o.connect(g).connect(audio.destination);
        o.start();
        o.stop(audio.currentTime + (ok ? 0.08 : 0.25));
    } catch (e) { /* no audio */ }
}

export async function render(container, { user, navigate } = {}) {
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
    let keyboard = !TOUCH || pref('kb', '0') === '1';
    let options = { payment_methods: [{ key: 'cash', label: 'เงินสด' }], payment_mixed: false, sell_out_of_stock: false };
    // Admin switch: sell products whose recorded stock is below 1 (stock goes negative).
    // The user's own switch (remembered on this device); only roles the admin allows get it.
    const SHORT_KEY = `short.${user?.id ?? 0}`;
    let shortMode = pref(SHORT_KEY, '0') === '1';
    const canShort = () => !!options.sell_out_of_stock;
    const allowShort = () => canShort() && shortMode;
    let modal = null; // the payment dialog element while open
    // Cash drawer of the current accounting day: counted before selling, or skipped for a few minutes.
    let drawer = null; // state from /sales/options and /drawer/*
    let skipEnds = 0; // Date.now() when this cashier's skip runs out
    let drawerModal = null; // the opening-count dialog while open
    let msgTimer;
    // Products matching the search that cannot be sold (stock below 1, switch off).
    let outHints = { term: '', items: [] };
    let outTimer;

    const life = new AbortController();
    const on = (target, type, fn, capture = false) => target.addEventListener(type, fn, { signal: life.signal, capture });

    container.innerHTML = `
      <div class="pos">
        <div class="pos-bar card">
          <label class="pos-search">
            <span aria-hidden="true">🔍</span>
            <input data-el="q" placeholder="สแกนบาร์โค้ด หรือพิมพ์ชื่อสินค้า" autocomplete="off" enterkeyhint="search" spellcheck="false">
            <button type="button" class="pos-icon-btn" data-act="clear-q" aria-label="ล้างคำค้น" hidden>✕</button>
          </label>
          ${TOUCH ? '<button type="button" class="pos-tool" data-act="kb" aria-label="แป้นพิมพ์บนจอ" title="แป้นพิมพ์บนจอ">⌨</button>' : ''}
          <div class="pos-seg" role="group" aria-label="รูปแบบการแสดงสินค้า">
            <button type="button" data-view="grid">▦ รูปภาพ</button>
            <button type="button" data-view="list">☰ รายการ</button>
          </div>
          <select class="pos-sort" data-el="sort" aria-label="เรียงสินค้า">
            ${SORTS.map(([k, l]) => `<option value="${k}" ${k === sort ? 'selected' : ''}>เรียง: ${l}</option>`).join('')}
          </select>
          <div class="pos-seg" role="group" aria-label="กลุ่มสินค้า">
            ${GROUPS.map(([k, l]) => `<button type="button" data-group="${k}">${l}</button>`).join('')}
          </div>
          <button type="button" class="pos-tool" data-act="full" aria-label="เต็มหน้าจอ" title="เต็มหน้าจอ">⛶</button>
        </div>
        <div data-el="hours"></div>
        <div data-el="drawerbar"></div>
        <div data-el="shortbar"></div>
        <div data-el="msg"></div>
        <div class="pos-main">
          <section class="pos-products card" data-el="products"><div class="empty">กำลังโหลดสินค้า...</div></section>
          <aside class="pos-cart card">
            <div class="pos-cart-head"><h2 data-el="cart-title">ตะกร้าสินค้า</h2>
              <div class="pos-cart-tools">
                <button type="button" class="btn secondary sm" data-act="hold" title="พักตะกร้านี้ไว้ แล้วเปิดรายการขายใหม่">⏸ พักตะกร้า</button>
                <button type="button" class="btn secondary sm" data-act="clear-cart">ล้างตะกร้า</button>
              </div>
            </div>
            <div class="pos-held" data-el="held" hidden></div>
            <div class="pos-cart-list" data-el="cart"></div>
            <div class="pos-cart-foot">
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
        root.querySelector('[data-act="kb"]')?.classList.toggle('active', keyboard);
    }

    /** Puts the cursor back in the search box unless the cashier is typing somewhere else. */
    function focusSearch() {
        if (modal || drawerModal || isDialogOpen() || !root.isConnected) return;
        const a = document.activeElement;
        if (a && a !== q && root.contains(a) && a.matches('input, select, textarea')) return;
        q.focus({ preventScroll: true });
    }

    function message(text, type = 'ok') {
        clearTimeout(msgTimer);
        el('msg').innerHTML = text ? `<div class="pos-msg ${type}">${escapeHtml(text)}</div>` : '';
        if (text && type === 'ok') msgTimer = setTimeout(() => message(''), 4000);
    }

    function photoUrl(p) {
        return p.image_token ? api.downloadUrl(`/products/${p.id}/photo?size=thumb&v=${p.image_token}`) : null;
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

    function matches() {
        const term = q.value.trim().toLowerCase();
        const inGroup = GROUP_FN[group] || GROUP_FN.all;
        const list = products.filter((p) => inGroup(p)
            && (!term || p.name.toLowerCase().includes(term) || (p.barcode || '').includes(term)));
        const fn = SORT_FN[sort] || SORT_FN.name;
        // Out-of-stock products (shown only when the admin allows selling them) go last.
        return list.sort((a, b) => (a.qty_stock < 1) - (b.qty_stock < 1) || fn(a, b) || a.name.localeCompare(b.name, 'th'));
    }

    function inCart(id) {
        return cart.find((c) => c.id === id)?.qty || 0;
    }

    function drawProducts() {
        root.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
        root.querySelectorAll('[data-group]').forEach((b) => b.classList.toggle('active', b.dataset.group === group));
        root.querySelector('[data-act="clear-q"]').hidden = !q.value;
        const list = matches();
        const box = el('products');
        if (!list.length) {
            const term = q.value.trim();
            box.innerHTML = `<div class="empty">${term ? 'ไม่พบสินค้าที่ค้นหา' : group !== 'all' ? 'ไม่มีสินค้าในกลุ่มนี้' : 'ไม่มีสินค้าคงเหลือ'}</div>`;
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
        box.innerHTML = view === 'grid'
            ? `<div class="pos-grid">${list.map((p) => {
                const n = inCart(p.id);
                return `<button type="button" class="pos-tile${n ? ' picked' : ''}${p.qty_stock < 1 ? ' out' : ''}" data-pick="${p.id}">
                  <span class="pos-tile-img">${photoImg(p)}</span>
                  <span class="pos-tile-name">${escapeHtml(p.name)}</span>
                  ${p.consignor_id != null ? `<span class="pos-tile-consignor">ผู้ฝาก: ${escapeHtml(p.consignor_name)}</span>` : ''}
                  <span class="pos-tile-meta"><b>฿${money(p.sale_price)}</b><span>เหลือ ${p.qty_stock - n}</span></span>
                  ${n ? `<span class="pos-tile-badge">${n}</span>` : ''}
                </button>`;
            }).join('')}</div>`
            : `<div class="pos-list">${list.map((p) => {
                const n = inCart(p.id);
                return `<button type="button" class="pos-row${n ? ' picked' : ''}${p.qty_stock < 1 ? ' out' : ''}" data-pick="${p.id}">
                  <span class="pos-row-name">${escapeHtml(p.name)}<small>${escapeHtml(p.consignor_name)}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}</small></span>
                  <span class="pos-row-stock">เหลือ ${p.qty_stock - n}</span>
                  <b>฿${money(p.sale_price)}</b>
                  ${n ? `<span class="pos-tile-badge">${n}</span>` : ''}
                </button>`;
            }).join('')}</div>`;
    }

    function cartTotal() {
        return Math.round(cart.reduce((s, i) => s + i.sale_price * i.qty, 0) * 100) / 100;
    }

    function drawCart() {
        const qty = cart.reduce((s, i) => s + i.qty, 0);
        el('cart-title').textContent = `ตะกร้าสินค้า (${cart.length})`;
        el('cart-qty').textContent = qty ? `${qty} ชิ้น` : '';
        el('total').textContent = `฿ ${money(cartTotal())}`;
        root.querySelector('[data-act="pay"]').disabled = !cart.length;
        root.querySelector('[data-act="clear-cart"]').disabled = !cart.length;
        root.querySelector('[data-act="hold"]').disabled = !cart.length;
        saveJson(CART_KEY, cart);
        drawHeld();
        el('cart').innerHTML = cart.map((it, i) => `
          <div class="pos-line">
            <span class="pos-line-img">${photoImg(it)}</span>
            <div class="pos-line-name">${escapeHtml(it.name)}<small>฿${money(it.sale_price)} · ${escapeHtml(it.consignor_name)}</small></div>
            <div class="pos-qty">
              <button type="button" data-dec="${i}" aria-label="ลดจำนวน">−</button>
              <input data-qty="${i}" inputmode="numeric" value="${it.qty}" aria-label="จำนวน">
              <button type="button" data-inc="${i}" aria-label="เพิ่มจำนวน" ${it.qty >= it.qty_stock && !allowShort() ? 'disabled' : ''}>+</button>
            </div>
            <b class="pos-line-total">฿${money(it.sale_price * it.qty)}</b>
            <button type="button" class="pos-line-del" data-remove="${i}" aria-label="เอาออกจากตะกร้า">✕</button>
          </div>`).join('') || '<div class="empty">ยังไม่มีสินค้าในตะกร้า<br><small class="muted">สแกนบาร์โค้ด หรือแตะสินค้าเพื่อเพิ่ม</small></div>';
    }

    function drawHeld() {
        const box = el('held');
        box.hidden = !held.length;
        box.innerHTML = held.length ? `<span class="pos-held-label">ตะกร้าที่พักไว้</span>${held.map((h) => {
            const total = h.items.reduce((s, i) => s + i.sale_price * i.qty, 0);
            const pieces = h.items.reduce((s, i) => s + i.qty, 0);
            return `<span class="pos-held-chip">
              <button type="button" data-resume="${h.id}" title="${escapeHtml(h.items.map((i) => `${i.name} x${i.qty}`).join(', '))}">
                <b>#${h.no}</b> ${pieces} ชิ้น · ฿${money(total)}<small>พักเมื่อ ${escapeHtml(h.at)}</small></button>
              <button type="button" class="pos-held-del" data-drop="${h.id}" aria-label="ลบตะกร้าที่พัก #${h.no}">✕</button>
            </span>`;
        }).join('')}` : '';
    }

    /** Puts the current cart aside; returns its number. */
    function holdCart() {
        const no = held.reduce((m, h) => Math.max(m, h.no), 0) + 1;
        const at = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
        held.push({ id: Date.now(), no, at, items: cart });
        saveJson(HELD_KEY, held);
        cart = [];
        return no;
    }

    function resumeCart(id) {
        const h = held.find((x) => x.id === id);
        if (!h) return;
        // A cart in progress is held in its place, so nothing is lost by switching.
        const swapped = cart.length ? holdCart() : null;
        held = held.filter((x) => x !== h);
        cart = h.items.map((c) => ({ ...(products.find((p) => p.id === c.id) || c), qty: c.qty }));
        saveJson(HELD_KEY, held);
        message(`เปิดตะกร้าที่พัก #${h.no}${swapped ? ` (พักตะกร้าเดิมไว้เป็น #${swapped})` : ''}`);
        redraw();
    }

    /** Outside store hours: sales still go through but are booked on the next opening day. */
    function drawHours() {
        const d = options.business_day;
        const h = options.store_hours || {};
        const hours = h.open && h.close ? ` (เวลาทำการ ${h.open}–${h.close} น.)` : '';
        el('hours').innerHTML = d?.after_hours ? `
          <div class="pos-hours">🌙 <span><b>นอกเวลาทำการ${escapeHtml(hours)}</b>
            ${d.date !== options.today
                ? `รายการที่ขายตอนนี้จะลงบัญชีเป็นยอดขายวันที่ <b>${escapeHtml(fmtDate(d.date))}</b> (ไม่กระทบยอดสรุปของวันนี้)`
                : 'ยังไม่ถึงเวลาเปิดร้าน รายการที่ขายตอนนี้ลงบัญชีเป็นยอดขายวันนี้'}
            · บันทึกเวลาขายจริงไว้ตรวจสอบกับการนับสต๊อก</span></div>` : '';
    }

    function drawShortBar() {
        el('shortbar').innerHTML = allowShort() ? `
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
        if (!drawer || (!drawer.required && !drawer.open)) { box.innerHTML = ''; return; }
        if (drawer.open) {
            box.innerHTML = `<div class="pos-drawer">💰 <span>เงินตั้งต้นในลิ้นชัก <b>฿${money(drawer.opening_cash)}</b>
                · เงินสดจากการขาย <b>฿${money(drawer.cash_sales)}</b> · ควรมีในลิ้นชัก <b>฿${money(drawer.opening_cash + drawer.cash_sales)}</b></span>
              ${navigate ? `<button type="button" class="btn secondary sm" data-drawer-act="summary">${drawer.closed ? 'ดูสรุปยอด' : 'ปิดยอด / สรุปเงิน'}</button>` : ''}</div>`;
            return;
        }
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
        drawDrawerBar();
        drawShortBar();
        drawProducts();
        drawCart();
    }

    function add(p) {
        const line = cart.find((c) => c.id === p.id);
        const short = (line?.qty || 0) >= p.qty_stock;
        if (short && !allowShort()) {
            beep(false);
            message(`${p.name} มีคงเหลือเพียง ${p.qty_stock} ชิ้น`, 'error');
            return;
        }
        if (line) line.qty += 1;
        else cart.push({ ...p, qty: 1 });
        beep(true);
        message(short ? `${p.name}: คงเหลือในระบบ ${p.qty_stock} ชิ้น ขายได้ แต่สต๊อกจะติดลบ ควรตรวจนับ/บันทึกรับสินค้าภายหลัง` : '', 'warn');
        redraw();
        const idx = cart.findIndex((c) => c.id === p.id);
        el('cart').children[idx]?.scrollIntoView({ block: 'nearest' });
    }

    /** Enter in the search box: a scanned barcode, or the only product matching the text. */
    async function submitSearch() {
        const code = q.value.trim();
        if (!code) return;
        let p = products.find((x) => x.barcode && x.barcode === code);
        if (!p) {
            const list = matches();
            if (list.length === 1) p = list[0];
        }
        if (!p && /^\d{4,}$/.test(code)) {
            // Not in the loaded list (e.g. stock arrived after the page was opened).
            const res = await api.get(`/sales/products?q=${encodeURIComponent(code)}`).catch(() => ({ data: [] }));
            p = res.data.find((x) => x.barcode === code);
            if (p && !products.some((x) => x.id === p.id)) products.push(p);
        }
        if (!p) {
            beep(false);
            message(/^\d{4,}$/.test(code) ? `ไม่พบสินค้าบาร์โค้ด ${code} (หรือสินค้าหมด)` : `พบสินค้าหลายรายการ แตะเลือกสินค้าที่ต้องการ`, 'error');
            if (/^\d{4,}$/.test(code)) q.select();
            return;
        }
        q.value = '';
        add(p);
    }

    async function loadProducts() {
        try {
            const res = await api.get(`/sales/products?all=1&sort=popular${allowShort() ? '&short=1' : ''}`);
            products = res.data.map((p) => ({ ...p, sale_price: Number(p.sale_price), qty_stock: Number(p.qty_stock), sold_qty: Number(p.sold_qty) }));
        } catch (e) {
            message(e.message, 'error');
            redraw();
            return;
        }
        // Keep cart lines in step with the new prices and stock figures.
        const gone = [];
        cart = cart.filter((c) => products.some((p) => p.id === c.id) || !gone.push(c.name)).map((c) => {
            const p = products.find((x) => x.id === c.id);
            return { ...p, qty: allowShort() ? c.qty : Math.min(c.qty, p.qty_stock) };
        });
        if (gone.length) message(`เอาออกจากตะกร้าเพราะสินค้าหมด: ${gone.join(', ')}`, 'warn');
        redraw();
    }

    // ---------- Payment ----------

    function openPayment() {
        if (!cart.length) return;
        const total = cartTotal();
        const methods = options.payment_methods;
        const mixed = options.payment_mixed;
        const first = methods.find((m) => m.key === 'cash') || methods[0];
        let amounts = { [first.key]: total };
        let selected = [first.key];
        let busy = false;
        let error = null;
        let done = null;
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
                modal.innerHTML = `
                  <div class="modal pos-modal" role="dialog" aria-modal="true">
                    <div class="modal-body pos-done">
                      <div class="pos-done-icon">✓</div>
                      <h2>บันทึกการขายแล้ว</h2>
                      <div class="muted">เลขที่ ${escapeHtml(done.doc_no)} · ยอดขาย ฿${money(done.total_value)}</div>
                      ${done.after_hours ? `<div class="pos-hours-note">ขายนอกเวลาทำการ · ลงบัญชีวันที่ ${escapeHtml(fmtDate(done.business_date))}</div>` : ''}
                      ${done.change > 0 ? `<div class="pos-change">เงินทอน <b>฿${money(done.change)}</b></div>` : ''}
                      <button type="button" class="btn pos-big" data-pay="next">ขายรายการถัดไป</button>
                    </div>
                  </div>`;
                modal.querySelector('[data-pay="next"]').focus();
                return;
            }
            const f = figures();
            modal.innerHTML = `
              <div class="modal pos-modal" role="dialog" aria-modal="true" aria-label="ชำระเงิน">
                <div class="modal-head"><h2>ชำระเงิน</h2><button type="button" class="modal-close" data-pay="close" aria-label="ปิด">✕</button></div>
                <div class="modal-body">
                  <div class="pos-due"><span>ยอดที่ต้องชำระ</span><b>฿${money(total)}</b></div>
                  <div class="pos-methods">
                    ${methods.map((m) => `<button type="button" class="pos-method${selected.includes(m.key) ? ' active' : ''}" data-method="${m.key}">
                      <span>${METHOD_ICON[m.key] || ''}</span>${escapeHtml(m.label)}</button>`).join('')}
                  </div>
                  ${mixed && methods.length > 1 ? `
                  <label class="pos-switch"><input type="checkbox" data-mix ${mixMode ? 'checked' : ''}><span aria-hidden="true"></span>
                    ชำระแบบผสม <small class="muted">${mixMode ? 'แตะเลือกหลายวิธี แล้วใส่จำนวนเงินของแต่ละวิธี' : '(เช่น เงินสดบางส่วน + โอนส่วนที่เหลือ)'}</small></label>` : ''}
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
                <div class="modal-foot">
                  <button type="button" class="btn secondary pos-big" data-pay="close">ยกเลิก</button>
                  <button type="button" class="btn pos-big" data-pay="confirm" ${f.ok && !busy ? '' : 'disabled'}>${busy ? 'กำลังบันทึก...' : 'ยืนยันการชำระเงิน'}</button>
                </div>
              </div>`;
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
            modal.remove();
            modal = null;
            if (done) {
                cart = [];
                message(`บันทึกการขาย ${done.doc_no} แล้ว`);
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
                    items: cart.map((i) => ({ product_id: i.id, qty: i.qty })),
                    allow_short: allowShort(),
                    payments: selected.map((k) => ({ method: k, amount: Number(amounts[k]) || 0 })),
                });
                done = res.data;
                refreshDrawer();
            } catch (e) {
                error = e.message;
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
            const t = e.target.closest('button');
            if (e.target === modal && !done) return close();
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

    on(q, 'input', () => { message(''); drawProducts(); });
    on(q, 'keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); submitSearch(); }
        else if (e.key === 'Escape') { q.value = ''; drawProducts(); }
    });
    // Tapping the search box itself means the cashier wants to type.
    on(q, 'pointerdown', (e) => {
        if (e.pointerType === 'touch' && q.inputMode === 'none') {
            q.inputMode = 'text';
            q.blur();
            setTimeout(() => q.focus(), 0);
        }
    });
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

    on(el('sort'), 'change', (e) => {
        sort = e.target.value;
        savePref('sort', sort);
        drawProducts();
        focusSearch();
    });

    on(el('cart'), 'change', (e) => {
        const i = e.target.dataset.qty;
        if (i === undefined) return;
        const line = cart[Number(i)];
        const n = Math.floor(Number(e.target.value));
        if (!(n > 0)) cart.splice(Number(i), 1);
        else {
            if (n > line.qty_stock) {
                message(allowShort()
                    ? `${line.name}: คงเหลือในระบบ ${line.qty_stock} ชิ้น ขายได้ แต่สต๊อกจะติดลบ`
                    : `${line.name} มีคงเหลือเพียง ${line.qty_stock} ชิ้น`, allowShort() ? 'warn' : 'error');
            }
            line.qty = allowShort() ? n : Math.min(n, line.qty_stock);
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
            } else if (t.dataset.drawerAct === 'summary') {
                navigate('drawer');
            } else if (t.dataset.pick) {
                const p = products.find((x) => x.id === Number(t.dataset.pick));
                if (p) add(p);
            } else if (t.dataset.view) {
                view = t.dataset.view;
                savePref('view', view);
                drawProducts();
            } else if (t.dataset.group) {
                group = t.dataset.group;
                savePref('group', group);
                drawProducts();
            } else if (t.dataset.inc) {
                const line = cart[Number(t.dataset.inc)];
                if (line) add(line);
            } else if (t.dataset.dec) {
                const i = Number(t.dataset.dec);
                if (cart[i].qty > 1) cart[i].qty -= 1;
                else cart.splice(i, 1);
                redraw();
            } else if (t.dataset.remove) {
                cart.splice(Number(t.dataset.remove), 1);
                redraw();
            } else if (t.dataset.act === 'clear-q') {
                q.value = '';
                drawProducts();
            } else if (t.dataset.act === 'hold') {
                if (cart.length) {
                    const no = holdCart();
                    message(`พักตะกร้าไว้เป็น #${no} แล้ว เริ่มรายการขายใหม่ได้`);
                    redraw();
                }
            } else if (t.dataset.resume) {
                resumeCart(Number(t.dataset.resume));
            } else if (t.dataset.drop) {
                const h = held.find((x) => x.id === Number(t.dataset.drop));
                const pieces = h ? h.items.reduce((s, i) => s + i.qty, 0) : 0;
                if (h) confirmDialog(`สินค้า ${pieces} ชิ้นในตะกร้านี้จะถูกนำออกทั้งหมด`, { title: `ลบตะกร้าที่พัก #${h.no}?`, okText: 'ลบตะกร้า', danger: true }).then((yes) => {
                    if (!yes) return;
                    held = held.filter((x) => x !== h);
                    saveJson(HELD_KEY, held);
                    drawHeld();
                });
            } else if (t.dataset.act === 'clear-cart') {
                if (cart.length) confirmDialog(`สินค้า ${cart.length} รายการในตะกร้าจะถูกนำออกทั้งหมด`, { title: 'ล้างตะกร้าสินค้า?', okText: 'ล้างตะกร้า', danger: true }).then((yes) => {
                    if (yes) { cart = []; redraw(); }
                });
            } else if (t.dataset.act === 'pay') {
                openPayment();
                return;
            } else if (t.dataset.act === 'full') {
                setFull(!root.classList.contains('pos-full'));
            } else if (t.dataset.act === 'kb') {
                keyboard = !keyboard;
                savePref('kb', keyboard ? '1' : '0');
                setKeyboard();
                q.blur();
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
        life.abort();
    });

    setKeyboard();
    drawCart();
    focusSearch();
    const loadOptions = () => api.get('/sales/options').then((o) => {
        if (o.payment_methods?.length) options = o;
        if ('drawer' in o) setDrawer(o.drawer);
    }).catch(() => {});
    await loadOptions();
    await loadProducts();
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
