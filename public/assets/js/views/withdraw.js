import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';
import { promptDialog } from '../dialog.js';

const METHOD_LABEL = { cash: 'เงินสดที่เคาน์เตอร์', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };
const STATUS_LABEL = { pending: ['รออนุมัติ', 'warn'], paid: ['จ่ายแล้ว', 'ok'], cancelled: ['ยกเลิก', 'muted'] };

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

    async function refreshBalance() {
        balance = 0;
        if (!consignorId) return;
        const res = await api.get(`/consignors/${consignorId}/passbook`);
        balance = res.balance;
    }

    async function load() {
        await refreshBalance();
        rows = (await api.get('/withdrawals')).data;
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
          <div class="table-wrap"><table>
            <thead><tr><th>ผู้ฝากขาย</th><th>วิธีจ่าย</th><th class="num">จำนวนเงิน</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
              ${rows.map((r) => {
                  const [label, cls] = STATUS_LABEL[r.status] || ['-', 'muted'];
                  return `<tr>
                    <td><div style="font-weight:600">${escapeHtml(r.consignor_name)}</div><div class="muted" style="font-size:11.5px">${fmtDateTime(r.requested_at)}</div></td>
                    <td class="muted">${METHOD_LABEL[r.method] || r.method}</td>
                    <td class="num" style="font-weight:600">${money(r.amount)}</td>
                    <td><span class="pill ${cls}">${label}</span>
                      ${r.status === 'cancelled' && r.cancel_reason ? `<div class="muted" style="font-size:11.5px;margin-top:4px">เหตุผล: ${escapeHtml(r.cancel_reason)}${r.cancelled_by_name ? ` · โดย ${escapeHtml(r.cancelled_by_name)}` : ''}${r.cancelled_at ? ` · ${fmtDateTime(r.cancelled_at)}` : ''}</div>` : ''}</td>
                    <td style="text-align:right;white-space:nowrap">${r.status === 'pending' && canApprove ? `<button type="button" class="btn sm" data-approve="${r.id}">อนุมัติจ่าย</button>` : ''}
                      ${isAdmin && (r.status === 'pending' || r.status === 'paid') ? `<button type="button" class="btn sm secondary" data-cancel="${r.id}">ยกเลิก</button>` : ''}</td>
                  </tr>`;
              }).join('') || '<tr><td colspan="5" class="empty">ยังไม่มีรายการ</td></tr>'}
            </tbody>
          </table></div>
        </section>
        ${formOpen ? formHtml() : ''}`;

        container.querySelector('#btn-open').addEventListener('click', () => {
            formOpen = true;
            error = null;
            draw();
            container.querySelector('#sel-consignor')?.focus();
        });
        container.querySelectorAll('[data-approve]').forEach((btn) => btn.addEventListener('click', () => approve(btn.dataset.approve)));
        container.querySelectorAll('[data-cancel]').forEach((btn) => btn.addEventListener('click', () => cancel(btn.dataset.cancel)));
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
            alertError(e.message);
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
            alertError(e.message);
        }
    }

    function alertError(msg) {
        error = msg;
        draw();
    }

    await load();
}
