import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime, todayISO } from '../util.js';

export async function render(container) {
    const options = (await api.get('/consignors/options')).data;
    let consignorId = options[0]?.id ?? null;
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
            <thead><tr><th>เลขที่</th><th>ผู้ฝากขาย</th><th>รายการ</th><th>แจ้งเมื่อ</th><th></th></tr></thead>
            <tbody>${requests.map((r) => `
              <tr>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(r.request_no)}</td>
                <td>${escapeHtml(r.consignor_code)} - ${escapeHtml(r.consignor_name)}
                  ${r.note ? `<div class="muted" style="font-size:12px">หมายเหตุ: ${escapeHtml(r.note)}</div>` : ''}</td>
                <td style="font-size:12.5px">${r.items.map((i) => `${escapeHtml(i.name)} × ${Number(i.qty)}`).join('<br>')}</td>
                <td class="muted" style="font-size:12.5px">${fmtDateTime(r.created_at)}</td>
                <td style="text-align:right;white-space:nowrap">
                  ${loaded?.id === r.id ? '<span class="pill ok">กำลังตรวจรับ</span>'
                      : `<button type="button" class="btn sm" data-load="${r.id}">ตรวจรับ</button>`}
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
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            ${requestsCard()}
            <div class="hint"><div class="dot">i</div><div>ทำ 3 ขั้น: <b>1)</b> เลือกผู้ฝากขาย (หรือกด «ตรวจรับ» คำขอฝากด้านบน) <b>2)</b> กรอกสินค้าทีละรายการแล้วกดเพิ่ม — แก้ราคา/จำนวนในตารางให้ตรงกับของจริงได้ <b>3)</b> ตรวจยอดด้านขวาแล้วกดบันทึก</div></div>

            <section class="card card-pad">
              <div class="step-title"><span class="step-num">1</span><h2>ผู้ฝากขาย</h2></div>
              ${loaded ? `<div class="pill warn" style="margin-bottom:10px;display:inline-flex;gap:8px;align-items:center">
                  ตรวจรับคำขอ ${escapeHtml(loaded.request_no)} — ตรวจนับของจริงแล้วแก้ราคา/จำนวนในตารางให้ตรง
                  <button type="button" class="btn sm secondary" id="btn-unload">เลิกตรวจรับ</button></div>` : ''}
              <label class="field">เลือกผู้ฝากขาย
                <select id="sel-consignor" ${loaded ? 'disabled' : ''}>${options.map((o) => `<option value="${o.id}" ${Number(o.id) === Number(consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
            </section>

            <section class="card card-pad">
              <div class="step-title"><span class="step-num">2</span><h2>เพิ่มรายการสินค้า</h2></div>
              ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
              <div class="form-grid">
                <label class="field" style="grid-column:span 2">ชื่อสินค้า <input id="f-name" placeholder="เช่น ขนมทองม้วนสมุนไพร"></label>
                <label class="field">ราคาที่ผู้ฝากต้องการได้ (บาท) * <input id="f-cost" type="number" step="0.25" min="0"></label>
                <div class="field">ราคาขายหน้าร้าน <div id="f-price" class="price-preview">-</div></div>
                <label class="field">จำนวนที่ฝาก (ชิ้น) * <input id="f-qty" type="number" min="1" value="1"></label>
                <label class="field">วันที่ผลิต * <input id="f-mfg" type="date" value="${todayISO()}" max="${todayISO()}" required></label>
                <label class="field">วันหมดอายุ (ถ้ามี) <input id="f-expiry" type="date"></label>
                <label class="field">บาร์โค้ด (ถ้ามี) <input id="f-barcode"></label>
              </div>
              <div id="quote-box" class="muted" style="margin:12px 0;font-size:13px"></div>
              <div class="actions-row">
                <button type="button" class="btn secondary" id="btn-clear">ล้างช่องกรอก</button>
                <button type="button" class="btn" id="btn-add">เพิ่มลงใบรับฝาก</button>
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

          <section class="card card-pad">
            <h2 style="margin-bottom:12px">สรุปใบรับฝาก</h2>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13.5px;margin-bottom:16px">
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนรายการ</span><span>${cart.length}</span></div>
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนชิ้นรวม</span><span>${cart.reduce((s, i) => s + i.qty, 0)}</span></div>
              <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--border);padding-top:8px"><span>มูลค่ารวม (ราคาขาย)</span><span>฿ ${money(total)}</span></div>
            </div>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${cart.length ? '' : 'disabled'}>${loaded ? 'ยืนยันและบันทึกการรับฝาก' : 'บันทึกการรับฝาก'}</button>
          </section>
        </div>`;

        wire();
    }

    function wire() {
        container.querySelector('#sel-consignor').addEventListener('change', (e) => { consignorId = Number(e.target.value); });
        container.querySelector('#f-cost').addEventListener('input', updateQuote);
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
        container.querySelectorAll('[data-load]').forEach((btn) => btn.addEventListener('click', () => loadRequest(Number(btn.dataset.load))));
        container.querySelectorAll('[data-reject]').forEach((btn) => btn.addEventListener('click', () => rejectRequest(Number(btn.dataset.reject))));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
    }

    async function loadRequest(id) {
        const r = requests.find((x) => x.id === id);
        if (!r) return;
        if (cart.length && !confirm('ล้างรายการในใบรับฝากปัจจุบัน แล้วโหลดคำขอนี้แทน?')) return;
        if (!options.some((o) => Number(o.id) === Number(r.consignor_id))) {
            error = `ผู้ฝากขาย ${r.consignor_code} ยังไม่อยู่ในสถานะใช้งาน — เปิดใช้งานในทะเบียนผู้ฝากขายก่อน`;
            draw();
            return;
        }
        const prices = await Promise.all(r.items.map((i) => api.post('/receive/quote', { cost_price: Number(i.cost_price) })));
        loaded = r;
        consignorId = Number(r.consignor_id);
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
        const note = prompt(`เหตุผลที่ไม่รับฝาก ${r?.request_no || ''} (ผู้ฝากจะเห็นข้อความนี้)`);
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
                items: cart.map((i) => ({ name: i.name, cost_price: i.cost_price, qty: i.qty, barcode: i.barcode, mfg_date: i.mfg_date, expiry_date: i.expiry_date })),
            });
            success = `บันทึกใบรับฝากสำเร็จ: ${result.data.doc_no}${loaded ? ` (จากคำขอ ${loaded.request_no})` : ''}`;
            cart = [];
            loaded = null;
            error = null;
            await loadRequests();
            draw();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    await loadRequests();
    draw();
}
