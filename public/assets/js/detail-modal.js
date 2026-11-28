import { money, count, escapeHtml, fmtDate, fmtDateTime } from './util.js';
import { alertDialog, promptDialog } from './dialog.js';
import { api } from './api.js';
import { skBlock } from './skeleton.js';

// Drill-down dialog shared by the dashboard cards and the report lines.
// load() resolves to { title, note?, link?, sections: [{ title, columns: [[key, label, type]], rows }] }.

const LINK_LABEL = {
    drawer: 'ไปที่เงินในลิ้นชัก / ปิดยอด', passbook: 'ไปที่สมุดบัญชีผู้ฝาก', withdraw: 'ไปที่เบิก / จ่ายเงิน',
    ret: 'ไปที่คืนสินค้าผู้ฝาก', purchase: 'ไปที่ซื้อสินค้าเข้าร้าน', inventory: 'ไปที่สต๊อกสินค้าของร้าน',
    receive: 'ไปที่รับฝากสินค้า', wages: 'ไปที่ค่าแรงพนักงาน', capital: 'ไปที่เงินลงทุน', posimport: 'ไปที่นำเข้ายอดขายจาก POS',
};

function cell(type, v) {
    if (v === null || v === undefined || v === '') return '<span class="muted">-</span>';
    if (type === 'money') return money(v);
    if (type === 'qty') return count(v);
    if (type === 'date') return escapeHtml(fmtDate(v));
    if (type === 'datetime') return escapeHtml(fmtDateTime(v));
    return escapeHtml(String(v));
}

const cancelButton = (line) => `<button type="button" class="btn secondary sm" data-count-cancel="${line}" title="ยกเลิกการนับที่ผิดพลาด ต้องระบุเหตุผล" style="color:var(--bad)">✕ ยกเลิกการนับ</button>`;

/**
 * A row's buttons: edit and cancel a count line (admin / manager on an approved count: r._line),
 * or cancel only (a count in the stock ledger: r._cancel). No column when no row has any.
 */
function actionCell(r, canEdit, column) {
    if (!column) return '';
    const edit = canEdit && r._line ? `<button type="button" class="btn secondary sm" data-count-line="${r._line}" title="แก้จำนวนที่นับได้ ต้องระบุเหตุผล">✎ แก้ไข</button> ` : '';
    const cancel = r._cancel || (canEdit && r._line);
    return `<td style="white-space:nowrap">${edit}${cancel ? cancelButton(cancel) : ''}</td>`;
}

function sectionHtml(sec, canEdit = false) {
    canEdit = canEdit && sec.rows.some((r) => r._line);
    const acts = canEdit || sec.rows.some((r) => r._cancel);
    const num = (c) => c[2] === 'money' || c[2] === 'qty';
    const totals = sec.columns.map((c) => (sec.total !== false && num(c) && c[0] !== 'cost_price' ? sec.rows.reduce((s, r) => s + (Number(r[c[0]]) || 0), 0) : null));
    return `
      <h3 style="margin:16px 0 8px;font-size:14.5px">${escapeHtml(sec.title)} <span class="muted" style="font-weight:400">(${count(sec.rows.length)})</span></h3>
      ${sec.rows.length ? `<div class="table-wrap" style="max-height:340px;border:1px solid var(--border);border-radius:10px"><table>
        <thead><tr>${sec.columns.map((c) => `<th class="${num(c) ? 'num' : ''}">${escapeHtml(c[1])}</th>`).join('')}${acts ? '<th></th>' : ''}</tr></thead>
        <tbody>${sec.rows.map((r) => (r._source
            // A row with its own records: click opens them in another dialog on top.
            ? `<tr class="drill-row" data-source="${escapeHtml(r._source)}" tabindex="0" title="กดเพื่อดูรายละเอียด">${sec.columns.map((c, i) => `<td class="${num(c) ? 'num' : ''}">${i === 0 ? `<span class="drill-link">${cell(c[2], r[c[0]])}</span> <span class="drill-arrow">›</span>` : cell(c[2], r[c[0]])}</td>`).join('')}${actionCell(r, canEdit, acts)}</tr>`
            : `<tr>${sec.columns.map((c) => `<td class="${num(c) ? 'num' : ''}">${cell(c[2], r[c[0]])}</td>`).join('')}${actionCell(r, canEdit, acts)}</tr>`)).join('')}</tbody>
        ${sec.rows.length > 1 && totals.some((t) => t !== null) ? `<tfoot><tr style="font-weight:700">${sec.columns.map((c, i) => `<td class="${num(c) ? 'num' : ''}">${i === 0 ? 'รวม' : totals[i] === null ? '' : cell(c[2], totals[i])}</td>`).join('')}${acts ? '<td></td>' : ''}</tr></tfoot>` : ''}
      </table></div>` : '<div class="empty" style="padding:14px">ไม่มีรายการ</div>'}`;
}

