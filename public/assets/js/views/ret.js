import { api } from '../api.js';
import { money, escapeHtml, fmtDate } from '../util.js';

export async function render(container) {
    const options = (await api.get('/consignors/options')).data;
    // No consignor until one is chosen, so goods are never returned to someone by accident.
    let consignorId = null;
    let stock = [];
    let qtyByProduct = {};
    let recipient = '';
    let formOpen = false; // the return form modal
    let loading = false;
    let error = null;
    let success = null;

    async function load() {
        stock = [];
        qtyByProduct = {};
        if (consignorId) {
            loading = true;
            draw();
            try {
                stock = (await api.get(`/returns/stock/${consignorId}`)).data;
            } catch (e) {
                error = e.message;
            }
            loading = false;
        }
        draw();
    }

    function totals() {
        const items = stock.map((p) => ({ p, qty: Number(qtyByProduct[p.id] || 0) })).filter((x) => x.qty > 0);
        return {
            lines: items.length,
            qty: items.reduce((s, x) => s + x.qty, 0),
            value: items.reduce((s, x) => s + x.qty * Number(x.p.sale_price), 0),
        };
    }

    function summaryHtml() {
        const t = totals();
        return `
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนรายการ</span><span>${t.lines}</span></div>
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนชิ้นรวม</span><span>${t.qty}</span></div>
              <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--border);padding-top:8px"><span>มูลค่ารวม</span><span>฿ ${money(t.value)}</span></div>`;
    }

    function formHtml() {
        const t = totals();
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rt-title" style="width:1100px">
            <div class="modal-head">
              <h2 id="rt-title">คืนสินค้าให้ผู้ฝากขาย</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <label class="field">ผู้ฝากขายที่มาขอรับสินค้าคืน *
                <select id="sel-consignor" required>
                  <option value="" ${consignorId == null ? 'selected' : ''} disabled>— เลือกผู้ฝากขาย —</option>
                  ${options.map((o) => `<option value="${o.id}" ${Number(o.id) === Number(consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
            </section>
            ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
            <section class="card">
              <div class="card-head"><h2>สินค้าคงเหลือในร้าน — ใส่จำนวนที่ต้องการคืน</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคาขาย</th><th class="num">คงเหลือ</th><th>วันหมดอายุ</th><th class="num">จำนวนที่คืน</th></tr></thead>
                <tbody>
                  ${!consignorId ? '<tr><td colspan="5" class="empty">เลือกผู้ฝากขายก่อน</td></tr>'
                    : loading ? '<tr><td colspan="5" class="empty">กำลังโหลด...</td></tr>'
                    : stock.map((p) => `
                    <tr>
                      <td>${escapeHtml(p.name)}</td>
                      <td class="num">${money(p.sale_price)}</td>
                      <td class="num">${p.qty_stock}</td>
                      <td>${p.expiry_date ? fmtDate(p.expiry_date) : '-'}</td>
                      <td class="num"><input type="number" min="0" max="${p.qty_stock}" style="width:80px;text-align:right" data-qty="${p.id}" value="${qtyByProduct[p.id] || ''}"></td>
                    </tr>
                  `).join('') || '<tr><td colspan="5" class="empty">ไม่มีสินค้าคงเหลือของผู้ฝากรายนี้</td></tr>'}
                </tbody>
              </table></div>
            </section>
          </div>

          <section class="card card-pad">
            <h2 style="margin-bottom:12px">สรุปใบคืนสินค้า</h2>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13.5px;margin-bottom:14px" data-summary>${summaryHtml()}</div>
            <p class="muted" style="font-size:12px">สินค้าที่คืนไม่มีการคิดค่าฝากขาย เพราะค่าฝากหักตอนขายได้เท่านั้น</p>
            <label class="field" style="margin:12px 0">ผู้รับสินค้าคืน <input id="f-recipient" placeholder="ชื่อผู้มารับ / ผู้รับมอบอำนาจ" value="${escapeHtml(recipient)}"></label>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${t.lines && consignorId ? '' : 'disabled'}>บันทึกการคืนสินค้า</button>
          </section>
        </div>
            </div>
          </div>
        </div>`;
    }

    function draw() {
        const pending = totals().lines;
        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="actions-row" style="justify-content:space-between">
          <div class="hint" style="flex:1;margin:0"><div class="dot">i</div><div>กด <b>คืนสินค้า</b> เมื่อผู้ฝากขายมาขอรับสินค้าคืน แล้วเลือกผู้ฝากและใส่จำนวนที่คืน</div></div>
          <button type="button" class="btn" id="btn-open" style="font-size:15px;padding:12px 22px">+ คืนสินค้า${pending ? ` (ค้าง ${pending} รายการ)` : ''}</button>
        </div>
        ${formOpen ? formHtml() : ''}`;

        container.querySelector('#btn-open').addEventListener('click', () => {
            formOpen = true;
            success = null;
            draw();
            container.querySelector('#sel-consignor')?.focus();
        });
        if (!formOpen) return;
        // Closing keeps what was entered (the «คืนสินค้า» button shows it is waiting).
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', () => { formOpen = false; error = null; draw(); }));
        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            consignorId = Number(e.target.value) || null;
            error = null;
            load();
        });
        container.querySelector('#f-recipient').addEventListener('input', (e) => { recipient = e.target.value; });
        // Typing a quantity updates the summary in place, so the box keeps its focus.
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('input', (e) => {
            qtyByProduct[e.target.dataset.qty] = e.target.value;
            container.querySelector('[data-summary]').innerHTML = summaryHtml();
            container.querySelector('#btn-submit').disabled = !(totals().lines && consignorId);
        }));
        container.querySelector('#btn-submit').addEventListener('click', submit);
    }

    async function submit() {
        const items = stock
            .filter((p) => Number(qtyByProduct[p.id] || 0) > 0)
            .map((p) => ({ product_id: p.id, qty: Number(qtyByProduct[p.id]) }));
        try {
            const result = await api.post('/returns', {
                consignor_id: consignorId,
                items,
                recipient_name: recipient || null,
            });
            success = `บันทึกใบคืนสินค้าสำเร็จ: ${result.data.doc_no}`;
            error = null;
            formOpen = false;
            consignorId = null; // the next one starts with no consignor chosen
            recipient = '';
            stock = [];
            qtyByProduct = {};
            draw();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    draw();
}
