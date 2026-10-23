import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';

// Reports grouped by the part of the system they cover.
const GROUPS = [
    { label: 'ภาพรวมและกำไร', types: [
        { key: 'profit', label: 'กำไรขาดทุน (หลังหักค่าแรง)', grouped: true },
        { key: 'payments', label: 'ยอดขายตามวิธีชำระเงิน', grouped: true },
        { key: 'fee', label: 'รายได้ค่าธรรมเนียมของร้าน' },
    ]},
    { label: 'การขายและเงินสด', types: [
        { key: 'cashiers', label: 'ยอดขายตามพนักงาน' },
        { key: 'drawers', label: 'เงินในลิ้นชัก / ปิดยอด' },
        { key: 'after_hours', label: 'ขายนอกเวลาทำการ' },
        { key: 'pos_imports', label: 'นำเข้ายอดขายจาก POS' },
    ]},
    { label: 'ผู้ฝากขาย', types: [
        { key: 'sales', label: 'สรุปยอดขายของฝากขาย' },
        { key: 'detail', label: 'รายละเอียดการขายรายผู้ฝาก' },
        { key: 'receive', label: 'รายการรับของฝากขาย' },
        { key: 'return', label: 'รายการคืนของฝากขาย' },
        { key: 'withdrawals', label: 'เบิก/จ่ายเงินผู้ฝาก' },
        { key: 'balances', label: 'ยอดค้างจ่ายผู้ฝาก' },
        { key: 'consign_stock', label: 'สินค้าฝากขายคงเหลือ', snapshot: true },
    ]},
    { label: 'สินค้าของร้านและค่าใช้จ่าย', types: [
        { key: 'store_sales', label: 'ยอดขาย/กำไรสินค้าของร้าน' },
        { key: 'store_stock', label: 'สต๊อกสินค้าของร้าน', snapshot: true },
        { key: 'purchases', label: 'รายการซื้อเข้าร้าน' },
        { key: 'wages', label: 'ค่าแรงพนักงาน' },
        { key: 'stock_counts', label: 'ผลการตรวจนับสต๊อก' },
    ]},
];
const TYPES = GROUPS.flatMap((g) => g.types);
const RANGES = [
    { key: 'today', label: 'วันนี้' }, { key: 'week', label: 'สัปดาห์นี้' }, { key: '7d', label: '7 วันล่าสุด' },
    { key: 'month', label: 'เดือนนี้' }, { key: 'last_month', label: 'เดือนก่อน' }, { key: 'term', label: 'ภาคเรียนนี้' },
    { key: 'year', label: 'ปีนี้' },
];
const PERIOD_GROUPS = [['day', 'รายวัน'], ['week', 'รายสัปดาห์'], ['month', 'รายเดือน']];
const PREF = 'reports.view';

// Amount and count columns get number formatting; the "รวม" row adds them up, except
// prices and rates.
const isMoney = (k) => /value|fee|revenue|received|sales|cost|profit|price|income|cash|transfer|qr|card|unknown_method|diff|paid|balance/.test(k) && !/count|items|bills|cashier/.test(k);
const isCount = (k) => /qty|count|stock$|bills|items|units/.test(k);
const NO_TOTAL = /price|rate|unit_cost/;

