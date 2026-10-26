import { api } from '../api.js';
import { printConsignSlip } from '../print-slip.js';
import { money, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

export async function render(container, { permissions = [], user } = {}) {
    const isAdmin = user?.role === 'admin';
    const canBackdate = permissions.includes('receive.backdate');
    const options = (await api.get('/consignors/options')).data;
    let receiveDate = todayISO();
    // No consignor until one is chosen, so goods are never filed under someone by accident.
    let consignorId = null;
    let formOpen = false; // the receive form modal
    let cart = [];
    let quote = null;
    let error = null;
    let success = null;
    let requests = []; // consignors' pending requests
    let loaded = null; // the request being checked, if any
    let quoteSeq = 0; // ignore quote replies that arrive after a newer keystroke

    async function loadRequests() {
        try {
            requests = (await api.get('/receive/requests')).data.map((r) => ({ ...r, id: Number(r.id) }));
        } catch (e) {
            requests = []; // e.g. migration not run yet
        }
    }

    function requestsCard() {
        if (!requests.length) return '';
        return `
        <section class="card">
          <div class="card-head"><h2>คำขอฝากจากผู้ฝากขาย <span class="pill warn">${requests.length} รอตรวจรับ</span></h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>ผู้ฝากขาย</th><th>รายการ</th><th>วันที่ฝาก</th><th>แจ้งเมื่อ</th><th></th></tr></thead>
            <tbody>${requests.map((r) => `
              <tr>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(r.request_no)}</td>
                <td>${escapeHtml(r.consignor_code)} - ${escapeHtml(r.consignor_name)}
                  ${r.note ? `<div class="muted" style="font-size:12px">หมายเหตุ: ${escapeHtml(r.note)}</div>` : ''}</td>
                <td style="font-size:12.5px">${r.items.map((i) => `${escapeHtml(i.name)} × ${Number(i.qty)}`).join('<br>')}</td>
                <td style="font-size:12.5px;white-space:nowrap">${r.deposit_date ? `${fmtDate(r.deposit_date)}${r.deposit_date > todayISO() ? ' <span class="pill muted">ล่วงหน้า</span>' : ''}` : '<span class="muted">-</span>'}</td>
                <td class="muted" style="font-size:12.5px">${fmtDateTime(r.created_at)}</td>
                <td style="text-align:right;white-space:nowrap">
                  ${loaded?.id === r.id ? '<span class="pill ok">กำลังตรวจรับ</span>'
                      : `<button type="button" class="btn sm" data-load="${r.id}">ตรวจรับ</button>`}
                  <button type="button" class="btn sm secondary" data-print="${r.id}" title="พิมพ์ใบฝากขายไว้ตรวจนับ">พิมพ์</button>
                  <button type="button" class="btn sm secondary" data-reject="${r.id}">ไม่รับฝาก</button>
                </td>
              </tr>`).join('')}
            </tbody>
          </table></div>
        </section>`;
    }

    function draw() {
        const total = cart.reduce((s, i) => s + i.price * i.qty, 0);

        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="actions-row" style="justify-content:space-between">
          <div class="hint" style="flex:1;margin:0"><div class="dot">i</div><div>กด <b>รับฝาก</b> เพื่อบันทึกสินค้าที่ผู้ฝากนำมา หรือกด <b>ตรวจรับ</b> ที่คำขอฝากจากผู้ฝากขาย</div></div>
          <button type="button" class="btn" id="btn-open" style="font-size:15px;padding:12px 22px">+ รับฝาก${cart.length ? ` (ค้าง ${cart.length} รายการ)` : ''}</button>
        </div>
        ${requestsCard()}
        <div id="rc-docs"></div>
        ${formOpen ? formHtml(total) : ''}`;

        wire();
        drawDocs();
    }

    /** The receive form, on a modal. */
    function formHtml(total) {
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rc-title" style="width:1100px">
            <div class="modal-head">
              <h2 id="rc-title">${loaded ? `ตรวจรับคำขอฝาก ${escapeHtml(loaded.request_no)}` : 'รับฝากสินค้า'}</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
          <div class="rc-form">
            <section class="card card-pad">
              <div class="step-title"><span class="step-num">1</span><h2>ผู้ฝากขาย</h2></div>
              ${loaded ? `<div class="pill warn" style="margin-bottom:10px;display:inline-flex;gap:8px;align-items:center">
                  ตรวจรับคำขอ ${escapeHtml(loaded.request_no)} — ตรวจนับของจริงแล้วแก้ราคา/จำนวนในตารางให้ตรง
                  <button type="button" class="btn sm secondary" id="btn-unload">เลิกตรวจรับ</button></div>` : ''}
              <div class="rc-row">
              <label class="field rc-grow">เลือกผู้ฝากขาย *
                <select id="sel-consignor" ${loaded ? 'disabled' : ''} required>
                  <option value="" ${consignorId == null ? 'selected' : ''} disabled>— เลือกผู้ฝากขาย —</option>
                  ${options.map((o) => `<option value="${o.id}" ${Number(o.id) === Number(consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
              ${canBackdate ? `
                <label class="field">วันที่รับฝาก
                  <input type="date" id="f-receive-date" value="${receiveDate}" max="${todayISO()}">
                </label>` : ''}
              </div>
              ${canBackdate ? `
                <div class="pill warn" id="backdate-note" style="margin-top:8px;display:${receiveDate < todayISO() ? 'inline-flex' : 'none'}">บันทึกย้อนหลังเป็นวันที่ ${escapeHtml(receiveDate)}</div>` : ''}
            </section>

            <section class="card card-pad">
              <div class="step-title"><span class="step-num">2</span><h2>เพิ่มรายการสินค้า</h2></div>
              ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
              <div class="form-grid rc-grid">
                <div class="field rc-name"><label for="f-name">ชื่อสินค้า</label>
                  <div class="ac">
                    <input id="f-name" placeholder="พิมพ์ค้นหาจากรายการฝากเดิม หรือชื่อสินค้าใหม่" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="f-name-list">
                    <div class="ac-list" id="f-name-list" role="listbox" hidden></div>
                  </div>
                </div>
                <label class="field">ราคาที่ผู้ฝากต้องการได้ (บาท) * <input id="f-cost" type="number" step="0.25" min="0"></label>
                <div class="field">ราคาขายหน้าร้าน <div id="f-price" class="price-preview">-</div></div>
                <label class="field">จำนวนที่ฝาก (ชิ้น) * <input id="f-qty" type="number" min="1" value="1"></label>
                <label class="field">วันที่ผลิต * <input id="f-mfg" type="date" value="${todayISO()}" max="${todayISO()}" required></label>
                <label class="field">วันหมดอายุ (ถ้ามี) <input id="f-expiry" type="date"></label>
                <label class="field">บาร์โค้ด (ถ้ามี) <input id="f-barcode"></label>
              </div>
              <div class="rc-add">
                <div id="quote-box" class="muted" style="font-size:13px"></div>
                <div class="actions-row">
                <button type="button" class="btn secondary" id="btn-clear">ล้างช่องกรอก</button>
                <button type="button" class="btn" id="btn-add">เพิ่มลงใบรับฝาก</button>
                </div>
              </div>
            </section>

            <section class="card">
              <div class="card-head"><h2>รายการในใบรับฝาก (${cart.length})</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคาผู้ฝาก</th><th class="num">ราคาขาย</th><th class="num">จำนวน</th><th>วันที่ผลิต</th><th></th></tr></thead>
                <tbody>
                  ${cart.map((it, i) => `
                    <tr><td>${escapeHtml(it.name)}</td>
                        <td class="num"><input type="number" step="0.25" min="0" value="${it.cost_price}" data-cost="${i}" style="width:90px;text-align:right"></td>
                        <td class="num">${money(it.price)}</td>
                        <td class="num"><input type="number" min="1" value="${it.qty}" data-qty="${i}" style="width:70px;text-align:right">
                          ${it.req_qty != null && it.req_qty !== it.qty ? `<div class="muted" style="font-size:11.5px">แจ้งไว้ ${it.req_qty}</div>` : ''}</td>
                        <td><input type="date" value="${it.mfg_date || ''}" max="${todayISO()}" data-mfg="${i}" required></td>
                        <td style="text-align:right"><button type="button" class="btn secondary sm" data-remove="${i}">ลบ</button></td></tr>
                  `).join('') || `<tr><td colspan="6" class="empty">ยังไม่มีรายการ</td></tr>`}
                </tbody>
              </table></div>
            </section>
          </div>
            </div>
            <div class="modal-foot rc-foot">
              <div class="rc-sum">
                <span><span class="muted">รายการ</span> <b>${cart.length}</b></span>
                <span><span class="muted">ชิ้นรวม</span> <b>${cart.reduce((s, i) => s + i.qty, 0)}</b></span>
                <span><span class="muted">มูลค่ารวม (ราคาขาย)</span> <b>฿ ${money(total)}</b></span>
                ${!consignorId ? '<span class="muted" style="font-size:12.5px">เลือกผู้ฝากขายก่อนบันทึก</span>' : ''}
              </div>
              <button type="button" class="btn" id="btn-submit" ${cart.length && consignorId ? '' : 'disabled'}>${loaded ? 'ยืนยันและบันทึกการรับฝาก' : 'บันทึกการรับฝาก'}</button>
            </div>
          </div>
        </div>`;
    }

    function wire() {
        container.querySelector('#btn-open').addEventListener('click', () => {
            formOpen = true;
            success = null;
            draw();
            container.querySelector(consignorId ? '#f-name' : '#sel-consignor')?.focus();
        });
        container.querySelectorAll('[data-load]').forEach((btn) => btn.addEventListener('click', () => loadRequest(Number(btn.dataset.load))));
        container.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', async () => {
            const r = requests.find((x) => x.id === Number(btn.dataset.print));
            btn.disabled = true;
            try {
                await printConsignSlip(r, async (cost) => (await api.post('/receive/quote', { cost_price: cost })).data.price);
            } catch (e) {
                alertDialog(e.message, { tone: 'error' });
            }
            btn.disabled = false;
        }));
        container.querySelectorAll('[data-reject]').forEach((btn) => btn.addEventListener('click', () => rejectRequest(Number(btn.dataset.reject))));
        if (!formOpen) return;
        // Closing keeps what was entered (the «รับฝาก» button shows it is waiting).
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', () => { formOpen = false; error = null; draw(); }));
        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            const next = Number(e.target.value) || null;
            if (cart.length && next !== consignorId) {
                // Lines typed for one consignor are not moved to another one silently.
                confirmDialog('รายการที่เพิ่มไว้จะยังอยู่ในใบรับฝาก และจะบันทึกเป็นของผู้ฝากที่เลือกใหม่', { title: 'เปลี่ยนผู้ฝากขาย?', okText: 'เปลี่ยนผู้ฝาก' }).then((ok) => {
                    if (ok) consignorId = next;
                    draw();
                });
                return;
            }
            consignorId = next;
            draw();
            container.querySelector('#f-name')?.focus();
        });
        container.querySelector('#f-receive-date')?.addEventListener('change', (e) => {
            const v = e.target.value;
            receiveDate = v && v <= todayISO() ? v : todayISO();
            e.target.value = receiveDate;
            const note = container.querySelector('#backdate-note');
            note.style.display = receiveDate < todayISO() ? 'inline-flex' : 'none';
            note.textContent = `บันทึกย้อนหลังเป็นวันที่ ${receiveDate}`;
        });
        container.querySelector('#f-cost').addEventListener('input', updateQuote);
        wireNameSearch();
        container.querySelector('#btn-clear').addEventListener('click', () => { draw(); });
        container.querySelector('#btn-add').addEventListener('click', addItem);
        container.querySelector('#btn-unload')?.addEventListener('click', () => {
            loaded = null;
            cart = [];
            draw();
        });
        container.querySelectorAll('[data-remove]').forEach((btn) => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            draw();
        }));
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('change', () => {
            const qty = Math.floor(Number(inp.value));
            if (qty > 0) cart[Number(inp.dataset.qty)].qty = qty;
            draw();
        }));
        container.querySelectorAll('[data-mfg]').forEach((inp) => inp.addEventListener('change', () => {
            cart[Number(inp.dataset.mfg)].mfg_date = inp.value || null;
        }));
        container.querySelectorAll('[data-cost]').forEach((inp) => inp.addEventListener('change', async () => {
            const cost = Number(inp.value);
            const it = cart[Number(inp.dataset.cost)];
            if (cost > 0) {
                it.cost_price = cost;
                it.price = (await api.post('/receive/quote', { cost_price: cost })).data.price;
            }
            draw();
        }));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
    }

    /**
     * Product name box: lists what this consignor has deposited before; picking one refills
     * the name, price and barcode. A name not in the list is added as a new product.
     */
    function wireNameSearch() {
        const input = container.querySelector('#f-name');
        const list = container.querySelector('#f-name-list');
        let items = []; // history rows shown, then the "new product" choice (null)
        let active = -1;
        let timer;
        let seq = 0;

        const close = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            active = -1;
        };
        const show = (rows) => {
            const term = input.value.trim();
            const exact = rows.some((r) => r.name.trim().toLowerCase() === term.toLowerCase());
            items = term && !exact ? [...rows, null] : rows;
            active = -1;
            list.innerHTML = (rows.length ? '' : `<div class="ac-note">${term ? 'ไม่พบในรายการฝากเดิมของผู้ฝากนี้' : 'ผู้ฝากนี้ยังไม่มีรายการฝากเดิม'}</div>`)
                + items.map((r, i) => r
                    ? `<div class="ac-item" role="option" data-i="${i}">
                         <span>${escapeHtml(r.name)}</span>
                         <small>ราคาผู้ฝาก ฿${money(r.cost_price)} · ขาย ฿${money(r.sale_price)}${r.barcode ? ` · ${escapeHtml(r.barcode)}` : ''} · ฝากล่าสุด ${fmtDate(r.last_at)}${Number(r.lots) > 1 ? ` (${Number(r.lots)} ครั้ง)` : ''}</small>
                       </div>`
                    : `<div class="ac-item ac-new" role="option" data-i="${i}">+ เพิ่มเป็นสินค้าใหม่: <b>${escapeHtml(term)}</b></div>`).join('');
            list.hidden = !list.innerHTML;
            input.setAttribute('aria-expanded', String(!list.hidden));
        };
        const search = async () => {
            const s = ++seq;
            const term = input.value.trim();
            if (!consignorId) {
                list.innerHTML = '<div class="ac-note">เลือกผู้ฝากขายก่อน เพื่อค้นหาจากรายการฝากเดิม</div>';
                list.hidden = false;
                return;
            }
            const res = await api.get(`/receive/history?consignor_id=${encodeURIComponent(consignorId ?? '')}&q=${encodeURIComponent(term)}`).catch(() => ({ data: [] }));
            if (s !== seq || document.activeElement !== input) return;
            show(res.data);
        };
        const pick = (i) => {
            const r = items[i];
            close();
            if (r) {
                input.value = r.name;
                container.querySelector('#f-cost').value = Number(r.cost_price);
                container.querySelector('#f-barcode').value = r.barcode || '';
                updateQuote();
                container.querySelector('#f-qty').focus();
                container.querySelector('#f-qty').select();
            } else {
                container.querySelector('#f-cost').focus();
            }
        };
        const highlight = () => list.querySelectorAll('.ac-item').forEach((el, i) => el.classList.toggle('active', i === active));

        input.addEventListener('focus', search);
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(search, 200);
        });
        input.addEventListener('blur', () => setTimeout(close, 150));
        input.addEventListener('keydown', (e) => {
            if (list.hidden) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const n = items.length;
                if (!n) return;
                active = e.key === 'ArrowDown' ? (active + 1) % n : (active - 1 + n) % n;
                highlight();
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                pick(active);
            } else if (e.key === 'Escape') {
                close();
            }
        });
        list.addEventListener('mousedown', (e) => {
            const el = e.target.closest('.ac-item');
            if (!el) return;
            e.preventDefault();
            pick(Number(el.dataset.i));
        });
    }

    async function loadRequest(id) {
        const r = requests.find((x) => x.id === id);
        if (!r) return;
        if (cart.length && !(await confirmDialog('รายการในใบรับฝากปัจจุบันจะถูกล้าง แล้วโหลดคำขอนี้แทน', { title: 'โหลดคำขอฝากนี้?', okText: 'โหลดคำขอ' }))) return;
        if (!options.some((o) => Number(o.id) === Number(r.consignor_id))) {
            error = `ผู้ฝากขาย ${r.consignor_code} ยังไม่อยู่ในสถานะใช้งาน — เปิดใช้งานในทะเบียนผู้ฝากขายก่อน`;
            draw();
            return;
        }
        const prices = await Promise.all(r.items.map((i) => api.post('/receive/quote', { cost_price: Number(i.cost_price) })));
        loaded = r;
        consignorId = Number(r.consignor_id);
        formOpen = true;
        cart = r.items.map((i, k) => ({
            name: i.name, cost_price: Number(i.cost_price), qty: Number(i.qty), req_qty: Number(i.qty),
            barcode: i.barcode || null, mfg_date: i.mfg_date || todayISO(), expiry_date: i.expiry_date || null,
            price: prices[k].data.price,
        }));
        error = null;
        success = null;
        draw();
    }

    async function rejectRequest(id) {
        const r = requests.find((x) => x.id === id);
        const note = await promptDialog('ผู้ฝากจะเห็นข้อความนี้', { title: `ไม่รับฝาก ${r?.request_no || ''}`, okText: 'บันทึกไม่รับฝาก', placeholder: 'เหตุผลที่ไม่รับฝาก', multiline: true, danger: true });
        if (note === null) return;
        try {
            await api.post(`/receive/requests/${id}/reject`, { note });
            if (loaded?.id === id) { loaded = null; cart = []; }
            success = `บันทึกไม่รับฝาก ${r?.request_no || ''} แล้ว`;
            error = null;
        } catch (e) {
            error = e.message;
        }
        await loadRequests();
        draw();
    }

    async function updateQuote() {
        const cost = Number(container.querySelector('#f-cost').value);
        const box = container.querySelector('#quote-box');
        const priceEl = container.querySelector('#f-price');
        const seq = ++quoteSeq;
        if (!cost) { box.textContent = ''; priceEl.textContent = '-'; return; }
        const res = await api.post('/receive/quote', { cost_price: cost });
        if (seq !== quoteSeq) return;
        quote = res.data;
        priceEl.textContent = `฿ ${money(quote.price)}`;
        box.innerHTML = `ระบบคิดราคาขายให้อัตโนมัติ: ต้นทุน ฿${money(cost)} + ค่าฝากขาย ฿${money(quote.fee)} = ฿${money(quote.raw)} → ปัดเป็น <b>฿${money(quote.price)}</b>`;
    }

    async function addItem() {
        error = null;
        if (!consignorId) {
            error = 'กรุณาเลือกผู้ฝากขายก่อน';
            draw();
            container.querySelector('#sel-consignor')?.focus();
            return;
        }
        const name = container.querySelector('#f-name').value.trim();
        const cost = Number(container.querySelector('#f-cost').value);
        const qty = Number(container.querySelector('#f-qty').value);
        const barcode = container.querySelector('#f-barcode').value.trim();
        const expiry = container.querySelector('#f-expiry').value;
        const mfg = container.querySelector('#f-mfg').value;
        if (!name || !cost || !qty || !mfg) {
            error = 'กรุณากรอกชื่อสินค้า ราคา จำนวน และวันที่ผลิตให้ครบ';
            draw();
            return;
        }
        if (mfg > todayISO() || (expiry && expiry < mfg)) {
            error = mfg > todayISO() ? 'วันที่ผลิตต้องไม่เกินวันนี้' : 'วันหมดอายุต้องไม่ก่อนวันที่ผลิต';
            draw();
            return;
        }
        const res = await api.post('/receive/quote', { cost_price: cost });
        cart.push({ name, cost_price: cost, qty, barcode: barcode || null, mfg_date: mfg, expiry_date: expiry || null, price: res.data.price });
        draw();
    }

    async function submit() {
        if (!consignorId || !cart.length) return;
        try {
            const result = await api.post('/receive', {
                consignor_id: consignorId,
                request_id: loaded?.id ?? null,
                receive_date: canBackdate ? receiveDate : null,
                items: cart.map((i) => ({ name: i.name, cost_price: i.cost_price, qty: i.qty, barcode: i.barcode, mfg_date: i.mfg_date, expiry_date: i.expiry_date })),
            });
            success = `บันทึกใบรับฝากสำเร็จ: ${result.data.doc_no}${loaded ? ` (จากคำขอ ${loaded.request_no})` : ''}`;
            cart = [];
            loaded = null;
            error = null;
            formOpen = false;
            consignorId = null; // the next one starts with no consignor chosen
            await loadRequests();
            draw();
            loadDocs();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    // ---------------- Receive documents already saved (admins may correct a line) ----------------

    let docs = [];
    let docsQ = '';
    let docsTimer;
    let docModal = null; // { el, doc, edit: { item id, fields } | null, error, busy }

    async function loadDocs() {
        try {
            docs = (await api.get(`/receive/docs?q=${encodeURIComponent(docsQ)}`)).data;
        } catch (e) {
            docs = [];
        }
        drawDocs();
    }

    function drawDocs() {
        const box = container.querySelector('#rc-docs');
        if (!box) return;
        const focused = document.activeElement?.id === 'rc-docs-q';
        box.innerHTML = `
        <section class="card">
          <div class="card-head" style="flex-wrap:wrap;gap:10px">
            <h2>รายการที่รับฝากไว้</h2>
            <input id="rc-docs-q" type="search" value="${escapeHtml(docsQ)}" placeholder="ค้นหาเลขที่ ผู้ฝาก หรือชื่อสินค้า" style="max-width:280px;flex:1">
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>วันที่รับฝาก</th><th>ผู้ฝากขาย</th><th>สินค้า</th><th class="num">ชิ้น</th><th class="num">มูลค่า (ราคาขาย)</th><th></th></tr></thead>
            <tbody>${docs.map((d) => `
              <tr${d.cancelled_at ? ' style="opacity:.6"' : ''}>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(d.doc_no)}${d.cancelled_at ? '<div><span class="pill muted" style="font-size:11px">ยกเลิกแล้ว</span></div>' : ''}</td>
                <td style="font-size:12.5px;white-space:nowrap">${fmtDateTime(d.created_at)}</td>
                <td>${escapeHtml(d.consignor_code)} - ${escapeHtml(d.consignor_name)}</td>
                <td style="font-size:12.5px;max-width:320px">${escapeHtml(d.names || '')}<div class="muted">${Number(d.items)} รายการ</div>${d.cancelled_at && d.cancel_reason ? `<div class="muted" style="font-size:11.5px">เหตุผลที่ยกเลิก: ${escapeHtml(d.cancel_reason)}</div>` : ''}</td>
                <td class="num">${Number(d.total_qty)}</td>
                <td class="num">${money(d.total_value)}</td>
                <td style="text-align:right"><button type="button" class="btn sm secondary" data-doc="${d.id}">${isAdmin && !d.cancelled_at ? 'ดู / แก้ไข' : 'ดู'}</button></td>
              </tr>`).join('') || `<tr><td colspan="7" class="empty">${docsQ ? 'ไม่พบใบรับฝากที่ค้นหา' : 'ยังไม่มีรายการรับฝาก'}</td></tr>`}
            </tbody>
          </table></div>
        </section>`;
        const q = box.querySelector('#rc-docs-q');
        if (focused) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
        q.addEventListener('input', () => {
            docsQ = q.value.trim();
            clearTimeout(docsTimer);
            docsTimer = setTimeout(loadDocs, 300);
        });
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => openDoc(Number(b.dataset.doc))));
    }

    async function openDoc(id) {
        try {
            const doc = (await api.get(`/receive/docs/${id}`)).data;
            docModal = { el: document.createElement('div'), doc, edit: null, error: null, busy: false };
            document.body.appendChild(docModal.el);
            drawDoc();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    function closeDoc() {
        docModal?.el.remove();
        docModal = null;
    }

    function drawDoc() {
        const m = docModal;
        const d = m.doc;
        const ed = m.edit;
        const canEdit = isAdmin && !d.cancelled_at;
        // Cancellable while every lot still holds exactly what was received.
        const canCancel = canEdit && d.items.every((it) => Number(it.qty_sold) === 0 && Number(it.qty_returned) === 0 && Number(it.qty_stock) === Number(it.qty));
        const fee = (it) => Number(it.sale_price) - Number(it.cost_price);
        // Prices as received; a later price change shows underneath (it applies to sales from then on).
        const now = (it, recv, cur) => Math.abs(Number(it[recv]) - Number(it[cur])) >= 0.005
            ? `<div class="pill warn" style="font-size:11px;margin-top:2px" title="ราคาที่ใช้ขายตอนนี้">ตอนนี้ ${money(it[cur])}</div>` : '';
        m.el.innerHTML = `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-label="ใบรับฝาก ${escapeHtml(d.doc_no)}" style="width:1100px">
            <div class="modal-head">
              <h2>ใบรับฝาก ${escapeHtml(d.doc_no)}${d.cancelled_at ? ' <span class="pill muted">ยกเลิกแล้ว</span>' : ''}</h2>
              <button type="button" class="modal-close" data-doc-close aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              ${d.cancelled_at ? `<div class="error-box" style="margin-bottom:12px">ยกเลิกเมื่อ ${fmtDateTime(d.cancelled_at)}${d.cancelled_by_name ? ` โดย ${escapeHtml(d.cancelled_by_name)}` : ''} · เหตุผล: ${escapeHtml(d.cancel_reason || '-')}</div>` : ''}
              <div class="stat-row" style="margin-bottom:14px;font-size:13.5px">
                <div><div class="muted">ผู้ฝากขาย</div><b>${escapeHtml(d.consignor_code)} - ${escapeHtml(d.consignor_name)}</b></div>
                <div><div class="muted">วันที่รับฝาก</div><b>${fmtDateTime(d.created_at)}</b><div class="muted">โดย ${escapeHtml(d.user_name || '-')}</div></div>
                <div><div class="muted">รวม</div><b>${Number(d.total_qty)} ชิ้น · ฿ ${money(d.total_value)}</b><div class="muted">ค่าทำข้อมูล ฿${money(d.listing_fee_total)}</div></div>
              </div>
              <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
                <thead><tr><th>สินค้า</th><th class="num">รับฝาก</th><th class="num">ขาย / คืน / คงเหลือ</th><th class="num">ผู้ฝากได้<div class="muted" style="font-weight:400">ตอนรับฝาก</div></th><th class="num">ราคาขาย<div class="muted" style="font-weight:400">ตอนรับฝาก</div></th><th class="num">ค่าฝาก</th><th>ผลิต / หมดอายุ</th><th></th></tr></thead>
                <tbody>${d.items.map((it) => `
                  <tr>
                    <td>${escapeHtml(it.name)}${it.barcode ? `<div class="muted" style="font-size:11.5px">บาร์โค้ด ${escapeHtml(it.barcode)}</div>` : ''}</td>
                    <td class="num">${Number(it.qty)}</td>
                    <td class="num">${Number(it.qty_sold)} / ${Number(it.qty_returned)} / <b>${Number(it.qty_stock)}</b></td>
                    <td class="num">${money(it.cost_price)}${now(it, 'cost_price', 'current_cost')}</td>
                    <td class="num">${money(it.sale_price)}${now(it, 'sale_price', 'current_price')}</td>
                    <td class="num">${money(fee(it))}</td>
                    <td style="font-size:12.5px;white-space:nowrap">${it.mfg_date ? fmtDate(it.mfg_date) : '-'}<div class="muted">${it.expiry_date ? fmtDate(it.expiry_date) : 'ไม่ระบุ'}</div></td>
                    <td style="text-align:right">${canEdit ? `<button type="button" class="btn sm secondary" data-item-edit="${it.id}" ${ed ? 'disabled' : ''}>แก้ไข</button>` : ''}</td>
                  </tr>
                  ${ed && ed.id === Number(it.id) ? `<tr><td colspan="8" style="background:var(--surface2)">${editHtml(it, ed)}</td></tr>` : ''}`).join('')}
                </tbody>
              </table></div>
              ${canEdit ? '<div class="muted" style="font-size:12px;margin-top:8px">ผู้ดูแลระบบแก้ไขรายการได้: จำนวนที่แก้จะปรับสต๊อกของล็อตตามส่วนต่าง · ปรับราคา (เช่น ลดราคา) มีผลกับการขายหลังบันทึกเท่านั้น ราคาตอนรับฝาก บิลที่ขายไปแล้ว และยอดในสมุดบัญชีผู้ฝากไม่เปลี่ยน · ค่าทำข้อมูลที่เก็บไปแล้วไม่เปลี่ยน</div>' : ''}
            </div>
            <div class="modal-foot">
              ${canEdit ? `<button type="button" class="btn danger" id="rc-doc-cancel" ${canCancel && !ed && !m.busy ? '' : 'disabled'} title="${canCancel ? 'ยกเลิกใบรับฝากทั้งใบ' : 'ยกเลิกไม่ได้: มีการขาย คืน หรือปรับยอดไปแล้ว'}">ยกเลิกการรับฝาก</button>` : '<span></span>'}
              <button type="button" class="btn secondary" data-doc-close>ปิด</button>
            </div>
          </div>
        </div>`;
        m.el.querySelectorAll('[data-doc-close]').forEach((b) => b.addEventListener('click', closeDoc));
        m.el.querySelector('#rc-doc-cancel')?.addEventListener('click', cancelDoc);
        m.el.querySelectorAll('[data-item-edit]').forEach((b) => b.addEventListener('click', () => {
            const it = d.items.find((x) => Number(x.id) === Number(b.dataset.itemEdit));
            m.edit = {
                id: Number(it.id), name: it.name, barcode: it.barcode || '', qty: String(it.qty),
                cost_price: String(Number(it.current_cost)), sale_price: String(Number(it.current_price)),
                mfg_date: it.mfg_date || '', expiry_date: it.expiry_date || '',
            };
            m.error = null;
            drawDoc();
            m.el.querySelector('[data-ef="name"]')?.focus();
        }));
        if (!ed) return;
        m.el.querySelectorAll('[data-ef]').forEach((inp) => inp.addEventListener('input', () => {
            ed[inp.dataset.ef] = inp.value;
            const hint = m.el.querySelector('#ef-fee');
            if (hint) hint.textContent = feeHint(ed);
        }));
        m.el.querySelector('#ef-calc').addEventListener('click', async () => {
            try {
                ed.sale_price = String((await api.post('/receive/quote', { cost_price: Number(ed.cost_price) || 0 })).data.price);
                m.el.querySelector('[data-ef="sale_price"]').value = ed.sale_price;
                m.el.querySelector('#ef-fee').textContent = feeHint(ed);
            } catch (e) {
                m.error = e.message;
                drawDoc();
            }
        });
        m.el.querySelector('#ef-cancel').addEventListener('click', () => { m.edit = null; m.error = null; drawDoc(); });
        m.el.querySelector('#ef-save').addEventListener('click', saveItem);
    }

    async function cancelDoc() {
        const m = docModal;
        const d = m.doc;
        const fee = Number(d.listing_fee_total);
        const reason = await promptDialog(
            `สินค้า ${d.items.length} รายการ (${Number(d.total_qty)} ชิ้น) ของ ${d.consignor_name} จะถูกตัดออกจากสต๊อก${fee > 0 ? ` และคืนค่าทำข้อมูล ฿${money(fee)} เข้าบัญชีผู้ฝาก` : ''} (ใบรับฝากเดิมยังอยู่ แสดงว่ายกเลิกแล้ว)`,
            { title: `ยกเลิกใบรับฝาก ${d.doc_no}?`, okText: 'ยกเลิกการรับฝาก', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
        );
        if (reason === null || reason === undefined || !String(reason).trim() || docModal !== m) return;
        m.busy = true;
        drawDoc();
        try {
            m.doc = (await api.post(`/receive/docs/${d.id}/cancel`, { reason: String(reason).trim() })).data;
            loadDocs();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        m.busy = false;
        drawDoc();
    }

    function feeHint(ed) {
        const cost = Number(ed.cost_price);
        const price = Number(ed.sale_price);
        if (ed.cost_price === '' || !(price > 0)) return 'ใส่ราคาให้ครบ';
        return cost > price ? '⚠️ ผู้ฝากได้มากกว่าราคาขาย' : `ร้านได้ค่าฝาก ฿${money(price - cost)}/ชิ้น`;
    }

    function editHtml(it, ed) {
        const m = docModal;
        const gone = Number(it.qty) - Number(it.qty_stock);
        return `
          <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
            <label class="field" style="grid-column:span 2">ชื่อสินค้า <input data-ef="name" value="${escapeHtml(ed.name)}" maxlength="191"></label>
            <label class="field">บาร์โค้ดชิ้น <input data-ef="barcode" value="${escapeHtml(ed.barcode)}"></label>
            <label class="field">จำนวนรับฝาก (ชิ้น) <input data-ef="qty" type="number" min="${Math.max(1, gone)}" value="${escapeHtml(ed.qty)}">
              ${gone > 0 ? `<span class="muted" style="font-size:11.5px;font-weight:400">ขาย/คืน/ปรับไปแล้ว ${gone} ชิ้น</span>` : ''}</label>
            <label class="field">ผู้ฝากได้/ชิ้น ตั้งแต่นี้ไป <input data-ef="cost_price" type="number" min="0" step="0.25" value="${escapeHtml(ed.cost_price)}"></label>
            <label class="field">ราคาขาย/ชิ้น ตั้งแต่นี้ไป <input data-ef="sale_price" type="number" min="0" step="0.25" value="${escapeHtml(ed.sale_price)}"></label>
            <label class="field">วันที่ผลิต <input data-ef="mfg_date" type="date" value="${escapeHtml(ed.mfg_date)}" max="${todayISO()}"></label>
            <label class="field">วันหมดอายุ <input data-ef="expiry_date" type="date" value="${escapeHtml(ed.expiry_date)}"></label>
          </div>
          <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-top:10px">
            <span class="muted" id="ef-fee" style="font-size:12.5px">${feeHint(ed)}</span>
            <button type="button" class="btn secondary sm" id="ef-calc">คำนวณราคาขายตามค่าฝากขาย</button>
            <span style="flex:1"></span>
            <button type="button" class="btn secondary" id="ef-cancel">ยกเลิก</button>
            <button type="button" class="btn" id="ef-save" ${m.busy ? 'disabled' : ''}>${m.busy ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข'}</button>
          </div>
          ${m.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(m.error)}</div>` : ''}`;
    }

    async function saveItem() {
        const m = docModal;
        const ed = m.edit;
        const cost = Number(ed.cost_price);
        const price = Number(ed.sale_price);
        if (!ed.name.trim()) m.error = 'กรุณากรอกชื่อสินค้า';
        else if (!(Number(ed.qty) >= 1)) m.error = 'จำนวนต้องอย่างน้อย 1 ชิ้น';
        else if (ed.cost_price === '' || !(price > 0) || cost < 0 || cost > price) m.error = 'ราคาขายต้องมากกว่า 0 และผู้ฝากได้ต้องอยู่ระหว่าง 0 ถึงราคาขาย';
        else if (ed.expiry_date && ed.mfg_date && ed.expiry_date < ed.mfg_date) m.error = 'วันหมดอายุต้องไม่ก่อนวันที่ผลิต';
        else m.error = null;
        if (m.error) { drawDoc(); return; }
        m.busy = true;
        drawDoc();
        try {
            m.doc = (await api.put(`/receive/items/${ed.id}`, {
                name: ed.name.trim(), barcode: ed.barcode.trim(), qty: Number(ed.qty), cost_price: cost, sale_price: price,
                mfg_date: ed.mfg_date, expiry_date: ed.expiry_date,
            })).data;
            m.edit = null;
            loadDocs();
        } catch (e) {
            m.error = e.message;
        }
        m.busy = false;
        drawDoc();
    }

    window.addEventListener('hashchange', closeDoc, { once: true });

    await loadRequests();
    draw();
    loadDocs();
}
