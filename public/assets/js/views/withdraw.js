import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime, todayISO, qs, DATE_PERIODS, periodRange } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

const METHOD_LABEL = { cash: 'เงินสดที่เคาน์เตอร์', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };
const HOLD_LABEL = 'พักในบัญชีเพื่อผู้ฝากขาย';
const STATUS_LABEL = { pending: ['รออนุมัติ', 'warn'], paid: ['จ่ายแล้ว', 'ok'], cancelled: ['ยกเลิก', 'muted'] };
const methodLabel = (m) => (m === 'hold' ? HOLD_LABEL : METHOD_LABEL[m] || m);

export async function render(container, { permissions, user }) {
    const options = (await api.get('/consignors/options')).data;
    // No consignor until one is chosen, so money is never paid to someone by accident.
    let consignorId = null;
    let balance = 0;
    let amount = '';
    let method = 'cash';
    // Paying over the balance (the shop is responsible, e.g. lost goods): account goes negative.
    let overdraw = false;
    let overdrawReason = '';
    let error = null;
    let rows = [];
    let formOpen = false; // the withdrawal form, on a modal
    const canApprove = permissions.includes('withdraw.approve');
    const isAdmin = user?.role === 'admin';
    let period = 'today';
    let customFrom = '';
    let customTo = '';
    let limit = null; // the most the server sends for a period
    let listError = null;
    let canHold = false;
    let seq = 0; // ignore a reply that arrives after a newer period was chosen
    let edit = null; // the correction form: { row, amount, method, reason, balance, error, busy }

    const range = () => (period === 'custom' ? [customFrom, customTo] : periodRange(period, todayISO()));

    function rangeProblem() {
        const [from, to] = range();
        if (!from || !to) return 'เลือกวันที่เริ่มต้นและวันที่สิ้นสุด';
        return from > to ? 'วันที่เริ่มต้นต้องไม่เกินวันที่สิ้นสุด' : null;
    }

    async function refreshBalance() {
        balance = 0;
        if (!consignorId) return;
        const res = await api.get(`/consignors/${consignorId}/passbook`);
        balance = res.balance;
    }

    async function fetchRows() {
        const mine = ++seq;
        listError = null;
        if (rangeProblem()) {
            rows = [];
            return;
        }
        const [from, to] = range();
        try {
            const res = await api.get(`/withdrawals${qs({ from, to })}`);
            if (mine !== seq) return;
            rows = res.data;
            limit = res.limit ?? null;
            canHold = !!res.can_hold;
        } catch (e) {
            if (mine !== seq) return;
            rows = [];
            listError = e.message;
        }
    }

    async function load() {
        await refreshBalance();
        await fetchRows();
        draw();
    }

    const over = () => amount !== '' && Number(amount) > balance;
    const overdrawOk = () => overdraw && overdrawReason.trim() !== '';
    const blocked = () => !consignorId || !amount || Number(amount) <= 0 || (over() && canApprove && !overdrawOk());
    const negativeAfter = () => money(balance - Number(amount || 0));

    function formHtml() {
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="wd-title" style="width:560px">
            <div class="modal-head"><h2 id="wd-title">ทำรายการเบิกเงิน</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button></div>
            <div class="modal-body">
            <label class="field" style="margin-bottom:12px">ผู้ฝากขาย *
              <select id="sel-consignor" required>
                <option value="" ${consignorId == null ? 'selected' : ''} disabled>— เลือกผู้ฝากขาย —</option>
                ${options.map((o) => `<option value="${o.id}" ${Number(o.id) === Number(consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
            </label>
            ${consignorId ? `<div class="chip" style="margin-bottom:14px">ยอดที่เบิกได้สูงสุด: <b>&nbsp;฿ ${money(balance)}</b></div>` : ''}
            <label class="field" style="margin-bottom:10px">จำนวนเงินที่เบิก (บาท)
              <input id="f-amount" type="number" step="0.25" min="0" value="${escapeHtml(amount)}" ${consignorId ? '' : 'disabled'}>
            </label>
            <div class="actions-row" style="margin-bottom:14px">
              ${[100, 500, 1000].map((n) => `<button type="button" class="btn secondary sm" data-quick="${n}" ${consignorId ? '' : 'disabled'}>${n} บาท</button>`).join('')}
              <button type="button" class="btn secondary sm" data-quick="all" ${consignorId ? '' : 'disabled'}>เบิกทั้งหมด</button>
            </div>
            <div data-over style="margin-bottom:12px" ${over() ? '' : 'hidden'}>
              ${canApprove ? `
                <div class="${overdraw ? 'chip' : 'error-box'}" data-over-msg style="margin-bottom:8px">${overdraw
                    ? `เบิกเกินยอด บัญชีจะติดลบ <b>&nbsp;฿ <span data-neg>${negativeAfter()}</span></b>&nbsp;หักคืนจากยอดขายครั้งต่อไป`
                    : 'จำนวนเงินที่กรอกเกินยอดคงเหลือ'}</div>
                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin-bottom:8px">
                  <input type="checkbox" id="f-overdraw" ${overdraw ? 'checked' : ''} style="margin-top:3px">
                  <span>ร้านรับผิดชอบ (เช่น สินค้าสูญหาย) ให้เบิกเกินยอดได้ คิดบัญชีติดลบไว้ก่อน</span>
                </label>
                ${overdraw ? `<input id="f-overdraw-reason" type="text" maxlength="150" placeholder="เหตุผล (จำเป็น) เช่น สินค้าสูญหาย 3 ชิ้น ร้านชดใช้" value="${escapeHtml(overdrawReason)}" style="width:100%">` : ''}
              ` : '<div class="error-box">จำนวนเงินที่กรอกเกินยอดคงเหลือ ผู้อนุมัติจะพิจารณาก่อนจ่าย</div>'}
            </div>
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
            <label class="field">ช่องทางจ่ายเงิน
              <div class="actions-row">
                ${Object.entries(METHOD_LABEL).map(([k, v]) => `<button type="button" class="tab-btn ${method === k ? 'active' : ''}" data-method="${k}">${v}</button>`).join('')}
              </div>
            </label>
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-close-form>ยกเลิก</button>
              <button type="button" class="btn" id="btn-submit" ${blocked() ? 'disabled' : ''}>
                ${canApprove ? 'บันทึกการจ่ายเงินและออกใบสำคัญรับเงิน' : 'ส่งคำขอเบิกเงินเพื่อรออนุมัติ'}</button>
            </div>
          </div>
        </div>`;
    }

    function rowsHtml() {
        const problem = rangeProblem();
        return rows.map((r) => {
            const [label, cls] = STATUS_LABEL[r.status] || ['-', 'muted'];
            return `<tr>
                    <td><div style="font-weight:600">${escapeHtml(r.consignor_name)}</div><div class="muted" style="font-size:11.5px">${fmtDateTime(r.paid_at || r.requested_at)} · ${escapeHtml(r.doc_no)}</div>
                      ${(r.edits || []).map((x) => `<div class="muted" style="font-size:11.5px;margin-top:2px">แก้ไขจาก ฿${money(x.old_amount)} (${escapeHtml(methodLabel(x.old_method))}): ${escapeHtml(x.reason)} · โดย ${escapeHtml(x.user_name || '-')} · ${fmtDateTime(x.at)}</div>`).join('')}</td>
                    <td class="muted">${escapeHtml(methodLabel(r.method))}</td>
                    <td class="num" style="font-weight:600">${money(r.amount)}</td>
                    <td><span class="pill ${cls}">${label}</span>
                      ${r.status === 'cancelled' && r.cancel_reason ? `<div class="muted" style="font-size:11.5px;margin-top:4px">เหตุผล: ${escapeHtml(r.cancel_reason)}${r.cancelled_by_name ? ` · โดย ${escapeHtml(r.cancelled_by_name)}` : ''}${r.cancelled_at ? ` · ${fmtDateTime(r.cancelled_at)}` : ''}</div>` : ''}</td>
                    <td style="text-align:right;white-space:nowrap">${r.status === 'pending' && canApprove ? `<button type="button" class="btn sm" data-approve="${r.id}">อนุมัติจ่าย</button>` : ''}
                      ${isAdmin && (r.status === 'pending' || r.status === 'paid') ? `<button type="button" class="btn sm secondary" data-edit="${r.id}">แก้ไข</button>
                      <button type="button" class="btn sm secondary" data-cancel="${r.id}">ยกเลิก</button>` : ''}</td>
                  </tr>`;
        }).join('') || `<tr><td colspan="5" class="empty">${listError ? `โหลดรายการไม่สำเร็จ: ${escapeHtml(listError)}` : problem ? escapeHtml(problem) : 'ไม่มีรายการในช่วงเวลานี้'}</td></tr>`;
    }

    // Only the rows change here, so the date boxes keep their focus.
    function drawRows() {
        const [from, to] = range();
        container.querySelector('#wd-range').textContent = period === 'custom' ? ''
            : from === to ? fmtDate(from) : `${fmtDate(from)} – ${fmtDate(to)}`;
        container.querySelector('#wd-note').textContent = limit && rows.length >= limit
            ? `แสดง ${limit.toLocaleString('th-TH')} รายการล่าสุดของช่วงนี้ เลือกช่วงเวลาให้แคบลงเพื่อดูให้ครบ` : '';
        container.querySelector('#wd-rows').innerHTML = rowsHtml();
        container.querySelectorAll('[data-approve]').forEach((btn) => btn.addEventListener('click', () => approve(btn.dataset.approve)));
        container.querySelectorAll('[data-cancel]').forEach((btn) => btn.addEventListener('click', () => cancel(btn.dataset.cancel)));
        container.querySelectorAll('[data-edit]').forEach((btn) => btn.addEventListener('click', () => openEdit(btn.dataset.edit)));
    }

    async function reloadRows() {
        const mine = seq + 1;
        await fetchRows();
        if (mine === seq) drawRows();
    }

    function editHtml() {
        const e = edit;
        const r = e.row;
        const paid = r.status === 'paid';
        const after = e.balance === null ? null : e.balance + Number(r.amount) - Number(e.amount || 0);
        const methods = Object.entries(METHOD_LABEL);
        if (paid && (canHold || r.method === 'hold')) methods.push(['hold', HOLD_LABEL]);
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="we-title" style="width:560px">
            <div class="modal-head"><h2 id="we-title">แก้ไขรายการ ${escapeHtml(r.doc_no)}</h2>
              <button type="button" class="modal-close" data-edit-close title="ปิด" aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div style="font-weight:600">${escapeHtml(r.consignor_name)}</div>
              <div class="muted" style="font-size:12.5px;margin-bottom:12px">เดิม ฿${money(r.amount)} · ${escapeHtml(methodLabel(r.method))}</div>
              <label class="field" style="margin-bottom:12px">จำนวนเงิน (บาท) *
                <input id="we-amount" type="number" step="0.25" min="0" value="${escapeHtml(e.amount)}"></label>
              <div class="field" style="margin-bottom:12px">ช่องทางจ่ายเงิน
                <div class="actions-row">${methods.map(([k, v]) => `<button type="button" class="tab-btn ${e.method === k ? 'active' : ''}" data-we-method="${k}">${v}</button>`).join('')}</div>
              </div>
              <label class="field">เหตุผลที่แก้ไข *
                <input id="we-reason" maxlength="150" value="${escapeHtml(e.reason)}" placeholder="เช่น กรอกจำนวนเงินผิด เลือกช่องทางผิด"></label>
              ${paid ? `<div class="muted" style="font-size:12px;margin-top:10px">สมุดบัญชีผู้ฝากจะมีรายการแก้ไขต่อท้าย รายการเดิมยังอยู่${after !== null ? ` · <span id="we-after">ยอดคงเหลือหลังแก้ไข ฿${money(after)}</span>` : ''}<br>ตัวเลขข้อ 4 และเงินที่ต้องส่งในสรุปบัญชีของวันที่จ่ายจะเปลี่ยนตาม ถ้าส่งสรุปวันนั้นแล้วให้ตรวจอีกครั้ง</div>` : ''}
              ${e.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(e.error)}</div>` : ''}
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-edit-close>ยกเลิก</button>
              <button type="button" class="btn" id="we-save" ${e.busy ? 'disabled' : ''}>${e.busy ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข'}</button>
            </div>
          </div>
        </div>`;
    }

    async function openEdit(id) {
        const row = rows.find((x) => String(x.id) === String(id));
        if (!row) return;
        let balance = null;
        try {
            balance = Number((await api.get(`/consignors/${row.consignor_id}/passbook`)).balance);
        } catch (e) { /* the form works without showing the balance */ }
        edit = { row, amount: String(Number(row.amount)), method: row.method, reason: '', balance, error: null, busy: false };
        draw();
        container.querySelector('#we-amount')?.focus();
    }

    function wireEdit() {
        const $ = (s) => container.querySelector(s);
        container.querySelectorAll('[data-edit-close]').forEach((b) => b.addEventListener('click', () => { edit = null; draw(); }));
        $('#we-amount').addEventListener('input', (e) => {
            edit.amount = e.target.value;
            const el = $('#we-after');
            if (el) el.textContent = `ยอดคงเหลือหลังแก้ไข ฿${money(edit.balance + Number(edit.row.amount) - Number(edit.amount || 0))}`;
        });
        $('#we-reason').addEventListener('input', (e) => { edit.reason = e.target.value; });
        container.querySelectorAll('[data-we-method]').forEach((b) => b.addEventListener('click', () => { edit.method = b.dataset.weMethod; draw(); }));
        $('#we-save').addEventListener('click', saveEdit);
    }

    async function saveEdit() {
        const e = edit;
        const r = e.row;
        const amount = Math.round(Number(e.amount) * 100) / 100;
        const fail = (msg) => { e.error = msg; e.busy = false; draw(); };
        if (!(amount > 0)) return fail('จำนวนเงินต้องมากกว่า 0');
        if (Math.abs(amount - Number(r.amount)) < 0.005 && e.method === r.method) return fail('ยังไม่ได้เปลี่ยนจำนวนเงินหรือช่องทาง');
        if (!e.reason.trim()) return fail('กรุณาระบุเหตุผลที่แก้ไข');
        const ok = await confirmDialog(
            `${r.doc_no} · ${r.consignor_name}: ฿${money(r.amount)} (${methodLabel(r.method)}) เป็น ฿${money(amount)} (${methodLabel(e.method)}) · เหตุผล: ${e.reason.trim()}`,
            { title: 'ยืนยันการแก้ไข?', okText: 'บันทึก' }
        );
        if (!ok) return;
        e.busy = true;
        e.error = null;
        draw();
        try {
            await api.put(`/withdrawals/${r.id}`, { amount, method: e.method, reason: e.reason.trim() });
            edit = null;
            await load();
        } catch (err) {
            fail(err.message);
        }
    }

    function draw() {
        const pending = rows.filter((r) => r.status === 'pending').length;
        container.innerHTML = `
        <div class="actions-row" style="justify-content:flex-end">
          <button type="button" class="btn" id="btn-open" style="font-size:15px;padding:12px 22px">+ เบิกเงินให้ผู้ฝากขาย</button>
        </div>
        <section class="card">
          <div class="card-head">
            <h2>คำขอเบิกเงินและการจ่ายล่าสุด</h2>
            ${pending ? `<span class="pill warn">รออนุมัติ ${pending}</span>` : ''}
          </div>
          <div class="sv-filters" style="padding:12px 18px;border-bottom:1px solid var(--border)">
            <div class="tabs" role="group" aria-label="ช่วงวันที่จ่ายเงิน" id="wd-periods">
              ${DATE_PERIODS.map(([k, l]) => `<button type="button" class="tab-btn${k === period ? ' active' : ''}" data-period="${k}" aria-pressed="${k === period}">${l}</button>`).join('')}
            </div>
            <div class="sv-controls">
              <span id="wd-custom" style="display:${period === 'custom' ? 'flex' : 'none'};flex-wrap:wrap;gap:8px;align-items:center">
                <label>ตั้งแต่ <input type="date" id="wd-from" value="${escapeHtml(customFrom)}"></label>
                <label>ถึง <input type="date" id="wd-to" value="${escapeHtml(customTo)}"></label>
              </span>
              <span class="muted" id="wd-range" style="font-size:12.5px"></span>
            </div>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>ผู้ฝากขาย</th><th>วิธีจ่าย</th><th class="num">จำนวนเงิน</th><th>สถานะ</th><th></th></tr></thead>
            <tbody id="wd-rows"></tbody>
          </table></div>
          <div class="muted" id="wd-note" style="font-size:12.5px;padding:0 18px"></div>
        </section>
        ${formOpen ? formHtml() : ''}
        ${edit ? editHtml() : ''}`;

        container.querySelector('#btn-open').addEventListener('click', () => {
            formOpen = true;
            error = null;
            draw();
            container.querySelector('#sel-consignor')?.focus();
        });
        const box = container;
        box.querySelector('#wd-periods').addEventListener('click', (e) => {
            const b = e.target.closest('[data-period]');
            if (!b || b.dataset.period === period) return;
            // Custom dates start from what was on screen, so a nearby range takes only a small change.
            if (b.dataset.period === 'custom') [customFrom, customTo] = range();
            period = b.dataset.period;
            box.querySelectorAll('[data-period]').forEach((t) => {
                t.classList.toggle('active', t === b);
                t.setAttribute('aria-pressed', String(t === b));
            });
            box.querySelector('#wd-custom').style.display = period === 'custom' ? 'flex' : 'none';
            box.querySelector('#wd-from').value = customFrom;
            box.querySelector('#wd-to').value = customTo;
            reloadRows();
        });
        box.querySelector('#wd-from').addEventListener('change', (e) => { customFrom = e.target.value; reloadRows(); });
        box.querySelector('#wd-to').addEventListener('change', (e) => { customTo = e.target.value; reloadRows(); });
        drawRows();
        if (edit) wireEdit();
        if (!formOpen) return;
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', () => { formOpen = false; error = null; draw(); }));
        container.querySelector('#sel-consignor').addEventListener('change', async (e) => {
            consignorId = Number(e.target.value) || null;
            amount = '';
            overdraw = false;
            overdrawReason = '';
            error = null;
            await refreshBalance();
            draw();
            container.querySelector('#f-amount')?.focus();
        });
        // Typing the amount updates the warning and button in place, so the box keeps its focus.
        container.querySelector('#f-amount').addEventListener('input', (e) => {
            amount = e.target.value;
            container.querySelector('[data-over]').hidden = !over();
            const neg = container.querySelector('[data-neg]');
            if (neg) neg.textContent = negativeAfter();
            container.querySelector('#btn-submit').disabled = blocked();
        });
        container.querySelector('#f-overdraw')?.addEventListener('change', (e) => {
            overdraw = e.target.checked;
            draw();
            container.querySelector('#f-overdraw-reason')?.focus();
        });
        container.querySelector('#f-overdraw-reason')?.addEventListener('input', (e) => {
            overdrawReason = e.target.value;
            container.querySelector('#btn-submit').disabled = blocked();
        });
        container.querySelectorAll('[data-quick]').forEach((btn) => btn.addEventListener('click', () => {
            amount = btn.dataset.quick === 'all' ? String(balance) : btn.dataset.quick;
            draw();
        }));
        container.querySelectorAll('[data-method]').forEach((btn) => btn.addEventListener('click', () => { method = btn.dataset.method; draw(); }));
        container.querySelector('#btn-submit').addEventListener('click', submit);
    }

    async function submit() {
        try {
            await api.post('/withdrawals', {
                consignor_id: consignorId,
                amount: Number(amount),
                method,
                overdraw_reason: over() && overdraw ? overdrawReason.trim() : '',
            });
            amount = '';
            overdraw = false;
            overdrawReason = '';
            error = null;
            formOpen = false;
            consignorId = null; // the next one starts with no consignor chosen
            await load();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    async function approve(id) {
        try {
            await api.post(`/withdrawals/${id}/approve`);
            await load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    async function cancel(id) {
        const r = rows.find((x) => String(x.id) === String(id));
        const reason = await promptDialog(
            r.status === 'paid'
                ? `ยอด ฿${money(r.amount)} จะคืนเข้าบัญชีของ ${r.consignor_name} (สมุดบัญชีจะมีรายการยกเลิกต่อท้าย รายการเบิกเดิมยังอยู่)`
                : `คำขอเบิก ฿${money(r.amount)} ของ ${r.consignor_name} จะถูกยกเลิก`,
            { title: `ยกเลิกรายการเบิก ${r.doc_no}?`, okText: 'ยกเลิกรายการเบิก', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
        );
        if (reason === null || reason === undefined || !String(reason).trim()) return;
        try {
            await api.post(`/withdrawals/${id}/cancel`, { reason: String(reason).trim() });
            await load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    await load();
}
