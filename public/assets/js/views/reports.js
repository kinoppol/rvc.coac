import { api } from '../api.js';
import { money, count, escapeHtml, todayISO } from '../util.js';

const TYPES = [
    { key: 'receive', label: 'รายการรับของฝากขาย' },
    { key: 'return', label: 'รายการคืนของฝากขาย' },
    { key: 'sales', label: 'สรุปยอดขายของฝากขาย' },
    { key: 'detail', label: 'รายละเอียดการขายรายผู้ฝาก' },
    { key: 'fee', label: 'รายได้ค่าธรรมเนียมของร้าน' },
];
const RANGES = [
    { key: 'today', label: 'วันนี้' }, { key: '7d', label: '7 วันล่าสุด' },
    { key: 'month', label: 'เดือนนี้' }, { key: 'term', label: 'ภาคเรียนนี้' },
];

export async function render(container, { permissions }) {
    let type = 'receive';
    let range = 'today';
    let from = todayISO();
    let to = todayISO();
    let consignorId = null;
    let options = [];
    const canExport = permissions.includes('reports.export');

    async function load() {
        if (type === 'detail' && !options.length) {
            options = (await api.get('/consignors/options')).data;
            consignorId = options[0]?.id ?? null;
        }
        const params = new URLSearchParams({ range });
        if (range === 'custom' || (from && to)) { params.set('from', from); params.set('to', to); }
        if (type === 'detail' && consignorId) params.set('consignor_id', consignorId);

        const res = await api.get(`/reports/${type}?${params.toString()}`);
        draw(res);
    }

    function draw(res) {
        container.innerHTML = `
        <div class="tabs">${TYPES.map((t) => `<button type="button" class="tab-btn ${type === t.key ? 'active' : ''}" data-type="${t.key}">${escapeHtml(t.label)}</button>`).join('')}</div>
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <div class="actions-row">
              ${RANGES.map((r) => `<button type="button" class="tab-btn ${range === r.key ? 'active' : ''}" data-range="${r.key}">${r.label}</button>`).join('')}
              <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${from}" style="padding:6px 8px"></label>
              <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${to}" style="padding:6px 8px"></label>
              ${type === 'detail' ? `<select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.name)}</option>`).join('')}</select>` : ''}
            </div>
            <div class="actions-row">
              <button type="button" class="btn secondary" onclick="window.print()">พิมพ์รายงาน</button>
              ${canExport ? `<a class="btn" href="${api.downloadUrl(`/reports/${type}/export?range=${range}&from=${from}&to=${to}${type === 'detail' && consignorId ? '&consignor_id=' + consignorId : ''}`)}">ส่งออก Excel</a>` : ''}
            </div>
          </div>
        </section>
        <section class="card">
          <div class="table-wrap">${renderTable(res.data)}</div>
        </section>`;

        container.querySelectorAll('[data-type]').forEach((btn) => btn.addEventListener('click', () => { type = btn.dataset.type; load(); }));
        container.querySelectorAll('[data-range]').forEach((btn) => btn.addEventListener('click', () => { range = btn.dataset.range; load(); }));
        container.querySelector('#f-from').addEventListener('change', (e) => { from = e.target.value; range = 'custom'; load(); });
        container.querySelector('#f-to').addEventListener('change', (e) => { to = e.target.value; range = 'custom'; load(); });
        const selConsignor = container.querySelector('#sel-consignor');
        if (selConsignor) selConsignor.addEventListener('change', (e) => { consignorId = Number(e.target.value); load(); });
    }

    function renderTable(rows) {
        if (!rows.length) return `<div class="empty">ไม่มีข้อมูลในช่วงเวลานี้</div>`;
        const headers = Object.keys(rows[0]);
        return `<table>
          <thead><tr>${headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
          <tbody>${rows.map((r) => `<tr>${headers.map((h) => `<td class="${typeof r[h] === 'number' || !isNaN(r[h]) && h !== 'date' ? '' : ''}">${escapeHtml(formatCell(h, r[h]))}</td>`).join('')}</tr>`).join('')}</tbody>
        </table>`;
    }

    function formatCell(key, val) {
        if (val === null || val === undefined) return '';
        if (/value|fee|revenue|received/.test(key) && !isNaN(val)) return money(val);
        if (/qty|count/.test(key) && !isNaN(val)) return count(val);
        return val;
    }

    await load();
}
