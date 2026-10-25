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
export function diffHtml(before, after) {
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
        <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num">เดิม</th><th class="num">ขอแก้เป็น</th><th class="num">รวมใหม่</th></tr></thead>
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

/** One correction request: who asked, why, what changes, and how it was decided. */
export function requestHtml(r) {
    return `
      <div class="card card-pad" style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
          <div>${statusPill(r.status)} <span class="muted" style="font-size:12.5px">ขอเมื่อ ${escapeHtml(fmtDateTime(r.requested_at))} โดย ${escapeHtml(r.requested_by_name || '-')}</span></div>
          <div style="font-size:13px">฿${money(r.total_before)} → <b>฿${money(r.total_after)}</b></div>
        </div>
        <div style="margin:8px 0;font-size:13.5px">เหตุผล: ${escapeHtml(r.reason)}</div>
        ${diffHtml(r.before, r.after)}
        ${r.reviewed_at && r.status !== 'cancelled' ? `<div class="muted" style="font-size:12.5px;margin-top:8px">
          ${r.status === 'approved' ? 'อนุมัติ' : 'ไม่อนุมัติ'}โดย ${escapeHtml(r.reviewed_by_name || '-')} เมื่อ ${escapeHtml(fmtDateTime(r.reviewed_at))}
          ${r.review_note ? ` · ${escapeHtml(r.review_note)}` : ''}</div>` : ''}
      </div>`;
}
