import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { printReport } from '../print-slip.js';
import { openDetailModal } from '../detail-modal.js';

// Reports grouped by the part of the system they cover.
const GROUPS = [
    { label: 'ภาพรวมและกำไร', types: [
        { key: 'statement', label: 'ผลประกอบการ (รายได้ − รายจ่าย)' },
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
        { key: 'capital', label: 'งบลงทุนและกำไรสุทธิรายเดือน' },
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
const NO_TOTAL = /price|rate|unit_cost|capital_balance|retained_profit/;

export async function render(container, { permissions, navigate }) {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(PREF) || '{}'); } catch (e) { /* private mode */ }
    let type = TYPES.some((t) => t.key === saved.type) ? saved.type : 'statement';
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
        <section class="card card-pad report-picker">
          <div class="report-row">
            <label class="report-type">รายงาน
              <select id="sel-type">${GROUPS.map((g) => `<optgroup label="${escapeHtml(g.label)}">${g.types.map((t) => `<option value="${t.key}" ${type === t.key ? 'selected' : ''}>${escapeHtml(t.label)}</option>`).join('')}</optgroup>`).join('')}</select>
            </label>
            <div class="actions-row" style="margin-left:auto">
              <button type="button" class="btn secondary" id="btn-print">พิมพ์รายงาน</button>
              ${canExport ? `<a class="btn" href="${api.downloadUrl(`/reports/${type}/export?${query()}`)}">ส่งออก Excel</a>` : ''}
            </div>
          </div>
          <div class="report-row">
            ${m.snapshot ? '<span class="muted">ข้อมูล ณ ปัจจุบัน (ไม่ขึ้นกับช่วงเวลา)</span>' : `
            <label>ช่วงเวลา
              <select id="sel-range">${RANGES.map((r) => `<option value="${r.key}" ${range === r.key ? 'selected' : ''}>${r.label}</option>`).join('')}
                <option value="custom" ${range === 'custom' ? 'selected' : ''}>กำหนดเอง</option></select></label>
            <label>ตั้งแต่ <input type="date" id="f-from" value="${from}"></label>
            <label>ถึง <input type="date" id="f-to" value="${to}"></label>`}
            ${m.grouped ? `<div class="tabs">${PERIOD_GROUPS.map(([k, l]) => `<button type="button" class="tab-btn ${group === k ? 'active' : ''}" data-group="${k}">${l}</button>`).join('')}</div>` : ''}
            ${type === 'detail' ? `<label>ผู้ฝาก <select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.name)}</option>`).join('')}</select></label>` : ''}
          </div>
        </section>
        ${type === 'profit' ? profitCards(res.data) : ''}
        <section class="card">
          <div class="card-head"><h2>${escapeHtml(m.label)}${m.snapshot ? '' : ` <span class="muted" style="font-size:13px;font-weight:400">${escapeHtml(fmtDate(from))}${from !== to ? ` – ${escapeHtml(fmtDate(to))}` : ''}</span>`}</h2></div>
          <div id="report-body">${type === 'statement' ? statementHtml(res.data) : `<div class="table-wrap" style="max-height:70vh">${renderTable(res.data)}</div>`}</div>
          ${type === 'profit' ? `<div class="muted report-note" style="font-size:12px;padding:10px 16px">รายได้รวมของร้าน = ค่าฝากขาย + ค่าทำข้อมูลสุทธิ + กำไรขั้นต้นสินค้าของร้าน ·
            กำไรสุทธิ = รายได้รวม − ค่าวัสดุใช้ในร้าน − ค่าแรงที่จ่าย (ยอดขายสินค้าฝากขายเป็นเงินของผู้ฝาก ไม่นับเป็นรายได้ของร้าน)</div>` : ''}
        </section>`;

        container.querySelector('#sel-type').addEventListener('change', (e) => { type = e.target.value; load(); });
        container.querySelector('#sel-range')?.addEventListener('change', (e) => {
            range = e.target.value;
            if (range === 'custom') container.querySelector('#f-from').focus();
            else load();
        });
        container.querySelectorAll('[data-group]').forEach((btn) => btn.addEventListener('click', () => { group = btn.dataset.group; load(); }));
        container.querySelector('#f-from')?.addEventListener('change', (e) => { from = e.target.value; range = 'custom'; load(); });
        container.querySelector('#f-to')?.addEventListener('change', (e) => { to = e.target.value; range = 'custom'; load(); });
        container.querySelector('#sel-consignor')?.addEventListener('change', (e) => { consignorId = Number(e.target.value); load(); });
        container.querySelector('#btn-print').addEventListener('click', print);
        container.querySelectorAll('[data-src]').forEach((b) => b.addEventListener('click', () => {
            openDetailModal(async () => (await api.get(`/reports/statement/source?line=${encodeURIComponent(b.dataset.src)}&${query()}`)).data, navigate);
        }));
    }

    /** Prints just the report (not the menus and buttons) on its own page. */
    function print() {
        const m = meta();
        const table = container.querySelector('#report-body table');
        const note = container.querySelector('.report-note');
        const consignor = type === 'detail' ? options.find((o) => o.id === consignorId) : null;
        const period = [
            m.snapshot ? 'ข้อมูล ณ วันที่พิมพ์' : `${fmtDate(from)}${from !== to ? ` – ${fmtDate(to)}` : ''}`,
            m.grouped ? PERIOD_GROUPS.find(([k]) => k === group)[1] : '',
            consignor ? `ผู้ฝาก ${consignor.name}` : '',
        ].filter(Boolean).join(' · ');
        printReport({
            title: m.label,
            period,
            body: `${type === 'profit' ? container.querySelector('.kpi-grid')?.outerHTML || '' : ''}
              ${table ? table.outerHTML : '<p>ไม่มีข้อมูลในช่วงเวลานี้</p>'}
              ${note ? `<div class="note">${note.innerHTML}</div>` : ''}`,
            landscape: (table?.querySelectorAll('thead th').length || 0) > 7,
        });
    }

    /** ผลประกอบการ as a statement: income lines, expense lines, then profit — this period next to the one before. */
    function statementHtml(rows) {
        if (!rows.length) return '<div class="empty">ไม่มีข้อมูล</div>';
        const prev = rows[0].prev_period.split(' – ');
        const amount = (v, bold) => `<b style="${bold ? '' : 'font-weight:400;'}${Number(v) < 0 ? 'color:var(--bad)' : ''}">${Number(v) < 0 ? '(' : ''}${money(Math.abs(v))}${Number(v) < 0 ? ')' : ''}</b>`;
        // Green when the change is good for the store: more income, less expense.
        const change = (r) => (r.change_percent === null ? '<span class="muted">–</span>'
            : `<span style="color:var(${(r.change_percent >= 0) !== (r.section === 'รายจ่าย') ? '--ok' : '--bad'})">${r.change_percent >= 0 ? '▲' : '▼'} ${Math.abs(r.change_percent).toFixed(1)}%</span>`);
        let section = '';
        const body = rows.map((r) => {
            const total = /^รวม|กำไร/.test(r.item);
            const info = r.section === 'ข้อมูลประกอบ';
            const head = r.section !== section ? `<tr><td colspan="5" style="padding-top:16px;font-weight:700;border-top:${section ? '1px solid var(--border)' : '0'}">${escapeHtml(r.section)}</td></tr>` : '';
            section = r.section;
            const fmt = (v) => (r.item === 'จำนวนบิลขาย' ? count(v) : amount(v, total));
            const profitRow = r.section === 'ผลประกอบการ';
            return `${head}<tr style="${profitRow ? 'background:var(--soft);font-size:16px' : ''}${info ? ';color:var(--muted)' : ''}">
              <td style="padding-left:${total ? 14 : 30}px;${total ? 'font-weight:700' : ''}">${escapeHtml(r.item)}</td>
              <td class="num" style="${total ? 'border-top:1px solid var(--text)' : ''}${profitRow ? ';border-bottom:3px double var(--text)' : ''}">${fmt(r.amount)}</td>
              <td class="num muted">${fmt(r.prev_amount)}</td>
              <td class="num">${info ? '' : change(r)}</td>
              <td class="st-note">${escapeHtml(r.note || '')}${r.key ? ` <button type="button" class="link-btn" data-src="${escapeHtml(r.key)}">ดูที่มา ›</button>` : ''}</td></tr>`;
        }).join('');
        return `<div class="table-wrap"><table>
          <thead><tr><th>รายการ</th><th class="num">${escapeHtml(fmtDate(from))}${from !== to ? ` – ${escapeHtml(fmtDate(to))}` : ''} (บาท)</th>
            <th class="num">ช่วงก่อนหน้า ${escapeHtml(fmtDate(prev[0]))}${prev[0] !== prev[1] ? ` – ${escapeHtml(fmtDate(prev[1]))}` : ''}</th><th class="num">เปลี่ยนแปลง</th><th>คำอธิบาย / ที่มา</th></tr></thead>
          <tbody>${body}</tbody>
        </table></div>
        <div class="muted report-note" style="font-size:12px;padding:10px 16px">รายได้ของร้านจากสินค้าฝากขายคือค่าฝากขายและค่าทำข้อมูลเท่านั้น ยอดขายสินค้าฝากขายส่วนที่เหลือเป็นเงินของผู้ฝาก ·
          ค่าวัสดุใช้ในร้านนับตามวันที่ในใบเสร็จ · ค่าแรงนับตามวันที่จ่าย · ตัวเลขในวงเล็บคือติดลบ</div>`;
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
