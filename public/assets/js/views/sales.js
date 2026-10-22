import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';

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

export async function render(container) {
    let products = [];
    let cart = [];
    let view = pref('view', 'grid');
    let sort = pref('sort', 'popular');
    let keyboard = !TOUCH || pref('kb', '0') === '1';
    let options = { payment_methods: [{ key: 'cash', label: 'เงินสด' }], payment_mixed: false };
    let modal = null; // the payment dialog element while open
    let msgTimer;

    const life = new AbortController();
    const on = (target, type, fn) => target.addEventListener(type, fn, { signal: life.signal });

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
          <button type="button" class="pos-tool" data-act="full" aria-label="เต็มหน้าจอ" title="เต็มหน้าจอ">⛶</button>
        </div>
        <div data-el="msg"></div>
        <div class="pos-main">
          <section class="pos-products card" data-el="products"><div class="empty">กำลังโหลดสินค้า...</div></section>
          <aside class="pos-cart card">
            <div class="pos-cart-head"><h2 data-el="cart-title">ตะกร้าสินค้า</h2><button type="button" class="btn secondary sm" data-act="clear-cart">ล้างตะกร้า</button></div>
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
        if (modal || !root.isConnected) return;
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
        return p.image_token ? api.downloadUrl(`/products/${p.id}/photo?v=${p.image_token}`) : null;
    }

    function matches() {
        const term = q.value.trim().toLowerCase();
        const list = term
            ? products.filter((p) => p.name.toLowerCase().includes(term) || (p.barcode || '').includes(term))
            : products.slice();
        const fn = SORT_FN[sort] || SORT_FN.name;
        return list.sort((a, b) => fn(a, b) || a.name.localeCompare(b.name, 'th'));
    }

    function inCart(id) {
        return cart.find((c) => c.id === id)?.qty || 0;
    }

    function drawProducts() {
        root.querySelectorAll('[data-view]').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
        root.querySelector('[data-act="clear-q"]').hidden = !q.value;
        const list = matches();
        const box = el('products');
        if (!list.length) {
            box.innerHTML = `<div class="empty">${q.value.trim() ? 'ไม่พบสินค้าที่ค้นหา' : 'ไม่มีสินค้าคงเหลือ'}</div>`;
            return;
        }
        box.innerHTML = view === 'grid'
            ? `<div class="pos-grid">${list.map((p) => {
                const img = photoUrl(p);
                const n = inCart(p.id);
                return `<button type="button" class="pos-tile${n ? ' picked' : ''}" data-pick="${p.id}">
                  <span class="pos-tile-img">${img ? `<img src="${escapeHtml(img)}" alt="" loading="lazy">` : '<span class="pos-nopic">no pic</span>'}</span>
                  <span class="pos-tile-name">${escapeHtml(p.name)}</span>
                  <span class="pos-tile-meta"><b>฿${money(p.sale_price)}</b><span>เหลือ ${p.qty_stock - n}</span></span>
                  ${n ? `<span class="pos-tile-badge">${n}</span>` : ''}
                </button>`;
            }).join('')}</div>`
            : `<div class="pos-list">${list.map((p) => {
                const n = inCart(p.id);
                return `<button type="button" class="pos-row${n ? ' picked' : ''}" data-pick="${p.id}">
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
        el('cart').innerHTML = cart.map((it, i) => `
          <div class="pos-line">
            <span class="pos-line-img">${photoUrl(it) ? `<img src="${escapeHtml(photoUrl(it))}" alt="">` : '<span class="pos-nopic">no pic</span>'}</span>
            <div class="pos-line-name">${escapeHtml(it.name)}<small>฿${money(it.sale_price)} · ${escapeHtml(it.consignor_name)}</small></div>
            <div class="pos-qty">
              <button type="button" data-dec="${i}" aria-label="ลดจำนวน">−</button>
              <input data-qty="${i}" inputmode="numeric" value="${it.qty}" aria-label="จำนวน">
              <button type="button" data-inc="${i}" aria-label="เพิ่มจำนวน" ${it.qty >= it.qty_stock ? 'disabled' : ''}>+</button>
            </div>
            <b class="pos-line-total">฿${money(it.sale_price * it.qty)}</b>
            <button type="button" class="pos-line-del" data-remove="${i}" aria-label="เอาออกจากตะกร้า">✕</button>
          </div>`).join('') || '<div class="empty">ยังไม่มีสินค้าในตะกร้า<br><small class="muted">สแกนบาร์โค้ด หรือแตะสินค้าเพื่อเพิ่ม</small></div>';
    }

    function redraw() {
        drawProducts();
        drawCart();
    }

    function add(p) {
        const line = cart.find((c) => c.id === p.id);
        if ((line?.qty || 0) >= p.qty_stock) {
            beep(false);
            message(`${p.name} มีคงเหลือเพียง ${p.qty_stock} ชิ้น`, 'error');
            return;
        }
        if (line) line.qty += 1;
        else cart.push({ ...p, qty: 1 });
        beep(true);
        message('');
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
            const res = await api.get('/sales/products?all=1&sort=popular');
            products = res.data.map((p) => ({ ...p, sale_price: Number(p.sale_price), qty_stock: Number(p.qty_stock), sold_qty: Number(p.sold_qty) }));
        } catch (e) {
            message(e.message, 'error');
        }
        // Keep cart lines in step with the new stock figures.
        cart = cart.filter((c) => products.some((p) => p.id === c.id)).map((c) => {
            const p = products.find((x) => x.id === c.id);
            return { ...p, qty: Math.min(c.qty, p.qty_stock) };
        });
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
                  ${mixed && methods.length > 1 ? '<p class="muted pos-hint">ชำระแบบผสมได้: แตะเลือกหลายวิธีแล้วใส่จำนวนเงินของแต่ละวิธี</p>' : ''}
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
        }

        async function confirm() {
            if (busy || !figures().ok) return;
            busy = true;
            error = null;
            draw();
            try {
                const res = await api.post('/sales', {
                    items: cart.map((i) => ({ product_id: i.id, qty: i.qty })),
                    payments: selected.map((k) => ({ method: k, amount: Number(amounts[k]) || 0 })),
                });
                done = res.data;
            } catch (e) {
                error = e.message;
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
                if (selected.includes(k)) {
                    selected = selected.filter((x) => x !== k);
                    delete amounts[k];
                } else {
                    if (!mixed) { selected = []; amounts = {}; }
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
            if (n > line.qty_stock) message(`${line.name} มีคงเหลือเพียง ${line.qty_stock} ชิ้น`, 'error');
            line.qty = Math.min(n, line.qty_stock);
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
            if (t.dataset.pick) {
                const p = products.find((x) => x.id === Number(t.dataset.pick));
                if (p) add(p);
            } else if (t.dataset.view) {
                view = t.dataset.view;
                savePref('view', view);
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
            } else if (t.dataset.act === 'clear-cart') {
                if (cart.length && confirm('ล้างสินค้าทั้งหมดในตะกร้า?')) { cart = []; redraw(); }
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
        if (modal || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
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
    api.get('/sales/options').then((o) => { if (o.payment_methods?.length) options = o; }).catch(() => {});
    await loadProducts();
    focusSearch();
}