export async function render(container, { permissions }) {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(PREF) || '{}'); } catch (e) { /* private mode */ }
    let type = TYPES.some((t) => t.key === saved.type) ? saved.type : 'profit';
    let range = RANGES.some((r) => r.key === saved.range) ? saved.range : 'month';
    let group = PERIOD_GROUPS.some(([k]) => k === saved.group) ? saved.group : 'day';
    let from = todayISO();
    let to = todayISO();
    let consignorId = null;
    let options = [];
    let res = null;
    const canExport = permissions.includes('reports.export');
    const meta = () => TYPES.find((t) => t.key === type);

    function remember() {
        try { localStorage.setItem(PREF, JSON.stringify({ type, range: range === 'custom' ? 'month' : range, group })); } catch (e) { /* private mode */ }
    }

    function query() {
        const params = new URLSearchParams({ range });
        if (range === 'custom') { params.set('from', from); params.set('to', to); }
        if (meta().grouped) params.set('group', group);
        if (type === 'detail' && consignorId) params.set('consignor_id', consignorId);
        return params.toString();
    }

    async function load() {
        remember();
        if (type === 'detail' && !options.length) {
            options = (await api.get('/consignors/options')).data;
            consignorId = options[0]?.id ?? null;
        }
        res = await api.get(`/reports/${type}?${query()}`);
        from = res.from;
        to = res.to;
        draw();
    }

    function draw() {
        const m = meta();
        container.innerHTML = `
        <section class="card card-pad" style="display:flex;flex-direction:column;gap:10px">
          ${GROUPS.map((g) => `
            <div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap">
              <div class="muted" style="font-size:12px;font-weight:600;min-width:170px">${escapeHtml(g.label)}</div>
              <div class="tabs">${g.types.map((t) => `<button type="button" class="tab-btn ${type === t.key ? 'active' : ''}" data-type="${t.key}">${escapeHtml(t.label)}</button>`).join('')}</div>
            </div>`).join('')}
        </section>
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <div class="actions-row">
              ${m.snapshot ? '<span class="muted">ข้อมูล ณ ปัจจุบัน</span>' : `
              ${RANGES.map((r) => `<button type="button" class="tab-btn ${range === r.key ? 'active' : ''}" data-range="${r.key}">${r.label}</button>`).join('')}
              <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${from}" style="padding:6px 8px"></label>
              <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${to}" style="padding:6px 8px"></label>`}
              ${m.grouped ? `<div class="tabs" style="margin-left:6px">${PERIOD_GROUPS.map(([k, l]) => `<button type="button" class="tab-btn ${group === k ? 'active' : ''}" data-group="${k}">${l}</button>`).join('')}</div>` : ''}
              ${type === 'detail' ? `<select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.name)}</option>`).join('')}</select>` : ''}
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" onclick="window.print()">พิมพ์รายงาน</button>
              ${canExport ? `<a class="btn" href="${api.downloadUrl(`/reports/${type}/export?${query()}`)}">ส่งออก Excel</a>` : ''}
            </div>
          </div>
        </section>
        ${type === 'profit' ? profitCards(res.data) : ''}
        <section class="card">
          <div class="card-head"><h2>${escapeHtml(m.label)}${m.snapshot ? '' : ` <span class="muted" style="font-size:13px;font-weight:400">${escapeHtml(fmtDate(from))}${from !== to ? ` – ${escapeHtml(fmtDate(to))}` : ''}</span>`}</h2></div>
          <div class="table-wrap" style="max-height:70vh">${renderTable(res.data)}</div>
          ${type === 'profit' ? `<div class="muted" style="font-size:12px;padding:10px 16px">รายได้รวมของร้าน = ค่าฝากขาย + ค่าทำข้อมูลสุทธิ + กำไรขั้นต้นสินค้าของร้าน ·
            กำไรสุทธิ = รายได้รวม − ค่าวัสดุใช้ในร้าน − ค่าแรงที่จ่าย (ยอดขายสินค้าฝากขายเป็นเงินของผู้ฝาก ไม่นับเป็นรายได้ของร้าน)</div>` : ''}
        </section>`;

        container.querySelectorAll('[data-type]').forEach((btn) => btn.addEventListener('click', () => { type = btn.dataset.type; load(); }));
        container.querySelectorAll('[data-range]').forEach((btn) => btn.addEventListener('click', () => { range = btn.dataset.range; load(); }));
        container.querySelectorAll('[data-group]').forEach((btn) => btn.addEventListener('click', () => { group = btn.dataset.group; load(); }));
        container.querySelector('#f-from')?.addEventListener('change', (e) => { from = e.target.value; range = 'custom'; load(); });
        container.querySelector('#f-to')?.addEventListener('change', (e) => { to = e.target.value; range = 'custom'; load(); });
        container.querySelector('#sel-consignor')?.addEventListener('change', (e) => { consignorId = Number(e.target.value); load(); });
    }

    function profitCards(rows) {
        const sum = (k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0);
        const net = sum('net_profit');
        return `
        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">ยอดขายรวม</div><div class="val">฿ ${money(sum('total_sales'))}</div>
            <div class="sub">${count(sum('bills'))} บิล · ฝากขาย ฿ ${money(sum('consign_sales'))} · ของร้าน ฿ ${money(sum('store_sales'))}</div></div>
          <div class="kpi"><div class="lbl">รายได้รวมของร้าน</div><div class="val">฿ ${money(sum('total_income'))}</div>
            <div class="sub">ค่าฝากขาย ฿ ${money(sum('consign_fee'))} · ค่าทำข้อมูล ฿ ${money(sum('listing_fee_net'))} · กำไรสินค้าของร้าน ฿ ${money(sum('store_gross_profit'))}</div></div>
          <div class="kpi"><div class="lbl">ค่าใช้จ่าย</div><div class="val">฿ ${money(sum('supply_cost') + sum('wage_cost'))}</div>
            <div class="sub">ค่าแรง ฿ ${money(sum('wage_cost'))} · วัสดุใช้ในร้าน ฿ ${money(sum('supply_cost'))}</div></div>
          <div class="kpi"><div class="lbl">กำไรสุทธิ (หลังหักค่าแรง)</div>
            <div class="val" style="color:var(${net < 0 ? '--bad' : '--ok'})">${net < 0 ? '−' : ''}฿ ${money(Math.abs(net))}</div>
            <div class="sub">${net < 0 ? 'ขาดทุน' : 'กำไร'}ในช่วงที่เลือก</div></div>
        </div>`;
    }

    function renderTable(rows) {
        if (!rows.length) return `<div class="empty">ไม่มีข้อมูลในช่วงเวลานี้</div>`;
        const labels = res.labels || {};
        const headers = Object.keys(rows[0]);
        const numeric = (h) => isMoney(h) || isCount(h);
        const totals = headers.map((h) => (numeric(h) && !NO_TOTAL.test(h) ? rows.reduce((s, r) => s + (Number(r[h]) || 0), 0) : null));
        return `<table>
          <thead><tr>${headers.map((h) => `<th class="${numeric(h) ? 'num' : ''}">${escapeHtml(labels[h] || h)}</th>`).join('')}</tr></thead>
          <tbody>${rows.map((r) => `<tr>${headers.map((h) => `<td class="${numeric(h) ? 'num' : ''}"${isMoney(h) && Number(r[h]) < 0 ? ' style="color:var(--bad)"' : ''}>${escapeHtml(formatCell(h, r[h]))}</td>`).join('')}</tr>`).join('')}</tbody>
          ${rows.length > 1 && totals.some((t) => t !== null) ? `<tfoot><tr style="font-weight:700">${headers.map((h, i) => `<td class="${numeric(h) ? 'num' : ''}">${i === 0 ? 'รวม' : totals[i] === null ? '' : escapeHtml(formatCell(h, totals[i]))}</td>`).join('')}</tr></tfoot>` : ''}
        </table>`;
    }

    function formatCell(key, val) {
        if (val === null || val === undefined) return '';
        if (isMoney(key) && val !== '' && !isNaN(val)) return money(val);
        if (isCount(key) && val !== '' && !isNaN(val)) return count(val);
        if (typeof val === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(val)) return fmtDateTime(val);
        if (typeof val === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(val)) return fmtDate(val);
        return val;
    }

    await load();
}
