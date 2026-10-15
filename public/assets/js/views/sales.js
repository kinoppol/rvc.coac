import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';

export async function render(container) {
    let cart = [];
    let searchResults = [];
    let error = null;
    let success = null;

    function draw() {
        const total = cart.reduce((s, i) => s + i.sale_price * i.qty, 0);
        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <h2 style="margin-bottom:10px">ค้นหาสินค้า</h2>
              <div class="search-box" style="max-width:420px">🔍 <input id="q" placeholder="ชื่อสินค้า หรือบาร์โค้ด"></div>
              <div id="search-results" style="margin-top:10px;display:flex;flex-direction:column;gap:6px"></div>
            </section>
            <section class="card">
              <div class="card-head"><h2>ตะกร้าสินค้า (${cart.length})</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th>ผู้ฝาก</th><th class="num">ราคา</th><th class="num">จำนวน</th><th class="num">รวม</th><th></th></tr></thead>
                <tbody>
                  ${cart.map((it, i) => `
                    <tr>
                      <td>${escapeHtml(it.name)}</td><td class="muted">${escapeHtml(it.consignor_name)}</td>
                      <td class="num">${money(it.sale_price)}</td>
                      <td class="num"><input type="number" min="1" max="${it.qty_stock}" value="${it.qty}" data-qty="${i}" style="width:70px;text-align:right"></td>
                      <td class="num">${money(it.sale_price * it.qty)}</td>
                      <td style="text-align:right"><button type="button" class="btn secondary sm" data-remove="${i}">ลบ</button></td>
                    </tr>`).join('') || `<tr><td colspan="6" class="empty">ยังไม่มีสินค้าในตะกร้า</td></tr>`}
                </tbody>
              </table></div>
            </section>
          </div>
          <section class="card card-pad">
            <h2 style="margin-bottom:12px">สรุปการขาย</h2>
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
            <div style="display:flex;justify-content:space-between;font-weight:700;font-size:18px;border-top:1px solid var(--border);padding-top:10px;margin-bottom:16px">
              <span>ยอดรวม</span><span>฿ ${money(total)}</span>
            </div>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${cart.length ? '' : 'disabled'}>บันทึกการขาย</button>
          </section>
        </div>`;

        const qInput = container.querySelector('#q');
        qInput.addEventListener('input', () => {
            clearTimeout(container._t);
            container._t = setTimeout(() => search(qInput.value), 200);
        });
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('input', (e) => {
            cart[Number(e.target.dataset.qty)].qty = Math.max(1, Number(e.target.value) || 1);
            draw();
        }));
        container.querySelectorAll('[data-remove]').forEach((btn) => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            draw();
        }));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
        renderResults();
    }

    function renderResults() {
        const box = container.querySelector('#search-results');
        if (!box) return;
        box.innerHTML = searchResults.map((p) => `
          <button type="button" class="shortcut-btn" style="flex-direction:row;justify-content:space-between;align-items:center;min-height:auto;padding:10px 14px" data-pick="${p.id}">
            <span>${escapeHtml(p.name)} <span class="muted" style="font-size:12px">(${escapeHtml(p.consignor_name)}, คงเหลือ ${p.qty_stock})</span></span>
            <span>฿ ${money(p.sale_price)}</span>
          </button>`).join('');
        box.querySelectorAll('[data-pick]').forEach((btn) => btn.addEventListener('click', () => pick(Number(btn.dataset.pick))));
    }

    async function search(q) {
        if (!q) { searchResults = []; renderResults(); return; }
        const res = await api.get(`/sales/products?q=${encodeURIComponent(q)}`);
        searchResults = res.data;
        renderResults();
    }

    function pick(id) {
        const p = searchResults.find((x) => x.id === id);
        if (!p) return;
        const existing = cart.find((c) => c.id === id);
        if (existing) {
            existing.qty = Math.min(existing.qty + 1, existing.qty_stock);
        } else {
            cart.push({ ...p, qty: 1 });
        }
        draw();
    }

    async function submit() {
        try {
            const result = await api.post('/sales', {
                items: cart.map((i) => ({ product_id: i.id, qty: i.qty })),
            });
            cart = [];
            error = null;
            success = `บันทึกการขายสำเร็จ: ${result.data.doc_no}`;
            draw();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    draw();
}
