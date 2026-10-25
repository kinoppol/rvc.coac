import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';
import { PAYMENT_LABEL, statusPill, paymentsText, serverTime, requestHtml } from '../sale-edit.js';

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n) => {
    const [y, m, d] = todayISO().split('-').map(Number);
    return iso(new Date(y, m - 1, d - n));
};

/**
 * ประวัติการขายของฉัน: the cashier's own POS sales by day. Within a few minutes of a
 * sale (admin setting) they may ask to correct it — quantities, lines out or in, how it
 * was paid; the sale changes only when a manager or admin approves.
 */
export async function render(container, { user }) {
    let from = todayISO();
    let to = from;
    let data = null;
    let skew = 0; // server clock minus this browser's, for the countdowns
    let detail = null; // the sale open on the detail modal
    let edit = null; // the correction being written: { sale, items, payments, reason, search, results, busy, error }
    let timer = null;

    const nowServer = () => Date.now() + skew;
    const left = (until) => Math.max(0, Math.floor((serverTime(until) - nowServer()) / 1000));
    const mmss = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

    async function load() {
        const res = await api.get(`/sales/mine?from=${from}&to=${to}`);
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

    function draw() {
        const d = data;
        const rows = d.data;
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row">
            <button type="button" class="tab-btn ${from === todayISO() && to === from ? 'active' : ''}" data-range="0">วันนี้</button>
            <button type="button" class="tab-btn ${from === daysAgo(1) && to === from ? 'active' : ''}" data-range="1">เมื่อวาน</button>
            <button type="button" class="tab-btn ${from === daysAgo(6) && to === todayISO() ? 'active' : ''}" data-range="7">7 วันล่าสุด</button>
            <button type="button" class="tab-btn ${from === daysAgo(29) && to === todayISO() ? 'active' : ''}" data-range="30">30 วันล่าสุด</button>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(from)}" max="${todayISO()}"></label>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${escapeHtml(to)}" max="${todayISO()}"></label>
          </div>
          <div class="muted" style="font-size:12.5px;margin-top:10px">
            ${d.edit_enabled
                ? `ขอแก้ไขการขายได้ภายใน <b>${count(d.edit_minutes)} นาที</b> หลังขายเสร็จ การแก้ไขจะมีผลเมื่อผู้จัดการหรือผู้ดูแลระบบอนุมัติ`
                : 'ผู้ดูแลระบบปิดการแก้ไขการขายย้อนหลังไว้ หากขายผิดกรุณาแจ้งผู้จัดการ'}
          </div>
        </section>

        <section class="card">
          <div class="card-head"><h2>การขายของฉัน ${escapeHtml(fmtDate(d.from))}${d.to !== d.from ? ` – ${escapeHtml(fmtDate(d.to))}` : ''}</h2>
            <div style="font-weight:700">${count(rows.length)} บิล · ฿ ${money(d.total_value)}</div></div>
          <div class="table-wrap" style="max-height:620px"><table>
            <thead><tr><th>เลขที่</th><th>เวลาขาย</th><th class="num">รายการ</th><th class="num">ชิ้น</th><th class="num">ยอดขาย</th><th>ชำระโดย</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>${rows.map((s) => `
              <tr>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(s.doc_no)}</td>
                <td style="font-size:12.5px">${escapeHtml(fmtDateTime(s.sale_datetime))}${Number(s.after_hours) ? ' <span class="pill muted">นอกเวลา</span>' : ''}</td>
                <td class="num">${count(s.line_count)}</td>
                <td class="num">${count(s.total_qty)}</td>
                <td class="num" style="font-weight:600">${money(s.total_value)}</td>
                <td style="font-size:12.5px">${escapeHtml(s.methods.map((m) => PAYMENT_LABEL[m] || m).join(' + ') || '-')}</td>
                <td>${s.edit_status ? statusPill(s.edit_status) : ''}${Number(s.edits_approved) && s.edit_status !== 'approved' ? ' <span class="pill ok">แก้ไขแล้ว</span>' : ''}</td>
                <td style="white-space:nowrap;text-align:right">
                  <button type="button" class="btn secondary sm" data-view="${s.id}">ดูรายละเอียด</button>
                  ${s.editable_until && left(s.editable_until) > 0 ? `<button type="button" class="btn sm" data-edit="${s.id}">แก้ไข (<span data-until="${escapeHtml(s.editable_until)}">${mmss(left(s.editable_until))}</span>)</button>` : ''}
                </td>
              </tr>`).join('') || '<tr><td colspan="8" class="empty">ไม่มีการขายในช่วงนี้</td></tr>'}</tbody>
          </table></div>
        </section>
        ${detail ? detailModal() : ''}
        ${edit ? editModal() : ''}`;
        wire();
        startTicks();
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
                <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num">จำนวน</th><th class="num">รวม</th></tr></thead>
                <tbody>${s.items.map((it) => `<tr><td>${escapeHtml(it.name)}${it.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${it.pack_size}</span>` : ''}
                    <div class="muted" style="font-size:12px">${escapeHtml(it.consignor_name)}</div></td>
                  <td class="num">${money(it.unit_price)}</td><td class="num">${count(it.qty)}</td><td class="num">${money(it.line_total)}</td></tr>`).join('')}</tbody>
                <tfoot><tr><td colspan="2" style="font-weight:600">ยอดรวม</td><td class="num">${count(s.total_qty)}</td><td class="num" style="font-weight:700">฿${money(s.total_value)}</td></tr></tfoot>
              </table></div>
              <div style="margin-top:10px;font-size:13.5px">ชำระโดย: ${escapeHtml(paymentsText(s.payments))}
                ${s.cash_received !== null ? `<span class="muted"> · รับเงินสด ${money(s.cash_received)} ทอน ${money(s.change_amount)}</span>` : ''}</div>
              ${s.requests.length ? `<h3 style="margin:18px 0 0;font-size:15px">คำขอแก้ไข</h3>${s.requests.map(requestHtml).join('')}` : ''}
            </div>
            <div class="modal-foot">
              ${mine ? `<button type="button" class="btn secondary" data-cancel-req="${mine.id}">ยกเลิกคำขอแก้ไข</button>` : '<span></span>'}
              <div style="display:flex;gap:8px">
                ${s.editable_until && left(s.editable_until) > 0 ? `<button type="button" class="btn" data-edit="${s.id}">ขอแก้ไข (<span data-until="${escapeHtml(s.editable_until)}">${mmss(left(s.editable_until))}</span>)</button>` : ''}
                <button type="button" class="btn secondary" data-close>ปิด</button>
              </div>
            </div>
          </div>
        </div>`;
    }

    const editTotal = () => Math.round(edit.items.reduce((t, it) => t + it.unit_price * it.qty, 0) * 100) / 100;
    const paidTotal = () => Math.round(edit.payments.reduce((t, p) => t + (Number(p.amount) || 0), 0) * 100) / 100;

    function editModal() {
        const e = edit;
        const total = editTotal();
        const methods = data.payment_methods;
        const single = e.payments.length === 1;
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:860px">
            <div class="modal-head"><h2>ขอแก้ไขการขาย ${escapeHtml(e.sale.doc_no)}</h2>
              <div style="display:flex;align-items:center;gap:10px">
                ${e.sale.editable_until ? `<span class="pill warn">เหลือเวลา <span data-until="${escapeHtml(e.sale.editable_until)}">${mmss(left(e.sale.editable_until))}</span></span>` : ''}
                <button type="button" class="modal-close" data-close-edit title="ปิด" aria-label="ปิด">✕</button></div></div>
            <div class="modal-body">
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคา/ชิ้น</th><th class="num" style="width:170px">จำนวน (ชิ้น)</th><th class="num">รวม</th><th></th></tr></thead>
                <tbody>${e.items.map((it, i) => {
                    const orig = it.sale_item_id ? e.sale.items.find((x) => x.sale_item_id === it.sale_item_id) : null;
                    const changed = !orig || orig.qty !== it.qty;
                    return `<tr style="${it.qty === 0 ? 'opacity:.5' : changed ? 'background:var(--warn-bg)' : ''}">
                      <td>${escapeHtml(it.name)}${it.pack_size > 1 ? ` <span class="pill muted">ยกแพ็ค ${it.pack_size}</span>` : ''}${orig ? '' : ' <span class="pill ok">เพิ่ม</span>'}
                        ${orig && orig.qty !== it.qty ? `<div class="muted" style="font-size:12px">เดิม ${count(orig.qty)} ชิ้น</div>` : ''}</td>
                      <td class="num">${money(it.unit_price)}</td>
                      <td class="num"><div style="display:inline-flex;gap:4px;align-items:center">
                        <button type="button" class="btn secondary sm" data-dec="${i}" aria-label="ลด">−</button>
                        <input type="number" data-qty="${i}" value="${it.qty}" min="0" step="${it.pack_size}" style="width:70px;text-align:right">
                        <button type="button" class="btn secondary sm" data-inc="${i}" aria-label="เพิ่ม">+</button></div></td>
                      <td class="num">${money(it.unit_price * it.qty)}</td>
                      <td style="text-align:right">${it.qty > 0 ? `<button type="button" class="btn secondary sm" data-remove="${i}">นำออก</button>` : ''}</td></tr>`;
                }).join('')}</tbody>
                <tfoot><tr><td colspan="3" style="font-weight:600">ยอดรวมใหม่ <span class="muted" style="font-weight:400">(เดิม ฿${money(e.sale.total_value)})</span></td>
                  <td class="num" style="font-weight:700">฿${money(total)}</td><td></td></tr></tfoot>
              </table></div>

              <div style="margin-top:14px;position:relative">
                <label class="field">เพิ่มสินค้าที่ขายแต่ลืมสแกน
                  <input id="e-search" placeholder="พิมพ์ชื่อสินค้าหรือสแกนบาร์โค้ด" value="${escapeHtml(e.search)}" autocomplete="off"></label>
                ${e.results.length ? `<div class="card" style="margin-top:6px;max-height:220px;overflow:auto">
                  ${e.results.map((p, i) => `<button type="button" class="nav-btn" data-add="${i}" style="display:flex;justify-content:space-between;width:100%;gap:10px;padding:8px 12px;border:0;background:none;cursor:pointer;text-align:left">
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

              <label class="field" style="margin-top:16px">เหตุผลที่ขอแก้ไข *
                <textarea id="e-reason" rows="2" maxlength="500" placeholder="เช่น สแกนสินค้าซ้ำ ลูกค้าเปลี่ยนใจ เลือกวิธีชำระผิด">${escapeHtml(e.reason)}</textarea></label>
              ${e.error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(e.error)}</div>` : ''}
              <div class="muted" style="font-size:12.5px;margin-top:10px">ระบบจะบันทึกคำขอไว้ การขาย สต๊อก และยอดเงินจะเปลี่ยนเมื่อผู้จัดการหรือผู้ดูแลระบบอนุมัติแล้วเท่านั้น</div>
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-close-edit>ยกเลิก</button>
              <button type="button" class="btn" id="e-save" ${e.busy ? 'disabled' : ''}>${e.busy ? 'กำลังส่ง...' : 'ส่งคำขอแก้ไข'}</button>
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

    async function openEdit(id) {
        try {
            const res = await api.get(`/sales/${id}`);
            skew = serverTime(res.now) - Date.now();
            const sale = res.data;
            if (!sale.editable_until) {
                alertDialog('การขายนี้แก้ไขไม่ได้แล้ว (เกินเวลา หรือมีคำขอรออนุมัติอยู่)', { tone: 'error' });
                return load();
            }
            const enabled = data.payment_methods.map((m) => m.key);
            const payments = sale.payments.filter((p) => enabled.includes(p.method)).map((p) => ({ ...p }));
            detail = null;
            edit = {
                sale,
                items: sale.items.map((it) => ({ ...it })),
                payments: payments.length ? payments : [{ method: enabled[0], amount: sale.total_value }],
                reason: '', search: '', results: [], busy: false, error: null,
            };
            draw();
        } catch (err) { alertDialog(err.message, { tone: 'error' }); }
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
        container.querySelectorAll('[data-range]').forEach((b) => b.addEventListener('click', () => {
            const n = Number(b.dataset.range);
            if (n === 0) { from = to = todayISO(); } else if (n === 1) { from = to = daysAgo(1); } else { from = daysAgo(n - 1); to = todayISO(); }
            load();
        }));
        $('#f-from').addEventListener('change', (e) => { if (e.target.value) { from = e.target.value; if (to < from) to = from; load(); } });
        $('#f-to').addEventListener('change', (e) => { if (e.target.value) { to = e.target.value; if (from > to) from = to; load(); } });
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => openDetail(b.dataset.view)));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => openEdit(b.dataset.edit)));
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
            if (!it.sale_item_id && qty === 0) edit.items.splice(i, 1); // an added line taken back out
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
        container.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
            const p = edit.results[+b.dataset.add];
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
        $('#e-save').addEventListener('click', save);
    }

    async function save() {
        const e = edit;
        e.error = null;
        syncSinglePayment();
        if (!e.items.some((it) => it.qty > 0)) { e.error = 'การขายต้องมีสินค้าอย่างน้อย 1 รายการ (ถ้าต้องการยกเลิกทั้งบิล กรุณาแจ้งผู้จัดการ)'; return draw(); }
        if (!e.reason.trim()) { e.error = 'กรุณาระบุเหตุผลที่ขอแก้ไข'; return draw(); }
        if (Math.abs(paidTotal() - editTotal()) > 0.004) { e.error = `ยอดชำระรวมต้องเท่ากับยอดขายใหม่ ฿${money(editTotal())}`; return draw(); }
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
            await load();
            alertDialog('ส่งคำขอแก้ไขแล้ว รอผู้จัดการหรือผู้ดูแลระบบอนุมัติ', { title: 'ส่งคำขอแล้ว' });
        } catch (err) {
            e.busy = false;
            e.error = err.message;
            draw();
        }
    }

    await load();
}
