import { api } from '../api.js';
import { money, escapeHtml, fmtDate } from '../util.js';

export async function render(container) {
    const options = (await api.get('/consignors/options')).data;
    let consignorId = options[0]?.id ?? null;
    let stock = [];
    let qtyByProduct = {};
    let error = null;
    let success = null;

    async function load() {
        if (!consignorId) { container.innerHTML = `<div class="empty">ยังไม่มีผู้ฝากขาย</div>`; return; }
        stock = (await api.get(`/returns/stock/${consignorId}`)).data;
        qtyByProduct = {};
        draw();
    }

    function draw() {
        const returnItems = stock
            .map((p) => ({ p, qty: Number(qtyByProduct[p.id] || 0) }))
            .filter((x) => x.qty > 0);
        const totalQty = returnItems.reduce((s, x) => s + x.qty, 0);
        const totalValue = returnItems.reduce((s, x) => s + x.qty * Number(x.p.sale_price), 0);

        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <label class="field">ผู้ฝากขายที่มาขอรับสินค้าคืน
                <select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
            </section>
            ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
            <section class="card">
              <div class="card-head"><h2>สินค้าคงเหลือในร้าน — ใส่จำนวนที่ต้องการคืน</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคาขาย</th><th class="num">คงเหลือ</th><th>วันหมดอายุ</th><th class="num">จำนวนที่คืน</th></tr></thead>
                <tbody>
                  ${stock.map((p) => `
                    <tr>
                      <td>${escapeHtml(p.name)}</td>
                      <td class="num">${money(p.sale_price)}</td>
                      <td class="num">${p.qty_stock}</td>
                      <td>${p.expiry_date ? fmtDate(p.expiry_date) : '-'}</td>
                      <td class="num"><input type="number" min="0" max="${p.qty_stock}" style="width:80px;text-align:right" data-qty="${p.id}" value="${qtyByProduct[p.id] || ''}"></td>
                    </tr>
                  `).join('') || `<tr><td colspan="5" class="empty">ไม่มีสินค้าคงเหลือของผู้ฝากรายนี้</td></tr>`}
                </tbody>
              </table></div>
            </section>
          </div>

          <section class="card card-pad">
            <h2 style="margin-bottom:12px">สรุปใบคืนสินค้า</h2>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13.5px;margin-bottom:14px">
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนรายการ</span><span>${returnItems.length}</span></div>
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนชิ้นรวม</span><span>${totalQty}</span></div>
              <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--border);padding-top:8px"><span>มูลค่ารวม</span><span>฿ ${money(totalValue)}</span></div>
            </div>
            <p class="muted" style="font-size:12px">สินค้าที่คืนไม่มีการคิดค่าฝากขาย เพราะค่าฝากหักตอนขายได้เท่านั้น</p>
            <label class="field" style="margin:12px 0">ผู้รับสินค้าคืน <input id="f-recipient" placeholder="ชื่อผู้มารับ / ผู้รับมอบอำนาจ"></label>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${returnItems.length ? '' : 'disabled'}>บันทึกการคืนสินค้า</button>
          </section>
        </div>`;

        container.querySelector('#sel-consignor').addEventListener('change', (e) => { consignorId = Number(e.target.value); load(); });
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('input', (e) => {
            qtyByProduct[e.target.dataset.qty] = e.target.value;
            draw();
        }));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
    }

    async function submit() {
        const items = stock
            .filter((p) => Number(qtyByProduct[p.id] || 0) > 0)
            .map((p) => ({ product_id: p.id, qty: Number(qtyByProduct[p.id]) }));
        try {
            const result = await api.post('/returns', {
                consignor_id: consignorId,
                items,
                recipient_name: container.querySelector('#f-recipient').value || null,
            });
            success = `บันทึกใบคืนสินค้าสำเร็จ: ${result.data.doc_no}`;
            error = null;
            await load();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    await load();
}
