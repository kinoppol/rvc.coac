import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { alertDialog, choiceDialog, confirmDialog } from '../dialog.js';
import { PAYMENT_LABEL, statusPill, paymentsText, serverTime, requestHtml, computeImpact, impactHtml } from '../sale-edit.js';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n) => {
    const [y, m, d] = todayISO().split('-').map(Number);
    return iso(new Date(y, m - 1, d - n));
};

/**
 * ประวัติการขายของฉัน: the cashier's own POS sales by day. Within a few minutes of a
 * sale (admin setting) they may ask to correct it — quantities, lines out or in, how it
 * was paid; the sale changes only when a manager or admin approves.
 * Managers and admins may also correct a cashier's sale themselves, any day, with the
 * reason on record; a change to the amount paid is flagged and must be acknowledged.
 */
/**
 * Correct one sale from anywhere (e.g. a bill opened from a product's stock card), with the same
 * editor as the sales history. onDone(saved) runs when the editor closes.
 */
export async function openSaleEditor(saleId, { user, onDone = () => {} }) {
    const host = document.createElement('div');
    document.body.appendChild(host);
    await render(host, { user, params: { editSale: saleId, onDone } });
}

export async function render(container, { user, params = {} }) {
    // Solo: only the editor for params.editSale (no list); the host goes away when it closes.
    const solo = params.editSale ? { id: params.editSale, onDone: params.onDone || (() => {}), saved: false, opened: false } : null;
    let from = todayISO();
    let to = from;
    // Admins and managers may look at other cashiers' sales: '' = mine, 'all', or a user id.
    let cashier = '';
    let data = null;
    let skew = 0; // server clock minus this browser's, for the countdowns
    let detail = null; // the sale open on the detail modal
    let edit = null; // the correction being written: { sale, direct, items, payments, reason, ack, search, results, busy, error }
    let timer = null;
    let onlyNeg = false; // show only sales that took a product's stock below zero

    // A sale line that took the product's stock below 0 (recorded at the sale, migration 0080),
    // or, before that migration, a product in the sale that is below 0 now.
    const negCount = (s) => (s.neg_lines === null || s.neg_lines === undefined ? Number(s.neg_now) : Number(s.neg_lines)) || 0;

    const nowServer = () => Date.now() + skew;
    const left = (until) => Math.max(0, Math.floor((serverTime(until) - nowServer()) / 1000));
    const mmss = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

    async function load() {
        const res = await api.get(`/sales/mine?from=${from}&to=${to}${cashier ? `&cashier_id=${encodeURIComponent(cashier)}` : ''}`);
        data = res;
        skew = serverTime(res.now) - Date.now();
        draw();
    }

    function startTicks() {
        clearInterval(timer);
        timer = setInterval(() => {
            if (!container.isConnected) { clearInterval(timer); return; }
            let expired = false;
            container.querySelectorAll('[data-until]').forEach((el) => {
                const s = left(el.dataset.until);
                el.textContent = mmss(s);
                if (s === 0) expired = true;
            });
            if (expired && !edit) load();
        }, 1000);
    }

    const ownSale = (s) => String(s.cashier_id) === String(user.id);
    /** A manager / admin correcting the sale themself (not their own sale, unless admin). */
    const canDirect = (s) => data.direct_edit && !Number(s.pos_import) && s.edit_status !== 'pending'
        && !(s.requests || []).some((r) => r.status === 'pending') && (!ownSale(s) || user.role === 'admin');

    /** A payment method picked by mistake: within a few minutes the cashier changes it alone, no approval. */
    async function fixPayment(id) {
        const s = data.data.find((r) => String(r.id) === String(id));
        if (!s) return;
        const choices = data.payment_methods
            .filter((m) => !(s.methods.length === 1 && s.methods[0] === m.key))
            .map((m) => ({ key: m.key, label: `${m.label} ฿${money(s.total_value)}` }));
        const method = await choiceDialog(
            `${s.doc_no} · ยอดขาย ฿${money(s.total_value)}\nเดิม: ${s.methods.map((m) => PAYMENT_LABEL[m] || m).join(' + ') || '-'}\nเลือกวิธีที่ลูกค้าชำระจริง (ทั้งยอด)`,
            choices,
            { title: 'แก้วิธีชำระเงิน' },
        );
        if (!method) return;
        try {
            await api.post(`/sales/${s.id}/payment-fix`, { method });
        } catch (err) {
            alertDialog(err.message, { title: 'แก้วิธีชำระไม่ได้', tone: 'error' });
        }
        load();
    }

    function draw() {
        if (solo) {
            if (!edit) {
                if (solo.opened) { container.remove(); solo.onDone(solo.saved); }
                return;
            }
            solo.opened = true;
            container.innerHTML = editModal();
            wire();
            startTicks();
            return;
        }
        const d = data;
        const negRows = d.data.filter((s) => negCount(s) > 0);
        const noAfter = d.data.some((s) => s.neg_lines === null || s.neg_lines === undefined);
        if (onlyNeg && !negRows.length) onlyNeg = false;
        const rows = onlyNeg ? negRows : d.data;
        const self = String(d.cashier_id) === String(user.id);
        const picked = d.cashiers?.find((c) => String(c.id) === String(d.cashier_id));
        const heading = self ? 'การขายของฉัน' : d.cashier_id === 'all' ? 'การขายของพนักงานทุกคน' : `การขายของ ${picked?.full_name || 'พนักงาน'}`;
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row">
            <button type="button" class="tab-btn ${from === todayISO() && to === from ? 'active' : ''}" data-range="0">วันนี้</button>
            <button type="button" class="tab-btn ${from === daysAgo(1) && to === from ? 'active' : ''}" data-range="1">เมื่อวาน</button>
            <button type="button" class="tab-btn ${from === daysAgo(6) && to === todayISO() ? 'active' : ''}" data-range="7">7 วันล่าสุด</button>
            <button type="button" class="tab-btn ${from === daysAgo(29) && to === todayISO() ? 'active' : ''}" data-range="30">30 วันล่าสุด</button>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(from)}" max="${todayISO()}"></label>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${escapeHtml(to)}" max="${todayISO()}"></label>
            ${d.cashiers ? `<label class="field" style="flex-direction:row;align-items:center;gap:6px">พนักงาน
              <select id="f-cashier">
                <option value="" ${self ? 'selected' : ''}>การขายของฉัน</option>
                <option value="all" ${d.cashier_id === 'all' ? 'selected' : ''}>พนักงานทุกคน</option>
                ${d.cashiers.filter((c) => String(c.id) !== String(user.id)).map((c) => `<option value="${c.id}" ${String(c.id) === String(d.cashier_id) ? 'selected' : ''}>${escapeHtml(c.full_name)}${Number(c.sales) ? ` (${count(c.sales)} บิล)` : ''}</option>`).join('')}
              </select></label>` : ''}
          </div>
          <div class="muted" style="font-size:12.5px;margin-top:10px">
            ${d.edit_enabled
                ? `ขอแก้ไขการขายได้ภายใน <b>${count(d.edit_minutes)} นาที</b> หลังขายเสร็จ การแก้ไขจะมีผลเมื่อผู้จัดการหรือผู้ดูแลระบบอนุมัติ`
                : 'ผู้ดูแลระบบปิดการแก้ไขการขายย้อนหลังไว้ หากขายผิดกรุณาแจ้งผู้จัดการ'}
            ${d.direct_edit ? `<br>ผู้จัดการ/ผู้ดูแลระบบ: กด <b>แก้ไข (ผู้จัดการ)</b> เพื่อแก้รายการขายของพนักงานได้ทันทีโดยต้องระบุสาเหตุ
              หากการแก้ไขกระทบยอดชำระเงิน ระบบจะแจ้งเตือนเป็นพิเศษและต้องยืนยันว่ารับทราบ` : ''}
            ${d.direct_edit_needs_migration ? '<br>การแก้ไขรายการขายโดยผู้จัดการต้องรัน migration 0079 ก่อน' : ''}
          </div>
          ${negRows.length ? `
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:10px;padding:9px 12px;border-radius:9px;background:var(--bad-bg, #fdecec);color:var(--bad);font-size:13px">
            <span>⚠ <b>${count(negRows.length)} บิล</b> ${noAfter ? 'มีสินค้าที่คงเหลือติดลบอยู่ตอนนี้' : 'ทำให้สต๊อกสินค้าติดลบ (ขายเกินคงเหลือ)'} — อาจขายผิดสินค้า เช่น สินค้าที่ไม่มีบาร์โค้ด ควรตรวจสอบ</span>
            <button type="button" class="btn sm ${onlyNeg ? '' : 'secondary'}" id="f-neg">${onlyNeg ? 'แสดงทุกบิล' : 'แสดงเฉพาะบิลที่ต้องตรวจสอบ'}</button>
          </div>` : ''}
        </section>

        <section class="card">
          <div class="card-head"><h2>${escapeHtml(heading)} ${escapeHtml(fmtDate(d.from))}${d.to !== d.from ? ` – ${escapeHtml(fmtDate(d.to))}` : ''}</h2>
            <div style="font-weight:700">${onlyNeg ? `${count(rows.length)} จาก ${count(d.data.length)} บิล` : `${count(rows.length)} บิล · ฿ ${money(d.total_value)}`}</div></div>
          <div class="table-wrap" style="max-height:620px"><table>
            <thead><tr><th>เลขที่</th><th>เวลาขาย</th>${self ? '' : '<th>พนักงาน</th>'}<th class="num">รายการ</th><th class="num">ชิ้น</th><th class="num">ยอดขาย</th><th>ชำระโดย</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>${rows.map((s) => `
              <tr${negCount(s) ? ' style="box-shadow:inset 3px 0 0 var(--bad)"' : ''}>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${negCount(s) ? '<span title="บิลนี้มีสินค้าที่สต๊อกติดลบ" style="color:var(--bad)">⚠ </span>' : ''}${escapeHtml(s.doc_no)}${s.pos_import ? `<div><span class="pill muted" title="${escapeHtml(s.pos_source || 'นำเข้ายอดขายจาก POS ภายนอก')}">นำเข้าจาก POS</span></div>` : ''}</td>
                <td style="font-size:12.5px">${escapeHtml(fmtDateTime(s.sale_datetime))}${Number(s.after_hours) ? ' <span class="pill muted">นอกเวลา</span>' : ''}</td>
                ${self ? '' : `<td style="font-size:12.5px">${escapeHtml(s.cashier_name || '-')}</td>`}
                <td class="num">${count(s.line_count)}</td>
                <td class="num">${count(s.total_qty)}</td>
                <td class="num" style="font-weight:600">${money(s.total_value)}</td>
                <td style="font-size:12.5px">${escapeHtml(s.methods.map((m) => PAYMENT_LABEL[m] || m).join(' + ') || (s.pos_import ? 'ไม่ระบุ (POS ภายนอก)' : '-'))}</td>
                <td>${negPill(s)}${s.edit_status ? statusPill(s.edit_status) : ''}${Number(s.edits_approved) && s.edit_status !== 'approved' ? ' <span class="pill ok">แก้ไขแล้ว</span>' : ''}${s.payment_edited_at ? ` <span class="pill warn" title="แก้เมื่อ ${escapeHtml(fmtDateTime(s.payment_edited_at))}">แก้วิธีชำระแล้ว</span>` : ''}${Number(s.direct_edits) ? ' <span class="pill warn">แก้ไขโดยผู้จัดการ</span>' : ''}${Number(s.edits_total_changed) ? ' <span class="pill bad" title="ยอดขายหลังแก้ไขไม่เท่ากับยอดเดิม">ยอดชำระเปลี่ยน</span>' : ''}</td>
                <td style="white-space:nowrap;text-align:right">
                  <button type="button" class="btn secondary sm" data-view="${s.id}">ดูรายละเอียด</button>
                  ${ownSale(s) && s.payment_fix_until && left(s.payment_fix_until) > 0 ? `<button type="button" class="btn secondary sm" data-fix="${s.id}" title="เลือกวิธีชำระผิด แก้ได้ภายใน 5 นาทีหลังขาย ไม่ต้องรออนุมัติ">แก้วิธีชำระ (<span data-until="${escapeHtml(s.payment_fix_until)}">${mmss(left(s.payment_fix_until))}</span>)</button>` : ''}
                  ${ownSale(s) && s.editable_until && left(s.editable_until) > 0 ? `<button type="button" class="btn sm" data-edit="${s.id}">แก้ไข (<span data-until="${escapeHtml(s.editable_until)}">${mmss(left(s.editable_until))}</span>)</button>` : ''}
                  ${canDirect(s) && !(ownSale(s) && s.editable_until && left(s.editable_until) > 0) ? `<button type="button" class="btn secondary sm" data-direct="${s.id}" title="แก้ไขรายการขายทันทีโดยระบุสาเหตุ">แก้ไข (ผู้จัดการ)</button>` : ''}
                </td>
              </tr>`).join('') || `<tr><td colspan="${self ? 8 : 9}" class="empty">ไม่มีการขายในช่วงนี้</td></tr>`}</tbody>
          </table></div>
        </section>
        ${detail ? detailModal() : ''}
        ${edit ? editModal() : ''}`;
        wire();
        startTicks();
    }

    function negPill(s) {
        const n = negCount(s);
        if (!n) return '';
        return s.neg_lines === null || s.neg_lines === undefined
            ? `<span class="pill warn" title="สินค้าในบิลนี้มีคงเหลือติดลบอยู่ตอนนี้ (รัน migration 0080 เพื่อดูว่าบิลไหนทำให้ติดลบ)">สินค้าติดลบ ${count(n)} รายการ</span> `
            : `<span class="pill bad" title="ขายแล้วคงเหลือของสินค้าต่ำกว่า 0 — ตรวจว่าขายถูกสินค้าหรือไม่">สต๊อกติดลบ ${count(n)} รายการ</span> `;
    }

    /** Stock left after the line was sold, and now; below zero is marked. */
    function stockCell(it) {
        const neg = (v) => v !== null && v !== undefined && v < 0;
        const fmt = (v) => (v === null || v === undefined ? '-' : `<span style="${neg(v) ? 'color:var(--bad);font-weight:700' : ''}">${count(v)}</span>`);
        return `<td class="num" style="white-space:nowrap">${neg(it.stock_after) ? '⚠ ' : ''}${fmt(it.stock_after)}
          ${it.stock_now !== null && it.stock_now !== it.stock_after ? `<div class="muted" style="font-size:11.5px">ตอนนี้ ${fmt(it.stock_now)}</div>` : ''}</td>`;
    }

    function detailModal() {
        const s = detail;
        const mine = s.requests.find((r) => r.status === 'pending' && String(r.requested_by) === String(user.id));
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:820px">
            <div class="modal-head"><h2>${escapeHtml(s.doc_no)} · ${escapeHtml(fmtDateTime(s.sale_datetime))}</h2>
              <button type="button" class="modal-close" data-close title="ปิด" aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num">จำนวน</th><th class="num">รวม</th><th class="num" title="คงเหลือของสินค้าหลังขายรายการนี้">คงเหลือหลังขาย</th></tr></thead>
                <tbody>${s.items.map((it) => `<tr${(it.stock_after ?? it.stock_now) < 0 ? ' style="background:var(--bad-bg, #fdecec)"' : ''}><td>${escapeHtml(it.name)}${it.barcode ? '' : ' <span class="pill muted">ไม่มีบาร์โค้ด</span>'}${it.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${it.pack_size}</span>` : ''}
                    <div class="muted" style="font-size:12px">${escapeHtml(it.consignor_name)}</div>
                    ${it.promo_discount > 0 ? `<div style="font-size:12px;color:var(--ok)">🏷 ${escapeHtml(it.promo_name || 'โปรโมชั่นจับคู่')} ลด ฿${money(it.promo_discount)}</div>` : ''}</td>
                  <td class="num">${money(it.unit_price)}</td><td class="num">${count(it.qty)}</td><td class="num">${money(it.line_total)}</td>${stockCell(it)}</tr>`).join('')}</tbody>
                <tfoot>${Number(s.promo_discount) > 0 ? `<tr><td colspan="3" style="color:var(--ok)">รวมส่วนลดโปรโมชั่นจับคู่สินค้า (ราคาต่อชิ้นหักส่วนลดแล้ว)</td><td class="num" style="color:var(--ok)">−฿${money(s.promo_discount)}</td><td></td></tr>` : ''}<tr><td colspan="2" style="font-weight:600">ยอดรวม</td><td class="num">${count(s.total_qty)}</td><td class="num" style="font-weight:700">฿${money(s.total_value)}</td><td></td></tr></tfoot>
              </table></div>
              <div style="margin-top:10px;font-size:13.5px">ชำระโดย: ${escapeHtml(paymentsText(s.payments))}
                ${s.cash_received !== null ? `<span class="muted"> · รับเงินสด ${money(s.cash_received)} ทอน ${money(s.change_amount)}</span>` : ''}</div>
              ${s.requests.length ? `<h3 style="margin:18px 0 0;font-size:15px">ประวัติการแก้ไข</h3>${s.requests.map(requestHtml).join('')}` : ''}
            </div>
            <div class="modal-foot">
              ${mine ? `<button type="button" class="btn secondary" data-cancel-req="${mine.id}">ยกเลิกคำขอแก้ไข</button>` : '<span></span>'}
              <div style="display:flex;gap:8px">
                ${ownSale(s) && s.editable_until && left(s.editable_until) > 0 ? `<button type="button" class="btn" data-edit="${s.id}">ขอแก้ไข (<span data-until="${escapeHtml(s.editable_until)}">${mmss(left(s.editable_until))}</span>)</button>` : ''}
                ${canDirect(s) ? `<button type="button" class="btn ${ownSale(s) && s.editable_until ? 'secondary' : ''}" data-direct="${s.id}">แก้ไขรายการขาย (ผู้จัดการ)</button>` : ''}
                <button type="button" class="btn secondary" data-close>ปิด</button>
              </div>
            </div>
          </div>
        </div>`;
    }

    const editTotal = () => Math.round(edit.items.reduce((t, it) => t + it.unit_price * it.qty, 0) * 100) / 100;
    const paidTotal = () => Math.round(edit.payments.reduce((t, p) => t + (Number(p.amount) || 0), 0) * 100) / 100;
    /** The payments as they would be saved (one method = the whole new total). */
    const editPayments = () => (edit.payments.length === 1
        ? [{ method: edit.payments[0].method, amount: editTotal() }]
        : edit.payments.map((p) => ({ method: p.method, amount: Number(p.amount) || 0 })));
    /** What this correction does to the amount paid; a changed figure asks for the acknowledgement again. */
    function liveImpact() {
        const impact = computeImpact(edit.sale, { payments: editPayments(), total_value: editTotal() });
        const key = JSON.stringify([impact.total_diff, impact.methods]);
        if (key !== edit.ackKey) { edit.ackKey = key; edit.ack = false; }
        return impact;
    }

    function editModal() {
        const e = edit;
        const total = editTotal();
        const methods = data.payment_methods;
        const single = e.payments.length === 1;
        const impact = liveImpact();
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:860px">
            <div class="modal-head"><h2>${e.direct ? 'แก้ไขรายการขาย' : 'ขอแก้ไขการขาย'} ${escapeHtml(e.sale.doc_no)}${e.direct ? ` <span class="muted" style="font-size:13px;font-weight:400">ขายโดย ${escapeHtml(e.sale.cashier_name || '-')} · ${escapeHtml(fmtDateTime(e.sale.sale_datetime))}</span>` : ''}</h2>
              <div style="display:flex;align-items:center;gap:10px">
                ${!e.direct && e.sale.editable_until ? `<span class="pill warn">เหลือเวลา <span data-until="${escapeHtml(e.sale.editable_until)}">${mmss(left(e.sale.editable_until))}</span></span>` : ''}
                <button type="button" class="modal-close" data-close-edit title="ปิด" aria-label="ปิด">✕</button></div></div>
            <div class="modal-body">
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num" style="width:170px">จำนวน (ชิ้น)</th><th class="num">รวม</th><th></th></tr></thead>
                <tbody>${e.items.map((it, i) => {
                    const orig = it.sale_item_id ? e.sale.items.find((x) => x.sale_item_id === it.sale_item_id) : null;
                    const changed = !orig || orig.qty !== it.qty;
                    return `<tr style="${it.qty === 0 ? 'opacity:.5' : changed ? 'background:var(--warn-bg)' : ''}">
                      <td>${escapeHtml(it.name)}${it.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${it.pack_size}</span>` : ''}${orig ? '' : it.replaces ? ' <span class="pill ok">ขายจริง</span>' : ' <span class="pill ok">เพิ่ม</span>'}
                        ${it.replaces ? `<div class="muted" style="font-size:12px">แทน ${escapeHtml(it.replaces)}</div>` : ''}
                        ${it.swappedTo && it.qty === 0 ? `<div class="muted" style="font-size:12px">เปลี่ยนเป็น ${escapeHtml(it.swappedTo)}</div>`
                          : orig && orig.qty !== it.qty ? `<div class="muted" style="font-size:12px">เดิม ${count(orig.qty)} ชิ้น</div>` : ''}</td>
                      <td class="num">${money(it.unit_price)}</td>
                      <td class="num"><div style="display:inline-flex;gap:4px;align-items:center">
                        <button type="button" class="btn secondary sm" data-dec="${i}" aria-label="ลด">−</button>
                        <input type="number" data-qty="${i}" value="${it.qty}" min="0" step="${it.pack_size}" style="width:70px;text-align:right">
                        <button type="button" class="btn secondary sm" data-inc="${i}" aria-label="เพิ่ม">+</button></div></td>
                      <td class="num">${money(it.unit_price * it.qty)}</td>
                      <td style="text-align:right;white-space:nowrap">${it.qty > 0 ? `<button type="button" class="btn ${e.swap === i ? '' : 'secondary'} sm" data-swap="${i}" title="ขายผิดสินค้า: เลือกสินค้าที่ขายจริงแทนรายการนี้ จำนวนเท่าเดิม">เปลี่ยนสินค้า</button>
                        <button type="button" class="btn secondary sm" data-remove="${i}">นำออก</button>` : ''}</td></tr>`;
                }).join('')}</tbody>
                <tfoot><tr><td colspan="3" style="font-weight:600">ยอดรวมใหม่ <span class="muted" style="font-weight:400">(เดิม ฿${money(e.sale.total_value)})</span></td>
                  <td class="num" style="font-weight:700">฿${money(total)}</td><td></td></tr></tfoot>
              </table></div>

              <div style="margin-top:14px;position:relative">
                ${e.swap !== null && e.items[e.swap] ? `<div class="hint" style="margin:0 0 8px;display:flex;justify-content:space-between;align-items:center;gap:10px">
                  <div>เลือกสินค้าที่ขายจริง แทน <b>${escapeHtml(e.items[e.swap].name)}</b> (${count(e.items[e.swap].qty)} ชิ้น) — ค้นหาด้านล่างแล้วกดเลือก</div>
                  <button type="button" class="btn secondary sm" id="e-swap-cancel">ยกเลิกการเปลี่ยน</button></div>` : ''}
                <label class="field">${e.swap !== null ? 'สินค้าที่ขายจริง' : e.direct ? 'เพิ่มสินค้าในบิลนี้' : 'เพิ่มสินค้าที่ขายแต่ลืมสแกน'}
                  <input id="e-search" placeholder="พิมพ์ชื่อสินค้าหรือสแกนบาร์โค้ด" value="${escapeHtml(e.search)}" autocomplete="off"></label>
                ${e.results.length ? `<div class="card" style="margin-top:6px;max-height:220px;overflow:auto">
                  ${e.results.map((p, i) => `<button type="button" class="pick-row" data-add="${i}">
                    <span>${escapeHtml(p.name)}${p.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${p.pack_size}</span>` : ''} <span class="muted" style="font-size:12px">${escapeHtml(p.consignor_name)} · คงเหลือ ${count(p.qty_stock)}</span></span>
                    <b>฿${money(p.price)}</b></button>`).join('')}</div>` : ''}
              </div>

              <div style="margin-top:16px;font-weight:600;font-size:14px">การชำระเงิน</div>
              <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
                ${e.payments.map((p, i) => `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                  <select data-pm="${i}">${methods.map((m) => `<option value="${m.key}" ${m.key === p.method ? 'selected' : ''}>${escapeHtml(m.label)}</option>`).join('')}</select>
                  ${single ? `<span>฿${money(total)}</span>` : `<input type="number" data-pa="${i}" value="${escapeHtml(p.amount)}" min="0" step="0.01" style="width:130px;text-align:right">
                    <button type="button" class="btn secondary sm" data-pdel="${i}">ลบ</button>`}
                </div>`).join('')}
                ${data.payment_mixed && e.payments.length < methods.length ? '<div><button type="button" class="btn secondary sm" id="e-padd">+ แบ่งชำระหลายวิธี</button></div>' : ''}
                ${!single && Math.abs(paidTotal() - total) > 0.004 ? `<div class="muted" style="color:var(--bad);font-size:12.5px">ยอดชำระรวม ฿${money(paidTotal())} ต้องเท่ากับยอดขายใหม่ ฿${money(total)}</div>` : ''}
              </div>

              ${impact.affected ? `${impactHtml(impact)}
                ${e.direct ? `<label class="impact-ack"><input type="checkbox" id="e-ack" ${e.ack ? 'checked' : ''}>
                  รับทราบว่าการแก้ไขนี้กระทบยอดชำระเงิน และได้ตรวจสอบ/ดำเนินการเรื่องเงินกับลูกค้าและลิ้นชักแล้ว</label>` : ''}` : ''}
              <label class="field" style="margin-top:16px">${e.direct ? 'สาเหตุที่แก้ไข *' : 'เหตุผลที่ขอแก้ไข *'}
                <textarea id="e-reason" rows="2" maxlength="500" placeholder="${e.direct ? 'เช่น พนักงานสแกนสินค้าซ้ำ ตรวจพบตอนนับสต๊อก ลูกค้าคืนสินค้า' : 'เช่น สแกนสินค้าซ้ำ ลูกค้าเปลี่ยนใจ เลือกวิธีชำระผิด'}">${escapeHtml(e.reason)}</textarea></label>
              ${e.error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(e.error)}</div>` : ''}
              <div class="muted" style="font-size:12.5px;margin-top:10px">${e.direct
                ? 'เมื่อบันทึก รายการขาย สต๊อกสินค้า สมุดบัญชีผู้ฝากขาย และยอดเงินในลิ้นชักจะถูกปรับทันที พร้อมบันทึกสาเหตุและผู้แก้ไขไว้ในประวัติการแก้ไขของบิลนี้ (สต๊อกอาจติดลบได้)'
                : 'ระบบจะบันทึกคำขอไว้ การขาย สต๊อก และยอดเงินจะเปลี่ยนเมื่อผู้จัดการหรือผู้ดูแลระบบอนุมัติแล้วเท่านั้น'}</div>
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-close-edit>ยกเลิก</button>
              <button type="button" class="btn" id="e-save" ${e.busy || (e.direct && impact.affected && !e.ack) ? 'disabled' : ''}>${e.busy ? 'กำลังบันทึก...' : e.direct ? 'บันทึกการแก้ไข' : 'ส่งคำขอแก้ไข'}</button>
            </div>
          </div>
        </div>`;
    }

    async function openDetail(id) {
        try {
            const res = await api.get(`/sales/${id}`);
            skew = serverTime(res.now) - Date.now();
            detail = res.data;
            draw();
        } catch (err) { alertDialog(err.message, { tone: 'error' }); }
    }

    async function openEdit(id, direct = false) {
        try {
            const res = await api.get(`/sales/${id}`);
            skew = serverTime(res.now) - Date.now();
            const sale = res.data;
            if (direct && !canDirect(sale)) {
                alertDialog(sale.requests.some((r) => r.status === 'pending')
                    ? 'การขายนี้มีคำขอแก้ไขของพนักงานรออนุมัติอยู่ กรุณาอนุมัติหรือไม่อนุมัติคำขอนั้นก่อน (เมนูอนุมัติแก้ไขการขาย)'
                    : 'แก้ไขรายการขายนี้ไม่ได้', { tone: 'error' });
                return load();
            }
            if (!direct && !sale.editable_until) {
                alertDialog('การขายนี้แก้ไขไม่ได้แล้ว (เกินเวลา หรือมีคำขอรออนุมัติอยู่)', { tone: 'error' });
                return load();
            }
            const enabled = data.payment_methods.map((m) => m.key);
            const payments = sale.payments.filter((p) => enabled.includes(p.method)).map((p) => ({ ...p }));
            detail = null;
            edit = {
                sale,
                direct,
                ack: false,
                ackKey: null,
                items: sale.items.map((it) => ({ ...it })),
                payments: payments.length ? payments : [{ method: enabled[0], amount: sale.total_value }],
                reason: '', search: '', results: [], busy: false, error: null,
                swap: null, // index of the line being changed to another product (sold the wrong product)
            };
            draw();
        } catch (err) { alertDialog(err.message, { tone: 'error' }); }
    }

    /**
     * Sold the wrong product: the line being changed goes to 0 (an added line is dropped) and the
     * product sold for real comes in with the same number of pieces (whole packs for a pack price).
     */
    function swapTo(p) {
        const old = edit.items[edit.swap];
        const pack = p.pack_size;
        const qty = Math.max(pack, Math.round(old.qty / pack) * pack);
        if (old.sale_item_id) {
            old.qty = 0;
            old.swappedTo = p.name;
        } else {
            edit.items.splice(edit.swap, 1);
        }
        const replaces = old.replaces || old.name;
        const same = edit.items.find((it) => !it.sale_item_id && it.product_id === Number(p.id) && it.pack_size === pack);
        if (same) same.qty += qty;
        else edit.items.push({ sale_item_id: null, product_id: Number(p.id), name: p.name, pack_size: pack, unit_price: p.price, qty, replaces });
        if (!edit.reason.trim()) edit.reason = `ขายผิดสินค้า: ${replaces} → ${p.name}`;
        edit.swap = null;
        edit.search = '';
        edit.results = [];
        syncSinglePayment();
        draw();
    }

    let searchSeq = 0;
    async function search(q) {
        edit.search = q;
        const seq = ++searchSeq;
        if (!q.trim()) { edit.results = []; return redrawKeepSearch(); }
        try {
            const res = await api.get(`/sales/products?q=${encodeURIComponent(q.trim())}`);
            if (seq !== searchSeq || !edit) return;
            // Each product, plus each of its packs that has a pack price.
            edit.results = res.data.flatMap((p) => [
                { ...p, pack_size: 1, price: Number(p.sale_price) },
                ...(p.packs || []).map((k) => ({ ...p, pack_size: k.pack_size, price: k.sale_price / k.pack_size })),
            ]).slice(0, 20);
            redrawKeepSearch();
        } catch (err) { /* ignore while typing */ }
    }

    function redrawKeepSearch() {
        const input = container.querySelector('#e-search');
        const pos = input ? input.selectionStart : null;
        draw();
        const again = container.querySelector('#e-search');
        if (again && pos !== null) { again.focus(); again.setSelectionRange(pos, pos); }
    }

    function syncSinglePayment() {
        if (edit.payments.length === 1) edit.payments[0].amount = editTotal();
    }

    function wire() {
        const $ = (s) => container.querySelector(s);
        $('#f-neg')?.addEventListener('click', () => { onlyNeg = !onlyNeg; draw(); });
        container.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => {
            const n = Number(b.dataset.range);
            if (n === 0) { from = to = todayISO(); } else if (n === 1) { from = to = daysAgo(1); } else { from = daysAgo(n - 1); to = todayISO(); }
            load();
        }));
        $('#f-cashier')?.addEventListener('change', (e) => { cashier = e.target.value; load(); });
        $('#f-from')?.addEventListener('change', (e) => { if (e.target.value) { from = e.target.value; if (to < from) to = from; load(); } });
        $('#f-to')?.addEventListener('change', (e) => { if (e.target.value) { to = e.target.value; if (from > to) from = to; load(); } });
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => openDetail(b.dataset.view)));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => openEdit(b.dataset.edit)));
        container.querySelectorAll('[data-direct]').forEach((b) => b.addEventListener('click', () => openEdit(b.dataset.direct, true)));
        container.querySelectorAll('[data-fix]').forEach((b) => b.addEventListener('click', () => fixPayment(b.dataset.fix)));
        container.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => { detail = null; draw(); }));
        container.querySelectorAll('[data-cancel-req]').forEach((b) => b.addEventListener('click', async () => {
            if (!await confirmDialog('ยกเลิกคำขอแก้ไขนี้? การขายจะคงเดิม', { title: 'ยกเลิกคำขอแก้ไข', okText: 'ยกเลิกคำขอ', cancelText: 'ไม่ยกเลิก', danger: true })) return;
            try {
                await api.post(`/sale-edits/${b.dataset.cancelReq}/cancel`);
                const id = detail.id;
                await load();
                openDetail(id);
            } catch (err) { alertDialog(err.message, { tone: 'error' }); }
        }));
        if (!edit) return;

        container.querySelectorAll('[data-close-edit]').forEach((b) => b.addEventListener('click', () => { edit = null; draw(); }));
        const setQty = (i, qty) => {
            const it = edit.items[i];
            qty = Math.max(0, Math.round(qty / it.pack_size) * it.pack_size);
            if (!it.sale_item_id && qty === 0) { edit.items.splice(i, 1); edit.swap = null; } // an added line taken back out
            else it.qty = qty;
            syncSinglePayment();
            draw();
        };
        container.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', () => setQty(+b.dataset.dec, edit.items[+b.dataset.dec].qty - edit.items[+b.dataset.dec].pack_size)));
        container.querySelectorAll('[data-inc]').forEach((b) => b.addEventListener('click', () => setQty(+b.dataset.inc, edit.items[+b.dataset.inc].qty + edit.items[+b.dataset.inc].pack_size)));
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('change', () => setQty(+inp.dataset.qty, Number(inp.value) || 0)));
        container.querySelectorAll('[data-remove]').forEach((b) => b.addEventListener('click', () => setQty(+b.dataset.remove, 0)));
        let t = null;
        $('#e-search').addEventListener('input', (e) => { clearTimeout(t); const q = e.target.value; edit.search = q; t = setTimeout(() => search(q), 250); });
        container.querySelectorAll('[data-swap]').forEach((b) => b.addEventListener('click', () => {
            const i = +b.dataset.swap;
            edit.swap = edit.swap === i ? null : i;
            edit.search = '';
            edit.results = [];
            draw();
            if (edit.swap !== null) container.querySelector('#e-search')?.focus();
        }));
        $('#e-swap-cancel')?.addEventListener('click', () => { edit.swap = null; draw(); });
        container.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
            const p = edit.results[+b.dataset.add];
            if (edit.swap !== null && edit.items[edit.swap]) {
                swapTo(p);
                return;
            }
            const same = edit.items.find((it) => !it.sale_item_id && it.product_id === Number(p.id) && it.pack_size === p.pack_size);
            if (same) same.qty += p.pack_size;
            else edit.items.push({ sale_item_id: null, product_id: Number(p.id), name: p.name, pack_size: p.pack_size, unit_price: p.price, qty: p.pack_size });
            edit.search = '';
            edit.results = [];
            syncSinglePayment();
            draw();
        }));
        container.querySelectorAll('[data-pm]').forEach((s) => s.addEventListener('change', () => { edit.payments[+s.dataset.pm].method = s.value; draw(); }));
        container.querySelectorAll('[data-pa]').forEach((inp) => inp.addEventListener('change', () => { edit.payments[+inp.dataset.pa].amount = inp.value; draw(); }));
        container.querySelectorAll('[data-pdel]').forEach((b) => b.addEventListener('click', () => {
            edit.payments.splice(+b.dataset.pdel, 1);
            syncSinglePayment();
            draw();
        }));
        $('#e-padd')?.addEventListener('click', () => {
            const used = edit.payments.map((p) => p.method);
            const next = data.payment_methods.find((m) => !used.includes(m.key));
            const rest = Math.max(0, Math.round((editTotal() - paidTotal()) * 100) / 100);
            edit.payments.push({ method: next.key, amount: rest });
            draw();
        });
        $('#e-reason').addEventListener('input', (e) => { edit.reason = e.target.value; });
        $('#e-ack')?.addEventListener('change', (e) => { edit.ack = e.target.checked; draw(); });
        $('#e-save').addEventListener('click', save);
    }

    async function save() {
        const e = edit;
        e.error = null;
        syncSinglePayment();
        if (!e.items.some((it) => it.qty > 0)) { e.error = 'การขายต้องมีสินค้าอย่างน้อย 1 รายการ (ถ้าต้องการยกเลิกทั้งบิล กรุณาแจ้งผู้จัดการ)'; return draw(); }
        if (!e.reason.trim()) { e.error = e.direct ? 'กรุณาระบุสาเหตุที่แก้ไขรายการขาย' : 'กรุณาระบุเหตุผลที่ขอแก้ไข'; return draw(); }
        if (Math.abs(paidTotal() - editTotal()) > 0.004) { e.error = `ยอดชำระรวมต้องเท่ากับยอดขายใหม่ ฿${money(editTotal())}`; return draw(); }
        if (e.direct) return saveDirect();
        const ok = await confirmDialog(`ส่งคำขอแก้ไข ${e.sale.doc_no}: ยอดขาย ฿${money(e.sale.total_value)} → ฿${money(editTotal())}`, { title: 'ส่งคำขอแก้ไขการขาย?', okText: 'ส่งคำขอ' });
        if (!ok) return;
        e.busy = true;
        draw();
        try {
            await api.post(`/sales/${e.sale.id}/edit-requests`, {
                items: e.items.map((it) => ({ sale_item_id: it.sale_item_id, product_id: it.product_id, qty: it.qty, pack_size: it.pack_size })),
                payments: e.payments.map((p) => ({ method: p.method, amount: Number(p.amount) })),
                reason: e.reason.trim(),
            });
            edit = null;
            if (solo) solo.saved = true;
            await load();
            alertDialog('ส่งคำขอแก้ไขแล้ว รอผู้จัดการหรือผู้ดูแลระบบอนุมัติ', { title: 'ส่งคำขอแล้ว' });
        } catch (err) {
            e.busy = false;
            e.error = err.message;
            draw();
        }
    }

    /** A manager's own correction: the server's view of the impact (drawer counted? day summed up?) before saving. */
    async function saveDirect() {
        const e = edit;
        const body = {
            items: e.items.map((it) => ({ sale_item_id: it.sale_item_id, product_id: it.product_id, qty: it.qty, pack_size: it.pack_size })),
            payments: editPayments(),
            reason: e.reason.trim(),
        };
        if (liveImpact().affected && !e.ack) { e.error = 'การแก้ไขนี้กระทบยอดชำระเงิน กรุณาติ๊กยืนยันว่ารับทราบก่อนบันทึก'; return draw(); }
        let impact;
        try {
            impact = (await api.post(`/sales/${e.sale.id}/edit-impact`, body)).data;
        } catch (err) { e.error = err.message; return draw(); }
        const ok = await confirmDialog(
            `แก้ไข ${e.sale.doc_no} ของ ${e.sale.cashier_name || 'พนักงาน'}: ยอดขาย ฿${money(e.sale.total_value)} → ฿${money(editTotal())}\nสาเหตุ: ${body.reason}`,
            {
                title: impact.affected ? 'ยืนยันการแก้ไขที่กระทบยอดชำระ' : 'ยืนยันการแก้ไขรายการขาย',
                okText: 'บันทึกการแก้ไข',
                danger: impact.affected,
                html: impactHtml(impact),
                width: impact.affected ? 640 : 0,
            },
        );
        if (!ok) return;
        e.busy = true;
        draw();
        try {
            await api.post(`/sales/${e.sale.id}/direct-edit`, { ...body, ack_payment: impact.affected ? e.ack : false });
            edit = null;
            if (solo) solo.saved = true;
            await load();
            alertDialog(impact.affected
                ? 'บันทึกการแก้ไขแล้ว การแก้ไขนี้กระทบยอดชำระเงิน — ระบบบันทึกข้อสังเกตไว้ในประวัติการแก้ไข กรุณาตรวจยอดเงินในลิ้นชัก/สรุปบัญชีของวันนั้น'
                : 'บันทึกการแก้ไขแล้ว', { title: 'แก้ไขรายการขายแล้ว', tone: impact.affected ? 'error' : 'info' });
        } catch (err) {
            e.busy = false;
            e.error = err.message;
            draw();
        }
    }

    await load();
    if (solo) {
        await openEdit(solo.id, true);
        if (!edit) container.remove(); // could not be edited (the reason was shown)
    }
}
