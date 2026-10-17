import { api } from '../api.js';
import { money, escapeHtml, fmtDate } from '../util.js';

/** Consignor view: the products this person has consigned and how many are left. */
export async function render(container) {
    const { data } = await api.get('/my/products');
    container.innerHTML = `
    <section class="card">
      <div class="card-head"><h2>สินค้าที่ฝากขาย</h2></div>
      ${data.length ? `<div class="table-wrap"><table>
        <thead><tr><th>สินค้า</th><th class="num">ราคาขาย</th><th class="num">รับฝาก</th><th class="num">คงเหลือ</th><th class="num">ขายแล้ว</th><th class="num">รับคืน</th><th>วันหมดอายุ</th></tr></thead>
        <tbody>
          ${data.map((p) => `
            <tr>
              <td>${escapeHtml(p.name)}<div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(p.barcode || '')}</div></td>
              <td class="num">${money(p.sale_price)}</td>
              <td class="num">${Number(p.qty_received)}</td>
              <td class="num" style="font-weight:600">${Number(p.qty_stock)}</td>
              <td class="num">${Number(p.qty_sold)}</td>
              <td class="num">${Number(p.qty_returned)}</td>
              <td class="muted">${p.expiry_date ? fmtDate(p.expiry_date) : '-'}</td>
            </tr>`).join('')}
        </tbody>
      </table></div>` : '<div class="empty">ยังไม่มีสินค้าที่ฝากขาย</div>'}
    </section>`;
}
