import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';

const METHOD_LABEL = { cash: 'เงินสดที่เคาน์เตอร์', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };
const STATUS_LABEL = { pending: ['รออนุมัติ', 'warn'], paid: ['จ่ายแล้ว', 'ok'], cancelled: ['ยกเลิก', 'muted'] };

export async function render(container, { permissions }) {
    const options = (await api.get('/consignors/options')).data;
    // No consignor until one is chosen, so money is never paid to someone by accident.
    let consignorId = null;
    let balance = 0;
    let amount = '';
    let method = 'cash';
    let error = null;
    let rows = [];
    let formOpen = false; // the withdrawal form, on a modal
    const canApprove = permissions.includes('withdraw.approve');

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
    const blocked = () => !consignorId || !amount || Number(amount) <= 0 || (over() && canApprove);

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
            <div class="error-box" data-over style="margin-bottom:12px" ${over() ? '' : 'hidden'}>เบิกไม่ได้ จำนวนเงินที่กรอกเกินยอดคงเหลือ</div>
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
                    <td><span class="pill ${cls}">${label}</span></td>
                    <td style="text-align:right">${r.status === 'pending' && canApprove ? `<button type="button" class="btn sm" data-approve="${r.id}">อนุมัติจ่าย</button>` : ''}</td>
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
        if (!formOpen) return;
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', () => { formOpen = false; error = null; draw(); }));
        container.querySelector('#sel-consignor').addEventListener('change', async (e) => {
            consignorId = Number(e.target.value) || null;
            amount = '';
            error = null;
            await refreshBalance();
            draw();
            container.querySelector('#f-amount')?.focus();
        });
        // Typing the amount updates the warning and button in place, so the box keeps its focus.
        container.querySelector('#f-amount').addEventListener('input', (e) => {
            amount = e.target.value;
            container.querySelector('[data-over]').hidden = !over();
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
            await api.post('/withdrawals', { consignor_id: consignorId, amount: Number(amount), method });
            amount = '';
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

    function alertError(msg) {
        error = msg;
        draw();
    }

    await load();
}
