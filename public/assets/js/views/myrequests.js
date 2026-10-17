import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';

const STATUS = {
    pending: ['warn', 'รอร้านตรวจรับ'],
    received: ['ok', 'รับฝากแล้ว'],
    rejected: ['bad', 'ไม่รับฝาก'],
    cancelled: ['muted', 'ยกเลิกแล้ว'],
};

/** Consignor view: list the goods and quantities to bring; staff check them and receive. */
export async function render(container) {
    let cart = [];
    let note = '';
    let error = null;
    let success = null;
    let requests = [];
    let quoteSeq = 0; // ignore quote replies that arrive after a newer keystroke

    async function load() {
        requests = (await api.get('/my/requests')).data;
        draw();
    }

    function requestCard(r) {
        const [cls, label] = STATUS[r.status] || ['muted', r.status];
        const qty = r.items.reduce((s, i) => s + Number(i.qty), 0);
        return `
        <section class="card">
          <div class="card-head">
            <h2 style="font-size:14px">${escapeHtml(r.request_no)} <span class="pill ${cls}">${label}</span></h2>
            <div class="actions-row">
              <span class="muted" style="font-size:12.5px">${fmtDateTime(r.created_at)} · ${r.items.length} รายการ ${qty} ชิ้น</span>
              ${r.status === 'pending' ? `<button type="button" class="btn sm secondary" data-cancel="${r.id}">ยกเลิกคำขอ</button>` : ''}
            </div>
          </div>
          ${r.status === 'received' ? `<div class="card-pad" style="padding-bottom:0;font-size:13px">ร้านรับฝากแล้ว เลขที่ใบรับฝาก <b>${escapeHtml(r.receive_doc_no || '')}</b>
              — จำนวนจริงที่รับดูได้ที่หน้า «สินค้าที่ฝากขาย»</div>` : ''}
          ${r.status === 'rejected' ? `<div class="card-pad" style="padding-bottom:0"><div class="error-box">เหตุผล: ${escapeHtml(r.review_note || '-')}</div></div>` : ''}
          ${r.note ? `<div class="card-pad muted" style="padding-bottom:0;font-size:12.5px">หมายเหตุ: ${escapeHtml(r.note)}</div>` : ''}
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th class="num">ราคาที่ต้องการได้</th><th class="num">จำนวน</th><th>วันที่ผลิต</th><th>วันหมดอายุ</th></tr></thead>
            <tbody>${r.items.map((i) => `
              <tr><td>${escapeHtml(i.name)}${i.barcode ? `<div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(i.barcode)}</div>` : ''}</td>
                  <td class="num">${money(i.cost_price)}</td><td class="num">${Number(i.qty)}</td>
                  <td class="muted">${i.mfg_date ? fmtDate(i.mfg_date) : '-'}</td>
                  <td class="muted">${i.expiry_date ? fmtDate(i.expiry_date) : '-'}</td></tr>`).join('')}
            </tbody>
          </table></div>
        </section>`;
    }

    function draw() {
        container.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="hint"><div class="dot">i</div><div>
            กรอกสินค้าและจำนวนที่จะนำมาฝากขาย แล้วกด «ส่งคำขอฝาก» จากนั้นนำสินค้ามาที่ร้าน
            พนักงานจะตรวจนับและแก้ไขให้ตรงกับของจริงก่อนรับฝาก
          </div></div>
          ${success ? `<div class="pill ok" style="align-self:flex-start;font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
          <section class="card card-pad">
            <h2 style="margin-bottom:12px">เพิ่มสินค้า</h2>
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
            <div class="form-grid">
              <label class="field" style="grid-column:span 2">ชื่อสินค้า <input id="f-name" placeholder="เช่น ขนมทองม้วนสมุนไพร"></label>
              <label class="field">ราคาที่ต้องการได้ต่อชิ้น (บาท) * <input id="f-cost" type="number" step="0.25" min="0"></label>
              <div class="field">ราคาขายหน้าร้าน <div id="f-price" class="price-preview">-</div></div>
              <label class="field">จำนวน (ชิ้น) * <input id="f-qty" type="number" min="1" value="1"></label>
              <label class="field">วันที่ผลิต * <input id="f-mfg" type="date" value="${todayISO()}" max="${todayISO()}" required></label>
              <label class="field">วันหมดอายุ (ถ้ามี) <input id="f-expiry" type="date"></label>
              <label class="field">บาร์โค้ด (ถ้ามี) <input id="f-barcode"></label>
            </div>
            <div id="quote-box" class="muted" style="margin:12px 0;font-size:13px"></div>
            <button type="button" class="btn secondary" id="btn-add">+ เพิ่มลงรายการ</button>
          </section>
          <section class="card">
            <div class="card-head"><h2>รายการที่จะฝาก (${cart.length})</h2></div>
            <div class="table-wrap"><table>
              <thead><tr><th>สินค้า</th><th class="num">ราคาที่ต้องการได้</th><th class="num">ราคาขายหน้าร้าน</th><th class="num">จำนวน</th><th>วันที่ผลิต</th><th></th></tr></thead>
              <tbody>${cart.map((it, i) => `
                <tr><td>${escapeHtml(it.name)}</td><td class="num">${money(it.cost_price)}</td><td class="num">${money(it.price)}</td>
                    <td class="num">${it.qty}</td><td class="muted">${fmtDate(it.mfg_date)}</td><td style="text-align:right"><button type="button" class="btn secondary sm" data-remove="${i}">ลบ</button></td></tr>`).join('')
                || '<tr><td colspan="6" class="empty">ยังไม่มีรายการ</td></tr>'}
              </tbody>
            </table></div>
            <div class="card-pad" style="display:flex;flex-direction:column;gap:10px">
              <label class="field">หมายเหตุถึงร้าน (ถ้ามี) <input id="f-note" value="${escapeHtml(note)}" placeholder="เช่น จะนำมาส่งวันจันทร์ช่วงพักกลางวัน"></label>
              <div><button type="button" class="btn" id="btn-submit" ${cart.length ? '' : 'disabled'}>ส่งคำขอฝาก</button></div>
            </div>
          </section>
          <h2 style="font-size:15px;margin-top:8px">คำขอฝากของฉัน</h2>
          ${requests.length ? requests.map(requestCard).join('') : '<div class="empty">ยังไม่มีคำขอฝาก</div>'}
        </div>`;
        wire();
    }

    function wire() {
        container.querySelector('#f-cost').addEventListener('input', updateQuote);
        container.querySelector('#f-note').addEventListener('input', (e) => { note = e.target.value; });
        container.querySelector('#btn-add').addEventListener('click', addItem);
        container.querySelector('#btn-submit').addEventListener('click', submit);
        container.querySelectorAll('[data-remove]').forEach((btn) => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            draw();
        }));
        container.querySelectorAll('[data-cancel]').forEach((btn) => btn.addEventListener('click', async () => {
            if (!confirm('ยกเลิกคำขอฝากนี้?')) return;
            try {
                await api.post(`/my/requests/${btn.dataset.cancel}/cancel`);
                success = 'ยกเลิกคำขอแล้ว';
            } catch (e) {
                error = e.message;
            }
            await load();
        }));
    }

    async function updateQuote() {
        const cost = Number(container.querySelector('#f-cost').value);
        const box = container.querySelector('#quote-box');
        const priceEl = container.querySelector('#f-price');
        const seq = ++quoteSeq;
        if (!cost) { box.textContent = ''; priceEl.textContent = '-'; return; }
        try {
            const q = (await api.post('/my/quote', { cost_price: cost })).data;
            if (seq !== quoteSeq) return;
            priceEl.textContent = `฿ ${money(q.price)}`;
            box.innerHTML = `ราคาที่คุณได้ ฿${money(cost)} + ค่าฝากขาย ฿${money(q.fee)} = ฿${money(q.raw)} → ปัดเป็นราคาขาย <b>฿${money(q.price)}</b>`;
        } catch (e) {
            if (seq === quoteSeq) { priceEl.textContent = '-'; box.textContent = e.message; }
        }
    }

    async function addItem() {
        error = null;
        success = null;
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
        const q = (await api.post('/my/quote', { cost_price: cost })).data;
        cart.push({ name, cost_price: cost, qty, barcode: barcode || null, mfg_date: mfg, expiry_date: expiry || null, price: q.price });
        draw();
    }

    async function submit() {
        try {
            const res = await api.post('/my/requests', {
                note,
                items: cart.map(({ name, cost_price, qty, barcode, mfg_date, expiry_date }) => ({ name, cost_price, qty, barcode, mfg_date, expiry_date })),
            });
            cart = [];
            note = '';
            error = null;
            success = `ส่งคำขอฝาก ${res.data.request_no} แล้ว — นำสินค้ามาที่ร้านเพื่อให้พนักงานตรวจรับ`;
        } catch (e) {
            error = e.message;
        }
        await load();
    }

    await load();
}
