import { api } from '../api.js';
import { money, count, fmtDateTime, escapeHtml } from '../util.js';

export async function render(container, { navigate }) {
    const data = await api.get('/dashboard');
    const k = data.kpis;

    container.innerHTML = `
    <div class="kpi-grid">
      <div class="kpi">
        <div class="lbl">ยอดขายของฝากขายวันนี้</div>
        <div class="val">฿ ${money(k.sales_today_value)}</div>
        <div class="sub">${count(k.sales_today_qty)} ชิ้น</div>
      </div>
      <div class="kpi">
        <div class="lbl">ค่าฝากขายที่ร้านได้รับ (วันนี้)</div>
        <div class="val">฿ ${money(k.fee_today)}</div>
        <div class="sub">รวมค่าทำข้อมูล ฿ ${money(k.listing_fee_today)}</div>
      </div>
      <div class="kpi">
        <div class="lbl">ยอดคงเหลือค้างจ่ายผู้ฝาก</div>
        <div class="val">฿ ${money(k.outstanding_balance)}</div>
        <div class="sub">ผู้ฝากที่มีเงินคงเหลือ ${count(k.outstanding_consignor_count)} ราย</div>
      </div>
      <div class="kpi">
        <div class="lbl">สินค้าฝากขายในสต๊อก</div>
        <div class="val">${count(k.stock_units)} ชิ้น</div>
        <div class="sub">${count(k.stock_items)} รายการ · ผู้ฝาก ${count(k.stock_consignors)} ราย</div>
      </div>
    </div>

    <div class="two-col">
      <section class="card">
        <div class="card-head"><h2>เรื่องที่ต้องจัดการ</h2></div>
        <div id="alerts-list">${renderAlerts(data.alerts)}</div>
      </section>
      <section class="card card-pad">
        <h2 style="margin-bottom:4px">ทางลัดที่ใช้บ่อย</h2>
        <p class="muted" style="margin:0 0 14px;font-size:12.5px">กดปุ่มใหญ่เพื่อเริ่มงาน</p>
        <div class="shortcuts-grid">
          <button type="button" class="shortcut-btn" data-go="sales">💰 ขายสินค้า</button>
          <button type="button" class="shortcut-btn" data-go="receive">📥 รับฝากสินค้าใหม่</button>
          <button type="button" class="shortcut-btn" data-go="ret">📤 คืนสินค้าให้ผู้ฝาก</button>
          <button type="button" class="shortcut-btn" data-go="count">📋 ตรวจนับสต๊อก</button>
        </div>
      </section>
    </div>

    <div class="two-col">
      <section class="card">
        <div class="card-head"><h2>การขายล่าสุด</h2></div>
        <div class="table-wrap"><table>
          <thead><tr><th>เวลา</th><th>สินค้า</th><th>ผู้ฝาก</th><th>จำนวน</th><th>รวมเงิน</th></tr></thead>
          <tbody>
            ${(data.recent_sales || []).map((s) => `
              <tr><td>${fmtDateTime(s.time)}</td><td>${escapeHtml(s.name)}</td><td class="muted">${escapeHtml(s.owner)}</td>
                  <td class="num">${count(s.qty)}</td><td class="num">${money(s.total)}</td></tr>
            `).join('') || `<tr><td colspan="5" class="empty">ยังไม่มีการขายวันนี้</td></tr>`}
          </tbody>
        </table></div>
      </section>
      <section class="card card-pad">
        <h2 style="margin-bottom:3px">สินค้าขายดี 7 วันล่าสุด</h2>
        <p class="muted" style="margin:0 0 15px;font-size:12.5px">ใช้แนะนำผู้ฝากขายว่าควรเติมสินค้าตัวไหน</p>
        <div style="display:flex;flex-direction:column;gap:13px">
          ${renderTopSellers(data.top_sellers)}
        </div>
      </section>
    </div>
  `;

    container.querySelectorAll('[data-go]').forEach((btn) => {
        btn.addEventListener('click', () => navigate(btn.dataset.go));
    });
    container.querySelectorAll('[data-alert-go]').forEach((btn) => {
        btn.addEventListener('click', () => navigate(btn.dataset.alertGo));
    });
}

function renderAlerts(alerts) {
    if (!alerts || !alerts.length) {
        return `<div class="empty">ไม่มีเรื่องที่ต้องจัดการตอนนี้ 🎉</div>`;
    }
    const dotColor = { warn: 'var(--warn)', info: 'var(--pri)' };
    return alerts.map((a) => `
      <button type="button" data-alert-go="${a.target}" style="display:flex;gap:13px;align-items:flex-start;text-align:left;width:100%;background:transparent;border:0;border-bottom:1px solid var(--border);padding:14px 18px;cursor:pointer;color:inherit">
        <span style="width:9px;height:9px;border-radius:50%;background:${dotColor[a.level] || 'var(--muted)'};margin-top:5px;flex:none"></span>
        <span style="flex:1;min-width:0">
          <span style="display:block;font-size:14px;font-weight:600">${escapeHtml(a.title)}</span>
          <span style="display:block;font-size:12.5px;color:var(--muted);margin-top:2px">${escapeHtml(a.detail)}</span>
        </span>
        <span style="font-size:12px;color:var(--pri);font-weight:600;white-space:nowrap;margin-top:2px">${escapeHtml(a.action)} →</span>
      </button>
    `).join('');
}

function renderTopSellers(sellers) {
    if (!sellers || !sellers.length) {
        return `<div class="empty">ยังไม่มีข้อมูลการขาย</div>`;
    }
    const max = Math.max(...sellers.map((p) => Number(p.qty)));
    return sellers.map((p) => `
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span style="font-weight:500">${escapeHtml(p.name)}</span>
          <span class="muted">${count(p.qty)} ชิ้น</span>
        </div>
        <div class="bar-track"><div class="bar-fill" style="width:${max ? (p.qty / max * 100) : 0}%"></div></div>
      </div>
    `).join('');
}
