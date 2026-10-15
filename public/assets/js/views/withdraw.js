import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';

const METHOD_LABEL = { cash: 'เงินสดที่เคาน์เตอร์', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };
const STATUS_LABEL = { pending: ['รออนุมัติ', 'warn'], paid: ['จ่ายแล้ว', 'ok'], cancelled: ['ยกเลิก', 'muted'] };

export async function render(container, { permissions }) {
    const options = (await api.get('/consignors/options')).data;
    let consignorId = options[0]?.id ?? null;
    let balance = 0;
    let amount = '';
    let method = 'cash';
    let error = null;
    const canApprove = permissions.includes('withdraw.approve');

    async function refreshBalance() {
        if (!consignorId) return;
        const res = await api.get(`/consignors/${consignorId}/passbook`);
        balance = res.balance;
    }

    async function load() {
        await refreshBalance();
        const list = await api.get('/withdrawals');
        draw(list.data);
    }

    function draw(rows) {
        const over = amount !== '' && Number(amount) > balance;
        container.innerHTML = `
        <div class="two-col">
          <section class="card card-pad">
            <h2 style="margin-bottom:14px">ทำรายการเบิกเงิน</h2>
            <label class="field" style="margin-bottom:12px">ผู้ฝากขาย
              <select id="sel-consignor">${options.map((o) => `<option value="${o.id}" ${o.id === consignorId ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
            </label>
            <div class="chip" style="margin-bottom:14px">ยอดที่เบิกได้สูงสุด: <b>&nbsp;฿ ${money(balance)}</b></div>
            <label class="field" style="margin-bottom:10px">จำนวนเงินที่เบิก (บาท)
              <input id="f-amount" type="number" step="0.25" min="0" value="${amount}">
            </label>
            <div class="actions-row" style="margin-bottom:14px">
              ${[100, 500, 1000].map((n) => `<button type="button" class="btn secondary sm" data-quick="${n}">${n} บาท</button>`).join('')}
              <button type="button" class="btn secondary sm" data-quick="all">เบิกทั้งหมด</button>
            </div>
            ${over ? `<div class="error-box" style="margin-bottom:12px">เบิกไม่ได้ จำนวนเงินที่กรอกเกินยอดคงเหลือ</div>` : ''}
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
            <label class="field" style="margin-bottom:14px">ช่องทางจ่ายเงิน
              <div class="actions-row">
                ${Object.entries(METHOD_LABEL).map(([k, v]) => `<button type="button" class="tab-btn ${method === k ? 'active' : ''}" data-method="${k}">${v}</button>`).join('')}
              </div>
            </label>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${(!amount || Number(amount) <= 0 || (over && canApprove)) ? 'disabled' : ''}>
              ${canApprove ? 'บันทึกการจ่ายเงินและออกใบสำคัญรับเงิน' : 'ส่งคำขอเบิกเงินเพื่อรออนุมัติ'}
            </button>
          </section>

          <section class="card">
            <div class="card-head">
              <h2>คำขอเบิกเงินและการจ่ายล่าสุด</h2>
              ${rows.filter((r) => r.status === 'pending').length ? `<span class="pill warn">รออนุมัติ ${rows.filter((r) => r.status === 'pending').length}</span>` : ''}
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
                }).join('') || `<tr><td colspan="5" class="empty">ยังไม่มีรายการ</td></tr>`}
              </tbody>
            </table></div>
          </section>
        </div>`;

        container.querySelector('#sel-consignor').addEventListener('change', async (e) => {
            consignorId = Number(e.target.value);
            await load();
        });
        container.querySelector('#f-amount').addEventListener('input', (e) => { amount = e.target.value; draw(); });
        container.querySelectorAll('[data-quick]').forEach((btn) => btn.addEventListener('click', () => {
            amount = btn.dataset.quick === 'all' ? String(balance) : btn.dataset.quick;
            draw();
        }));
        container.querySelectorAll('[data-method]').forEach((btn) => btn.addEventListener('click', () => { method = btn.dataset.method; draw(); }));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', submit);
        container.querySelectorAll('[data-approve]').forEach((btn) => btn.addEventListener('click', () => approve(btn.dataset.approve)));
    }

    async function submit() {
        try {
            await api.post('/withdrawals', { consignor_id: consignorId, amount: Number(amount), method });
            amount = '';
            error = null;
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
            error = e.message;
            draw();
        }
    }

    await load();
}
