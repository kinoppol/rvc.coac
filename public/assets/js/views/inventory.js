import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';

export async function render(container, { navigate, permissions }) {
    let search = '';

    async function load() {
        const q = search ? `?q=${encodeURIComponent(search)}` : '';
        draw(await api.get(`/inventory${q}`));
    }

    function draw(res) {
        const s = res.summary;
        const canBuy = permissions.includes('purchase.create');
        container.innerHTML = `
        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">มูลค่าสต๊อก (ต้นทุน)</div><div class="val">฿ ${money(s.stock_cost_value)}</div>
            <div class="sub">${count(s.stock_units)} ชิ้น · ${count(s.items)} รายการ${s.out_of_stock ? ` · หมด ${count(s.out_of_stock)}` : ''}</div></div>
          <div class="kpi"><div class="lbl">มูลค่าสต๊อก (ราคาขาย)</div><div class="val">฿ ${money(s.stock_retail_value)}</div>
            <div class="sub">กำไรที่คาดว่าจะได้ ฿ ${money(s.expected_profit)}</div></div>
          <div class="kpi"><div class="lbl">ยอดขายสะสม</div><div class="val">฿ ${money(s.sales_total)}</div>
            <div class="sub">${count(s.qty_sold)} ชิ้น · ต้นทุนขาย ฿ ${money(s.cogs_total)}</div></div>
          <div class="kpi"><div class="lbl">กำไรขั้นต้น</div><div class="val">฿ ${money(s.gross_profit)}</div>
            <div class="sub">หักค่าวัสดุใช้ในร้าน ฿ ${money(s.supply_cost)} = กำไรสุทธิ ฿ ${money(s.net_profit)}</div></div>
        </div>
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <input type="search" id="q" placeholder="ค้นหาชื่อหรือบาร์โค้ด แล้วกด Enter" value="${escapeHtml(search)}" style="max-width:320px">
            ${canBuy ? `<button type="button" class="btn" id="go-buy">ซื้อสินค้าเข้าร้าน</button>` : ''}
          </div>
        </section>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th>บาร์โค้ด</th><th>รับเข้า</th><th>ขายแล้ว</th><th>คงเหลือ</th>
              <th>ต้นทุน/ชิ้น</th><th>ราคาขาย</th><th>กำไร %</th><th>มูลค่าทุนคงเหลือ</th><th>กำไรสะสม</th><th>ซื้อล่าสุด</th></tr></thead>
            <tbody>${res.data.map(row).join('') || `<tr><td colspan="11" class="empty">ยังไม่มีสินค้าของร้าน — เริ่มจากเมนู ซื้อสินค้าเข้าร้าน</td></tr>`}</tbody>
          </table></div>
        </section>`;

        const input = container.querySelector('#q');
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { search = input.value.trim(); load(); } });
        const buy = container.querySelector('#go-buy');
        if (buy) buy.addEventListener('click', () => navigate('purchase'));
    }

    function row(p) {
        const low = Number(p.qty_stock) <= 3;
        return `<tr>
          <td>${escapeHtml(p.name)}</td>
          <td class="muted">${escapeHtml(p.barcode || p.pack_barcode || '')}</td>
          <td class="num">${count(p.qty_received)}</td>
          <td class="num">${count(p.qty_sold)}</td>
          <td class="num" style="${low ? 'color:var(--warn);font-weight:600' : ''}">${count(p.qty_stock)}</td>
          <td class="num">${money(p.cost_price)}</td>
          <td class="num">${money(p.sale_price)}</td>
          <td class="num">${p.margin_percent}%</td>
          <td class="num">${money(p.stock_cost_value)}</td>
          <td class="num">${money(p.profit_total)}</td>
          <td class="muted">${fmtDate(p.last_purchase)}</td>
        </tr>`;
    }

    await load();
}
