import { money, count, escapeHtml, fmtDateTime } from './util.js';

// Shared by ประวัติการขายของฉัน and the approval page: how a sale correction reads.

export const PAYMENT_LABEL = { cash: 'เงินสด', transfer: 'โอนเงิน', qr: 'สแกน QR-Code', card: 'บัตรเครดิต' };

export const EDIT_STATUS = {
    pending: ['warn', 'รออนุมัติแก้ไข'],
    approved: ['ok', 'อนุมัติแล้ว'],
    rejected: ['bad', 'ไม่อนุมัติ'],
    cancelled: ['muted', 'ยกเลิกคำขอ'],
};

export const statusPill = (status) => {
    const [tone, label] = EDIT_STATUS[status] || ['muted', status];
    return `<span class="pill ${tone}">${escapeHtml(label)}</span>`;
};

export const paymentsText = (payments) => (payments || [])
    .map((p) => `${PAYMENT_LABEL[p.method] || p.method} ${money(p.amount)}`).join(' + ') || '-';

/** Parses a server "Y-m-d H:i:s" the same way each time, so differences between two of them are right. */
export const serverTime = (s) => new Date(String(s).replace(' ', 'T')).getTime();

const lineKey = (it) => (it.sale_item_id ? `i${it.sale_item_id}` : `p${it.product_id}x${it.pack_size}`);
const packNote = (it) => (it.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${it.pack_size}</span>` : '');

/** Table of the sale's lines before → after, changed rows highlighted. */
export function diffHtml(before, after, toLabel = 'ขอแก้เป็น') {
    const rows = [];
    const afterBy = new Map(after.items.map((it) => [lineKey(it), it]));
    before.items.forEach((b) => {
        const a = afterBy.get(lineKey(b));
        afterBy.delete(lineKey(b));
        rows.push({ name: b.name, pack: b, price: b.unit_price, from: b.qty, to: a ? a.qty : 0 });
    });
    afterBy.forEach((a) => rows.push({ name: a.name, pack: a, price: a.unit_price, from: 0, to: a.qty, added: true }));
    const payChanged = paymentsText(before.payments) !== paymentsText(after.payments);
    return `
      <div class="table-wrap"><table>
        <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num">เดิม</th><th class="num">${escapeHtml(toLabel)}</th><th class="num">รวมใหม่</th></tr></thead>
        <tbody>${rows.map((r) => {
            const changed = r.from !== r.to;
            const tag = r.added ? ' <span class="pill ok">เพิ่ม</span>' : r.to === 0 ? ' <span class="pill bad">นำออก</span>' : '';
            return `<tr style="${changed ? 'background:var(--warn-bg)' : ''}">
              <td>${escapeHtml(r.name)}${packNote(r.pack)}${tag}</td>
              <td class="num">${money(r.price)}</td>
              <td class="num">${count(r.from)}</td>
              <td class="num" style="${changed ? 'font-weight:700' : ''}">${count(r.to)}</td>
              <td class="num">${money(r.price * r.to)}</td></tr>`;
        }).join('')}</tbody>
        <tfoot><tr><td colspan="2" style="font-weight:600">ยอดรวม</td><td class="num">฿${money(before.total_value)}</td><td></td>
          <td class="num" style="font-weight:700">฿${money(after.total_value)}</td></tr></tfoot>
      </table></div>
      <div style="margin-top:10px;font-size:13.5px;${payChanged ? 'background:var(--warn-bg);padding:8px 10px;border-radius:8px' : ''}">
        การชำระเงิน: <span class="muted">${escapeHtml(paymentsText(before.payments))}</span>
        ${payChanged ? ` → <b>${escapeHtml(paymentsText(after.payments))}</b>` : ' (ไม่เปลี่ยน)'}
      </div>`;
}

const signed = (n) => `${n > 0 ? '+' : n < 0 ? '−' : ''}฿${money(Math.abs(n))}`;

/**
 * What a correction does to the money taken: the bill's total, each payment method, from the
 * same figures the server checks (before / after payments). Used live in the edit form.
 */
export function computeImpact(before, after) {
    const sum = (ps) => (ps || []).reduce((m, p) => { m[p.method] = Math.round(((m[p.method] || 0) + Number(p.amount || 0)) * 100) / 100; return m; }, {});
    const b = sum(before.payments);
    const a = sum(after.payments);
    const methods = [...new Set([...Object.keys(b), ...Object.keys(a)])]
        .map((m) => ({ method: m, before: b[m] || 0, after: a[m] || 0, diff: Math.round(((a[m] || 0) - (b[m] || 0)) * 100) / 100 }))
        .filter((x) => Math.abs(x.diff) > 0.004);
    const totalDiff = Math.round((Number(after.total_value) - Number(before.total_value)) * 100) / 100;
    return {
        affected: Math.abs(totalDiff) > 0.004 || methods.length > 0,
        total_diff: totalDiff,
        methods,
        cash_diff: Math.round(((a.cash || 0) - (b.cash || 0)) * 100) / 100,
    };
}

/**
 * The special notice for a correction that changes the amount paid: what to do with the
 * customer's money, what the cash drawer should now hold, and whether that day's drawer
 * count or daily summary was already made (so those figures move after the fact).
 */
export function impactHtml(impact, { title = 'การแก้ไขนี้กระทบยอดชำระเงิน' } = {}) {
    if (!impact || !impact.affected) return '';
    const points = [];
    const t = Number(impact.total_diff);
    if (t < -0.004) points.push(`ยอดขายลดลง <b>${signed(t)}</b> — ต้องคืนเงินให้ลูกค้า หรือตรวจว่าลูกค้าชำระตามยอดเดิมไปแล้วหรือไม่`);
    else if (t > 0.004) points.push(`ยอดขายเพิ่มขึ้น <b>${signed(t)}</b> — ต้องตรวจว่าได้รับเงินส่วนเพิ่มจากลูกค้าจริง`);
    if (impact.methods?.length) {
        points.push(`ยอดแยกตามวิธีชำระเปลี่ยน: ${impact.methods.map((m) => `${escapeHtml(PAYMENT_LABEL[m.method] || m.method)} ฿${money(m.before)} → <b>฿${money(m.after)}</b> (${signed(m.diff)})`).join(' · ')}`);
    }
    const c = Number(impact.cash_diff);
    if (Math.abs(c) > 0.004) points.push(`เงินสดที่ควรมีในลิ้นชักของวันนั้น${c > 0 ? 'เพิ่มขึ้น' : 'ลดลง'} <b>${signed(c)}</b>`);
    if (impact.drawer_closed) points.push('<b>ปิดยอดลิ้นชักของวันนั้นไปแล้ว</b> — ยอดที่ควรมีและผลต่างเงินขาด/เกินของวันนั้นจะเปลี่ยนตาม');
    if (impact.closing_verified) points.push('<b>สรุปบัญชีรายวันของวันนั้นได้รับการยืนยันแล้ว</b> — ยอดส่งเงินที่ยืนยันไว้อาจไม่ตรงกับยอดขายใหม่ ต้องตรวจและแก้สรุปบัญชี');
    else if (impact.closing_done) points.push('<b>สรุปบัญชีรายวัน/ส่งเงินของวันนั้นบันทึกไปแล้ว</b> — ต้องตรวจยอดส่งเงินอีกครั้ง');
    return `
      <div class="impact-box" role="alert">
        <div class="impact-title">⚠ ${escapeHtml(title)}</div>
        <ul>${points.map((p) => `<li>${p}</li>`).join('')}</ul>
      </div>`;
}

/** One correction: a cashier's request (who asked, why, how it was decided) or a manager's own edit. */
export function requestHtml(r) {
    const direct = Number(r.direct) === 1;
    const affected = r.impact?.affected;
    return `
      <div class="card card-pad" style="margin-top:12px${affected && r.status !== 'rejected' && r.status !== 'cancelled' ? ';border-color:var(--bad)' : ''}">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
          <div>${direct ? '<span class="pill warn">แก้ไขโดยผู้จัดการ</span>' : statusPill(r.status)}
            ${affected ? ' <span class="pill bad">กระทบยอดชำระ</span>' : ''}
            <span class="muted" style="font-size:12.5px">${direct ? 'แก้ไขเมื่อ' : 'ขอเมื่อ'} ${escapeHtml(fmtDateTime(r.requested_at))} โดย ${escapeHtml(r.requested_by_name || '-')}</span></div>
          <div style="font-size:13px">฿${money(r.total_before)} → <b>฿${money(r.total_after)}</b></div>
        </div>
        <div style="margin:8px 0;font-size:13.5px">${direct ? 'สาเหตุที่แก้ไข' : 'เหตุผล'}: ${escapeHtml(r.reason)}</div>
        ${r.status === 'rejected' || r.status === 'cancelled' ? '' : impactHtml(r.impact, {
            title: direct ? 'การแก้ไขนี้กระทบยอดชำระเงิน' : r.status === 'pending' ? 'ถ้าอนุมัติ จะกระทบยอดชำระเงิน' : 'การแก้ไขนี้กระทบยอดชำระเงิน',
        })}
        ${diffHtml(r.before, r.after, direct ? 'แก้เป็น' : 'ขอแก้เป็น')}
        ${!direct && r.reviewed_at && r.status !== 'cancelled' ? `<div class="muted" style="font-size:12.5px;margin-top:8px">
          ${r.status === 'approved' ? 'อนุมัติ' : 'ไม่อนุมัติ'}โดย ${escapeHtml(r.reviewed_by_name || '-')} เมื่อ ${escapeHtml(fmtDateTime(r.reviewed_at))}
          ${r.review_note ? ` · ${escapeHtml(r.review_note)}` : ''}</div>` : ''}
      </div>`;
}
