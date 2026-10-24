import { api } from '../api.js';
import { money, count, fmtDateTime, escapeHtml } from '../util.js';
import { openDetailModal } from '../detail-modal.js';
import { skChart } from '../skeleton.js';

export async function render(container, { navigate }) {
    const data = await api.get('/dashboard');
    const k = data.kpis;

    container.innerHTML = `
    <div class="kpi-grid">
      <div class="kpi kpi-link tone-sales" data-detail="sales_today" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">ยอดขายของฝากขายวันนี้</div>
        <div class="val">฿ ${money(k.sales_today_value)}</div>
        <div class="sub">${count(k.sales_today_qty)} ชิ้น</div>
      </div>
      <div class="kpi kpi-link tone-income" data-detail="fee_today" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">ค่าฝากขายที่ร้านได้รับ (วันนี้)</div>
        <div class="val">฿ ${money(k.fee_today)}</div>
        <div class="sub">รวมค่าทำข้อมูล ฿ ${money(k.listing_fee_today)}</div>
      </div>
      <div class="kpi kpi-link tone-owed" data-detail="outstanding" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">ยอดคงเหลือค้างจ่ายผู้ฝาก</div>
        <div class="val">฿ ${money(k.outstanding_balance)}</div>
        <div class="sub">ผู้ฝากที่มีเงินคงเหลือ ${count(k.outstanding_consignor_count)} ราย</div>
      </div>
      <div class="kpi kpi-link tone-stock" data-detail="consign_stock" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">สินค้าในสต๊อก</div>
        <div class="val">${count(k.stock_units)} ชิ้น</div>
        <div class="sub">${count(k.stock_items)} รายการ · ผู้ฝาก ${count(k.stock_consignors)} ราย</div>
      </div>
      <div class="kpi kpi-link tone-cost" data-detail="purchases" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">ต้นทุนซื้อสินค้าทั้งหมด</div>
        <div class="val">฿ ${money(k.purchase_total_cost)}</div>
        <div class="sub">สินค้าขาย ฿ ${money(k.purchase_total_cost - k.purchase_supply_cost)} · วัสดุใช้ในร้าน ฿ ${money(k.purchase_supply_cost)}</div>
      </div>
      <div class="kpi kpi-link tone-sales" data-detail="store_sales_today" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">ขายสินค้าของร้านวันนี้</div>
        <div class="val">฿ ${money(k.store_sales_today)}</div>
        <div class="sub">${count(k.store_qty_today)} ชิ้น · <span class="${Number(k.store_profit_today) < 0 ? 'gain-neg' : 'gain-pos'}">กำไรขั้นต้น ฿ ${money(k.store_profit_today)}</span></div>
      </div>
      <div class="kpi kpi-link tone-stock" data-detail="store_stock" role="button" tabindex="0" title="ดูรายละเอียด">
        <div class="lbl">สต๊อกสินค้าของร้าน (ต้นทุน)</div>
        <div class="val">฿ ${money(k.store_stock_cost)}</div>
        <div class="sub">${count(k.store_stock_units)} ชิ้น · ${count(k.store_stock_items)} รายการ · ราคาขาย ฿ ${money(k.store_stock_retail)}</div>
      </div>
    </div>

    <section class="card card-pad" id="sales-chart"></section>

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
          <button type="button" class="shortcut-btn" data-go="purchase">🧾 ซื้อสินค้าเข้าร้าน</button>
          <button type="button" class="shortcut-btn" data-go="inventory">📦 สต๊อกสินค้าของร้าน</button>
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
    mountSalesChart(container.querySelector('#sales-chart'));
    container.querySelectorAll('[data-alert-go]').forEach((btn) => {
        btn.addEventListener('click', () => navigate(btn.dataset.alertGo));
    });
    container.querySelectorAll('[data-detail]').forEach((card) => {
        const open = () => openDetailModal(async () => (await api.get(`/dashboard/detail/${card.dataset.detail}`)).data, navigate);
        card.addEventListener('click', open);
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
}

// ---------- ยอดขายรายวัน / รายเดือน ----------

const PERIODS = [['day', 'วัน'], ['week', 'สัปดาห์'], ['month', 'เดือน'], ['year', 'ปี']];
const PERIOD_KEY = 'dashboard.salesPeriod';
const PREV_LABEL = { day: 'วันก่อน', week: 'สัปดาห์ก่อน', month: 'เดือนก่อน', year: 'ปีก่อน' };

const asDate = (ymd) => new Date(`${ymd}T00:00:00`);
const thDate = (ymd, opts) => asDate(ymd).toLocaleDateString('th-TH', opts);

/** 1,284 → "1.3K": y-axis ticks and the cap label stay short. */
function compact(v) {
    if (Math.abs(v) >= 1e6) return `${+(v / 1e6).toFixed(1)}M`;
    if (Math.abs(v) >= 1e3) return `${+(v / 1e3).toFixed(1)}K`;
    return String(Math.round(v));
}

/** Clean axis top and step (1/2/2.5/5 × 10^k) for about four gridlines. */
function niceScale(max) {
    if (max <= 0) return { top: 100, step: 25 };
    const raw = max / 4;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
    return { top: Math.ceil(max / step) * step, step };
}

function bucketLabel(period, key, { long = false } = {}) {
    if (period === 'day') return long ? `${key}:00–${key}:59 น.` : `${Number(key)}:00`;
    if (period === 'year') return thDate(`${key}-01`, { month: long ? 'long' : 'short', ...(long ? { year: 'numeric' } : {}) });
    if (period === 'week') return long ? thDate(key, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }) : thDate(key, { weekday: 'short' });
    return long ? thDate(key, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : String(asDate(key).getDate());
}

function periodLabel(d) {
    if (d.period === 'day') return thDate(d.start, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
    if (d.period === 'year') return `ปี ${thDate(d.start, { year: 'numeric' })}`;
    if (d.period === 'month') return thDate(d.start, { month: 'long', year: 'numeric' });
    return `${thDate(d.start, { day: 'numeric', month: 'short' })} – ${thDate(d.end, { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

function mountSalesChart(el) {
    if (!el) return;
    let period = 'week';
    try { period = PERIODS.some(([k]) => k === localStorage.getItem(PERIOD_KEY)) ? localStorage.getItem(PERIOD_KEY) : 'week'; } catch (e) { /* private mode */ }
    let date = '';
    let data = null;
    let error = null;
    let asTable = false;
    let loading = false;

    async function load() {
        loading = true;
        draw();
        try {
            data = (await api.get(`/dashboard/sales-chart?period=${period}${date ? `&date=${date}` : ''}`)).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        loading = false;
        draw();
    }

    function deltaHtml() {
        if (!data.prev_total && !data.total) return '';
        if (!data.prev_total) return `<span class="muted">${PREV_LABEL[data.period]}ไม่มียอดขาย</span>`;
        const pct = ((data.total - data.prev_total) / data.prev_total) * 100;
        const up = pct >= 0;
        // Status color with an arrow and words, never color alone.
        return `<span style="color:var(${up ? '--ok' : '--bad'});font-weight:600">${up ? '▲' : '▼'} ${up ? 'เพิ่มขึ้น' : 'ลดลง'} ${Math.abs(pct).toFixed(1)}%</span>
          <span class="muted">จาก${PREV_LABEL[data.period]} (฿ ${money(data.prev_total)})</span>`;
    }

    function draw() {
        el.innerHTML = `
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-bottom:12px">
          <div>
            <h2 style="margin:0 0 2px">ยอดขาย${data ? ` ${escapeHtml(periodLabel(data))}` : ''}</h2>
            ${data ? `<div style="font-size:24px;font-weight:700;letter-spacing:-.4px">฿ ${money(data.total)}</div>
              <div style="font-size:12.5px;display:flex;gap:8px;flex-wrap:wrap">
                <span class="muted">${count(data.qty)} ชิ้น · ${count(data.bills)} บิล</span>${deltaHtml()}
              </div>` : ''}
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <div class="tabs">${PERIODS.map(([k, l]) => `<button type="button" class="tab-btn ${k === period ? 'active' : ''}" data-period="${k}">${l}</button>`).join('')}</div>
            <button type="button" class="btn secondary sm" data-nav="prev" title="ช่วงก่อนหน้า" aria-label="ช่วงก่อนหน้า" ${data && !loading ? '' : 'disabled'}>←</button>
            <button type="button" class="btn secondary sm" data-nav="next" title="ช่วงถัดไป" aria-label="ช่วงถัดไป" ${data?.next_date && !loading ? '' : 'disabled'}>→</button>
            <button type="button" class="btn secondary sm" data-nav="today" ${date ? '' : 'disabled'}>ปัจจุบัน</button>
            <button type="button" class="btn secondary sm" data-table>${asTable ? 'ดูเป็นกราฟ' : 'ดูเป็นตาราง'}</button>
          </div>
        </div>
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        <div class="sales-chart-body" style="position:relative;min-height:240px;${loading ? 'opacity:.5' : ''}">
          ${data ? (asTable ? tableHtml() : '') : skChart()}
        </div>`;

        el.querySelectorAll('[data-period]').forEach((b) => b.addEventListener('click', () => {
            period = b.dataset.period;
            date = '';
            try { localStorage.setItem(PERIOD_KEY, period); } catch (e) { /* private mode */ }
            load();
        }));
        el.querySelector('[data-nav="prev"]').addEventListener('click', () => { date = data.prev_date; load(); });
        el.querySelector('[data-nav="next"]').addEventListener('click', () => { date = data.next_date; load(); });
        el.querySelector('[data-nav="today"]').addEventListener('click', () => { date = ''; load(); });
        el.querySelector('[data-table]').addEventListener('click', () => { asTable = !asTable; draw(); });
        if (data && !asTable) chart(el.querySelector('.sales-chart-body'));
    }

    function tableHtml() {
        return `<div class="table-wrap" style="max-height:340px"><table>
          <thead><tr><th>${data.period === 'year' ? 'เดือน' : data.period === 'day' ? 'ช่วงเวลา' : 'วันที่'}</th><th class="num">ยอดขาย</th><th class="num">ฝากขาย</th><th class="num">สินค้าของร้าน</th><th class="num">ชิ้น</th><th class="num">บิล</th></tr></thead>
          <tbody>${data.buckets.map((b) => `
            <tr><td>${escapeHtml(bucketLabel(data.period, b.key, { long: true }))}</td><td class="num">${money(b.value)}</td>
                <td class="num">${money(b.consign)}</td><td class="num">${money(b.store)}</td><td class="num">${count(b.qty)}</td><td class="num">${count(b.bills)}</td></tr>`).join('')}
          </tbody>
          <tfoot><tr style="font-weight:700"><td>รวม</td><td class="num">${money(data.total)}</td>
            <td class="num">${money(data.buckets.reduce((s, b) => s + b.consign, 0))}</td><td class="num">${money(data.buckets.reduce((s, b) => s + b.store, 0))}</td>
            <td class="num">${count(data.qty)}</td><td class="num">${count(data.bills)}</td></tr></tfoot>
        </table></div>`;
    }

    /** Single-series column chart: thin columns, rounded caps, hairline grid, one tooltip per column. */
    function chart(host) {
        const W = Math.max(host.clientWidth, 280);
        const H = 240;
        const m = { l: 44, r: 8, t: 22, b: 26 };
        const pw = W - m.l - m.r;
        const ph = H - m.t - m.b;
        const bs = data.buckets;
        const max = Math.max(0, ...bs.map((b) => b.value));
        const { top, step } = niceScale(max);
        const band = pw / bs.length;
        const bw = Math.max(2, Math.min(24, band * 0.62, band - 2));
        const y = (v) => m.t + ph - (v / top) * ph;
        const base = m.t + ph;
        const today = new Date();
        const ymd = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const todayKey = data.period === 'year' ? ymd.slice(0, 7)
            : data.period === 'day' ? (data.start === ymd ? String(today.getHours()).padStart(2, '0') : null)
            : ymd;
        // Month view: label every k-th day so the numbers never collide.
        const every = Math.max(1, Math.ceil(26 / band));
        const maxIdx = max > 0 ? bs.findIndex((b) => b.value === max) : -1;

        const grid = [];
        for (let v = 0; v <= top + 1e-9; v += step) {
            grid.push(`<line x1="${m.l}" x2="${W - m.r}" y1="${y(v)}" y2="${y(v)}" stroke="var(--border)" stroke-width="1"/>
              <text x="${m.l - 8}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="var(--muted)">${compact(v)}</text>`);
        }
        const bars = bs.map((b, i) => {
            const cx = m.l + band * i + band / 2;
            const x = cx - bw / 2;
            const label = (i % every === 0 || i === bs.length - 1 && data.period !== 'month')
                ? `<text x="${cx}" y="${H - 8}" text-anchor="middle" font-size="11" fill="${b.key === todayKey ? 'var(--text)' : 'var(--muted)'}" font-weight="${b.key === todayKey ? 700 : 400}">${escapeHtml(bucketLabel(data.period, b.key))}</text>`
                : '';
            let col = '';
            if (b.value > 0) {
                const h = Math.max(1, base - y(b.value));
                const r = Math.min(4, bw / 2, h);
                const yt = base - h;
                col = `<path class="sc-bar" data-i="${i}" fill="var(--pri)" d="M${x},${base} V${yt + r} Q${x},${yt} ${x + r},${yt} H${x + bw - r} Q${x + bw},${yt} ${x + bw},${yt + r} V${base} Z"/>`;
            }
            const cap = i === maxIdx
                ? `<text x="${cx}" y="${y(b.value) - 6}" text-anchor="middle" font-size="11" font-weight="600" fill="var(--text)">${compact(b.value)}</text>`
                : '';
            return `<rect class="sc-hit" data-i="${i}" x="${m.l + band * i}" y="${m.t}" width="${band}" height="${ph}" fill="transparent"/>${col}${cap}${label}`;
        }).join('');

        host.innerHTML = `
          <svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="กราฟยอดขาย ${escapeHtml(periodLabel(data))}" style="display:block;overflow:visible">
            ${grid.join('')}
            <rect class="sc-hover" x="0" y="${m.t}" width="0" height="${ph}" fill="var(--hover)" pointer-events="none"/>
            ${bars}
            <line x1="${m.l}" x2="${W - m.r}" y1="${base}" y2="${base}" stroke="var(--border)" stroke-width="1"/>
          </svg>
          ${max === 0 ? '<div class="muted" style="position:absolute;inset:0;display:grid;place-items:center;font-size:13px;pointer-events:none">ไม่มียอดขายในช่วงนี้</div>' : ''}
          <div class="sc-tip" role="tooltip" style="position:absolute;pointer-events:none;display:none;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:8px 11px;font-size:12.5px;box-shadow:0 8px 24px rgba(16,24,40,.18);white-space:nowrap;z-index:2"></div>`;

        const tip = host.querySelector('.sc-tip');
        const hover = host.querySelector('.sc-hover');
        const svg = host.querySelector('svg');
        // The band behind each column is the hit target, so even a short column is easy to hover.
        const show = (i) => {
            const b = bs[i];
            hover.setAttribute('x', m.l + band * i);
            hover.setAttribute('width', band);
            tip.innerHTML = `<div style="font-weight:700;margin-bottom:3px">${escapeHtml(bucketLabel(data.period, b.key, { long: true }))}</div>
              <div style="display:flex;justify-content:space-between;gap:16px"><span class="muted">ยอดขาย</span><b>฿ ${money(b.value)}</b></div>
              ${b.value > 0 ? `
              <div style="display:flex;justify-content:space-between;gap:16px"><span class="muted">ฝากขาย</span><span>฿ ${money(b.consign)}</span></div>
              <div style="display:flex;justify-content:space-between;gap:16px"><span class="muted">สินค้าของร้าน</span><span>฿ ${money(b.store)}</span></div>
              <div class="muted" style="margin-top:3px">${count(b.qty)} ชิ้น · ${count(b.bills)} บิล</div>` : ''}`;
            tip.style.display = 'block';
            const cx = m.l + band * i + band / 2;
            const left = Math.min(Math.max(cx - tip.offsetWidth / 2, 0), W - tip.offsetWidth);
            tip.style.left = `${left}px`;
            tip.style.top = `${Math.max(y(b.value) - tip.offsetHeight - 10, 0)}px`;
        };
        const hide = () => { tip.style.display = 'none'; hover.setAttribute('width', 0); };
        host.querySelectorAll('[data-i]').forEach((n) => {
            n.addEventListener('mouseenter', () => show(Number(n.dataset.i)));
            n.addEventListener('click', () => show(Number(n.dataset.i)));
        });
        svg.addEventListener('mouseleave', hide);
    }

    // Redraw at the new width when the layout changes (sidebar, window, phone rotation).
    let lastW = 0;
    const ro = new ResizeObserver(() => {
        const w = el.clientWidth;
        if (data && !asTable && Math.abs(w - lastW) > 4) {
            lastW = w;
            chart(el.querySelector('.sales-chart-body'));
        }
    });
    ro.observe(el);
    load();
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
