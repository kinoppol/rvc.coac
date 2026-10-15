import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';

export async function render(container) {
    const options = (await api.get('/consignors/options')).data;
    let consignorId = options[0]?.id ?? null;
    let cart = [];
    let quote = null;
    let error = null;
    let success = null;

    function draw() {
        const total = cart.reduce((s, i) => s + i.price * i.qty, 0);

        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <div class="hint"><div class="dot">i</div><div>ทำ 3 ขั้น: <b>1)</b> เลือกผู้ฝากขาย <b>2)</b> กรอกสินค้าทีละรายการแล้วกดเพิ่ม <b>3)</b> ตรวจยอดด้านขวาแล้วกดบันทึก</div></div>

            <section class="card card-pad">
              <div class="step-title"><span class="step-num">1</span><h2>ผู้ฝากขาย</h2></div>
              <label class="field">เลือกผู้ฝากขาย
                <select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
            </section>

            <section class="card card-pad">
              <div class="step-title"><span class="step-num">2</span><h2>เพิ่มรายการสินค้า</h2></div>
              ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
              <div class="form-grid">
                <label class="field" style="grid-column:span 2">ชื่อสินค้า <input id="f-name" placeholder="เช่น ขนมทองม้วนสมุนไพร"></label>
                <label class="field">ราคาที่ผู้ฝากต้องการได้ (บาท) <input id="f-cost" type="number" step="0.25" min="0"></label>
                <label class="field">จำนวนที่ฝาก (ชิ้น) <input id="f-qty" type="number" min="1" value="1"></label>
                <label class="field">บาร์โค้ด (ถ้ามี) <input id="f-barcode"></label>
                <label class="field">วันหมดอายุ (ถ้ามี) <input id="f-expiry" type="date"></label>
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
                <thead><tr><th>สินค้า</th><th class="num">ราคาผู้ฝาก</th><th class="num">ราคาขาย</th><th class="num">จำนวน</th><th></th></tr></thead>
                <tbody>
                  ${cart.map((it, i) => `
                    <tr><td>${escapeHtml(it.name)}</td><td class="num">${money(it.cost_price)}</td><td class="num">${money(it.price)}</td>
                        <td class="num">${it.qty}</td><td style="text-align:right"><button type="button" class="btn secondary sm" data-remove="${i}">ลบ</button></td></tr>
                  `).join('') || `<tr><td colspan="5" class="empty">ยังไม่มีรายการ</td></tr>`}
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
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${cart.length ? '' : 'disabled'}>บันทึกการรับฝาก</button>
          </section>
        </div>`;

        wire();
    }

    function wire() {
        container.querySelector('#sel-consignor').addEventListener('change', (e) => { consignorId = Number(e.target.value); });
        container.querySelector('#f-cost').addEventListener('input', updateQuote);
        container.querySelector('#btn-clear').addEventListener('click', () => { draw(); });
        container.querySelector('#btn-add').addEventListener('click', addItem);
        container.querySelectorAll('[data-remove]').forEach((btn) => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            draw();
        }));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
    }

    async function updateQuote() {
        const cost = Number(container.querySelector('#f-cost').value);
        const box = container.querySelector('#quote-box');
        if (!cost) { box.textContent = ''; return; }
        const res = await api.post('/receive/quote', { cost_price: cost });
        quote = res.data;
        box.innerHTML = `ระบบคิดราคาขายให้อัตโนมัติ: ต้นทุน ฿${money(cost)} + ค่าฝากขาย ฿${money(quote.fee)} = ฿${money(quote.raw)} → ปัดเป็น <b>฿${money(quote.price)}</b>`;
    }

    async function addItem() {
        error = null;
        const name = container.querySelector('#f-name').value.trim();
        const cost = Number(container.querySelector('#f-cost').value);
        const qty = Number(container.querySelector('#f-qty').value);
        const barcode = container.querySelector('#f-barcode').value.trim();
        const expiry = container.querySelector('#f-expiry').value;
        if (!name || !cost || !qty) {
            error = 'กรุณากรอกชื่อสินค้า ราคา และจำนวนให้ครบ';
            draw();
            return;
        }
        const res = await api.post('/receive/quote', { cost_price: cost });
        cart.push({ name, cost_price: cost, qty, barcode: barcode || null, expiry_date: expiry || null, price: res.data.price });
        draw();
    }

    async function submit() {
        if (!consignorId || !cart.length) return;
        try {
            const result = await api.post('/receive', {
                consignor_id: consignorId,
                items: cart.map((i) => ({ name: i.name, cost_price: i.cost_price, qty: i.qty, barcode: i.barcode, expiry_date: i.expiry_date })),
            });
            cart = [];
            error = null;
            success = `บันทึกใบรับฝากสำเร็จ: ${result.data.doc_no}`;
            draw();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    draw();
}