/**
 * opts.onChange runs after something was changed from this dialog (a sale bill corrected here),
 * so the page below can refresh in place.
 */
export async function openDetailModal(load, navigate, opts = {}) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:880px"><div class="modal-body">' + skBlock(5, 8) + '</div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    // Esc closes only the dialog on top (a row's records open over this one).
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
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
    /** Reads the records again after a change, keeping what is shown if that fails. */
    const reload = async () => {
        try { d = await load(); draw(); } catch (e) { /* keep what is shown */ }
    };
    const draw = () => {
    const y = box.querySelector('.modal-body')?.scrollTop || 0;
    box.querySelector('.modal').innerHTML = `
      <div class="modal-head"><h2>${escapeHtml(d.title)}</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
      <div class="modal-body" style="padding-top:0">
        ${d.note ? `<div class="hint" style="margin-top:12px"><div class="dot">i</div><div>${escapeHtml(d.note)}</div></div>` : ''}
        ${d.sections.map((s) => sectionHtml(s, !!d.count_edit)).join('')}
      </div>
      <div class="modal-foot">
        <button type="button" class="btn secondary" data-x>ปิด</button>
        ${d.count_edit && d.count_line ? `${cancelButton(d.count_line.id)}<button type="button" class="btn" data-count-one title="แก้จำนวนที่นับได้ ต้องระบุเหตุผล">✎ แก้ไขการนับ</button>` : ''}
        ${d.sale_edit ? '<button type="button" class="btn" data-sale-edit title="แก้รายการขายนี้ทันที โดยระบุสาเหตุ (เช่น พนักงานขายผิดสินค้า)">แก้ไขรายการขาย</button>' : ''}
        ${d.purchase_edit && navigate ? `<button type="button" class="btn" data-purchase-edit title="เปิดใบนี้ในฟอร์มแก้ไขที่หน้าซื้อสินค้าเข้าร้าน">✎ ${escapeHtml(d.purchase_edit.label)}</button>`
            : d.link && navigate ? `<button type="button" class="btn" data-link>${escapeHtml(LINK_LABEL[d.link] || 'ไปที่หน้าที่เกี่ยวข้อง')}</button>` : ''}
      </div>`;
    box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
    box.querySelector('[data-link]')?.addEventListener('click', () => { close(); navigate(d.link); });
    box.querySelector('[data-purchase-edit]')?.addEventListener('click', () => { close(); navigate('purchase', { edit: d.purchase_edit.id }); });
    box.querySelectorAll('[data-source]').forEach((tr) => {
        // Something changed from the dialog on top: this one and the page below read again.
        const onChange = async () => { await reload(); opts.onChange?.(); };
        const open = () => openDetailModal(async () => (await api.get(tr.dataset.source)).data, navigate && ((page, params) => { close(); navigate(page, params); }), { ...opts, onChange });
        // A button inside the row does its own job, not the row's.
        const own = (e) => e.target.closest('button');
        tr.addEventListener('click', (e) => { if (!own(e)) open(); });
        tr.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !own(e)) open(); });
    });
    // Cancel a wrong count: what it would do to the stock is shown first, a reason is required.
    const cancelCount = async (line) => {
        try {
            const p = (await api.post(`/stockcounts/lines/${line}/cancel`, { preview: true })).data;
            const diff = p.counted - p.system;
            const effect = p.later ? `สต๊อกตอนนี้ไม่เปลี่ยน (${p.stock_before} ชิ้น) เพราะมีการนับสินค้านี้ครั้งหลังกว่านี้แล้ว`
                : p.stock_after === p.stock_before ? `สต๊อกตอนนี้ไม่เปลี่ยน (${p.stock_before} ชิ้น)`
                    : `สต๊อกตอนนี้จะเปลี่ยนจาก ${p.stock_before} เป็น ${p.stock_after} ชิ้น${p.stock_after < 0 ? ' (ติดลบ ควรตรวจนับใหม่)' : ''}`;
            const signed = (n) => `${n > 0 ? '+' : ''}${n}`;
            // Counts still waiting for a manager: their system figure loses the cancelled change too.
            const waiting = (p.waiting || []).map((w) => `\nการนับ ${w.doc_no} ที่รอรับรอง: ยอดในระบบตอนนับ ${w.system_before} → ${w.system_after} ผลต่าง ${signed(w.counted - w.system_before)} → ${signed(w.counted - w.system_after)} (นับได้ ${w.counted} เท่าเดิม)`).join('');
            const reason = await promptDialog(
                `${p.name}: นับได้ ${p.counted} ในระบบตอนปรับ ${p.system} (${diff > 0 ? '+' : ''}${diff})\n${effect}${waiting}${p.round ? '\nรอบตรวจนับทั้งร้าน: ยกเลิกเฉพาะสินค้านี้ สินค้าอื่นในรอบไม่เปลี่ยน' : ''}\nยกเลิกแล้วกู้คืนไม่ได้\n\nระบุเหตุผลที่ยกเลิก`,
                { title: `ยกเลิกการนับ ${p.doc_no}?`, okText: 'ยกเลิกการนับ', cancelText: 'ไม่ยกเลิก', placeholder: 'เหตุผล (ต้องระบุ) เช่น นับผิดสินค้า กรอกจำนวนผิด', required: 'กรุณาระบุเหตุผลที่ยกเลิก', multiline: true, danger: true });
            if (reason === null) return;
            const res = (await api.post(`/stockcounts/lines/${line}/cancel`, { reason: reason.trim() })).data;
            alertDialog(`${res.name}: ${res.stock_after !== res.stock_before ? `สต๊อกเปลี่ยนจาก ${res.stock_before} เป็น ${res.stock_after} ชิ้น` : 'สต๊อกตอนนี้ไม่เปลี่ยน'}`, { title: `ยกเลิกการนับ ${res.doc_no} แล้ว` });
            await reload();
            opts.onChange?.();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    };
    box.querySelectorAll('[data-count-cancel]').forEach((b) => b.addEventListener('click', () => cancelCount(b.dataset.countCancel)));
    // Correct what was counted on a line of an approved count, with a reason.
    const editLine = async (line) => {
        const typed = await promptDialog(`${line.product} — ในระบบ ${line.system} นับได้ ${line.counted}\n\nจำนวนที่นับได้ที่ถูกต้อง`,
            { title: `แก้ไขการนับ ${d.title}`, okText: 'ถัดไป', value: String(line.counted), required: 'กรุณากรอกจำนวน' });
        if (typed === null) return;
        const counted = Number(typed.trim());
        if (!Number.isInteger(counted) || counted < 0) {
            alertDialog('จำนวนที่นับต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป', { tone: 'error' });
            return;
        }
        if (counted === line.counted) return;
        const delta = counted - line.counted;
        const reason = await promptDialog(
            `${line.product}: นับได้ ${line.counted} → ${counted} (${delta > 0 ? '+' : ''}${delta})\nถ้าเป็นการนับล่าสุดของสินค้านี้ สต๊อกตอนนี้จะเปลี่ยนตามส่วนต่าง ถ้ามีการนับครั้งหลังกว่าแล้ว สต๊อกตอนนี้ไม่เปลี่ยน\n\nระบุเหตุผลที่แก้ไข`,
            { title: 'ยืนยันแก้ไขการนับ?', okText: 'บันทึกการแก้ไข', placeholder: 'เหตุผล (ต้องระบุ) เช่น กรอกจำนวนผิด นับซ้ำแล้วได้ 12', required: 'กรุณาระบุเหตุผลที่แก้ไข', multiline: true, danger: true });
        if (reason === null) return;
        try {
            const res = (await api.post(`/stockcounts/lines/${line.id}/edit`, { counted, reason: reason.trim() })).data;
            alertDialog(`${res.name} นับได้ ${res.counted_before} → ${res.counted_after}` + (res.stock_after !== res.stock_before
                ? ` · สต๊อกตอนนี้เปลี่ยนจาก ${res.stock_before} เป็น ${res.stock_after} ชิ้น` : ' · สต๊อกตอนนี้ไม่เปลี่ยน (มีการนับครั้งหลังกว่านี้แล้ว)'), { title: `แก้ไขการนับ ${res.doc_no} แล้ว` });
            try { d = await load(); draw(); } catch (e) { /* keep what is shown */ }
            opts.onChange?.();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    };
    box.querySelectorAll('[data-count-line]').forEach((b) => b.addEventListener('click', () => {
        const r = d.sections.flatMap((s) => s.rows).find((x) => String(x._line) === b.dataset.countLine);
        if (r) editLine({ id: r._line, product: r.product, counted: Number(r.counted_qty), system: Number(r.system_qty) });
    }));
    box.querySelector('[data-count-one]')?.addEventListener('click', () => editLine(d.count_line));
    box.querySelector('[data-sale-edit]')?.addEventListener('click', async () => {
        const { openSaleEditor } = await import('./views/mysales.js');
        openSaleEditor(d.sale_edit.id, {
            user: d.sale_edit.user,
            onDone: async (saved) => {
                if (!saved) return;
                try { d = await load(); draw(); } catch (e) { /* keep what is shown */ }
                opts.onChange?.();
            },
        });
    });
    const m = box.querySelector('.modal-body');
    if (m) m.scrollTop = y;
    };
    draw();
}
