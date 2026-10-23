import { money, count, escapeHtml, fmtDate, fmtDateTime } from './util.js';
import { alertDialog } from './dialog.js';

// Drill-down dialog shared by the dashboard cards and the report lines.
// load() resolves to { title, note?, link?, sections: [{ title, columns: [[key, label, type]], rows }] }.

const LINK_LABEL = {
    drawer: 'ไปที่เงินในลิ้นชัก / ปิดยอด', passbook: 'ไปที่สมุดบัญชีผู้ฝาก', withdraw: 'ไปที่เบิก / จ่ายเงิน',
    ret: 'ไปที่คืนสินค้าผู้ฝาก', purchase: 'ไปที่ซื้อสินค้าเข้าร้าน', inventory: 'ไปที่สต๊อกสินค้าของร้าน',
    receive: 'ไปที่รับฝากสินค้า', wages: 'ไปที่ค่าแรงพนักงาน', capital: 'ไปที่เงินลงทุน',
};

function cell(type, v) {
    if (v === null || v === undefined || v === '') return '<span class="muted">-</span>';
    if (type === 'money') return money(v);
    if (type === 'qty') return count(v);
    if (type === 'date') return escapeHtml(fmtDate(v));
    if (type === 'datetime') return escapeHtml(fmtDateTime(v));
    return escapeHtml(String(v));
}

function sectionHtml(sec) {
    const num = (c) => c[2] === 'money' || c[2] === 'qty';
    const totals = sec.columns.map((c) => (sec.total !== false && num(c) && c[0] !== 'cost_price' ? sec.rows.reduce((s, r) => s + (Number(r[c[0]]) || 0), 0) : null));
    return `
      <h3 style="margin:16px 0 8px;font-size:14.5px">${escapeHtml(sec.title)} <span class="muted" style="font-weight:400">(${count(sec.rows.length)})</span></h3>
      ${sec.rows.length ? `<div class="table-wrap" style="max-height:340px;border:1px solid var(--border);border-radius:10px"><table>
        <thead><tr>${sec.columns.map((c) => `<th class="${num(c) ? 'num' : ''}">${escapeHtml(c[1])}</th>`).join('')}</tr></thead>
        <tbody>${sec.rows.map((r) => `<tr>${sec.columns.map((c) => `<td class="${num(c) ? 'num' : ''}">${cell(c[2], r[c[0]])}</td>`).join('')}</tr>`).join('')}</tbody>
        ${sec.rows.length > 1 && totals.some((t) => t !== null) ? `<tfoot><tr style="font-weight:700">${sec.columns.map((c, i) => `<td class="${num(c) ? 'num' : ''}">${i === 0 ? 'รวม' : totals[i] === null ? '' : cell(c[2], totals[i])}</td>`).join('')}</tr></tfoot>` : ''}
      </table></div>` : '<div class="empty" style="padding:14px">ไม่มีรายการ</div>'}`;
}

export async function openDetailModal(load, navigate) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:880px"><div class="modal-body"><div class="empty">กำลังโหลด...</div></div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    document.addEventListener('keydown', onKey);
    // Closed with its buttons (or Esc) only, so a stray click outside never closes it.
    let d;
    try {
        d = await load();
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    box.querySelector('.modal').innerHTML = `
      <div class="modal-head"><h2>${escapeHtml(d.title)}</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
      <div class="modal-body" style="padding-top:0">
        ${d.note ? `<div class="hint" style="margin-top:12px"><div class="dot">i</div><div>${escapeHtml(d.note)}</div></div>` : ''}
        ${d.sections.map(sectionHtml).join('')}
      </div>
      <div class="modal-foot">
        <button type="button" class="btn secondary" data-x>ปิด</button>
        ${d.link && navigate ? `<button type="button" class="btn" data-link>${escapeHtml(LINK_LABEL[d.link] || 'ไปที่หน้าที่เกี่ยวข้อง')}</button>` : ''}
      </div>`;
    box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
    box.querySelector('[data-link]')?.addEventListener('click', () => { close(); navigate(d.link); });
}
