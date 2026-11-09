import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

const signed = (v) => `${v > 0 ? '+' : v < 0 ? '−' : ''}${money(Math.abs(v))}`;
const differs = (a, b) => Math.abs(a - b) >= 0.005;

/** บัญชีเพื่อผู้ฝากขาย: what it should hold, paying held money out, and checks against the bank. */
export async function render(container, { user, permissions = [] }) {
    const isAdmin = user?.role === 'admin';
    let d = null;
    let pay = null; // the pay form: { consignor, amount, method, note, error, busy }
    let chk = null; // the check form: { date, expected, actual, note, error, busy }
    let chkSeq = 0; // ignore an expected figure that arrives after a newer date was chosen

    async function load() {
        d = (await api.get('/holds')).data;
        if (!chk) chk = { date: d.today, expected: d.expected, actual: '', note: '', error: null, busy: false };
        else if (chk.date === d.today) chk.expected = d.expected;
    }

    const chkDiff = () => (chk.actual === '' ? null : Math.round((Number(chk.actual) - chk.expected) * 100) / 100);

    function diffHtml() {
        const v = chkDiff();
        if (v === null) return '<span class="muted">กรอกยอดจากสมุดบัญชีหรือแอปธนาคาร</span>';
        if (!differs(v, 0)) return '<span class="pill ok">ตรงกับยอดที่ควรมี</span>';
        return `<span style="color:var(--bad);font-weight:600">ต่างจากยอดที่ควรมี ${signed(v)} บาท</span> <span class="muted">ระบุหมายเหตุ เช่น เงินยังไม่ได้นำเข้าบัญชี หรือดอกเบี้ย</span>`;
    }

    function consignorsHtml() {
        return `
        <section class="card">
          <div class="card-head"><h2>เงินที่พักไว้แยกตามผู้ฝากขาย</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>ผู้ฝากขาย</th><th>ช่องทางที่สะดวก</th><th class="num">เงินที่พักไว้ (บาท)</th><th></th></tr></thead>
            <tbody>${d.consignors.map((c) => `
              <tr>
                <td>${escapeHtml(c.code)} - ${escapeHtml(c.name)}${c.phone ? `<div class="muted" style="font-size:11.5px">${escapeHtml(c.phone)}</div>` : ''}</td>
                <td style="font-size:12.5px">${escapeHtml(d.methods[c.pay_channel] || c.pay_channel || '-')}${c.pay_account ? `<div class="muted">${escapeHtml(c.pay_account)}</div>` : ''}</td>
                <td class="num" style="font-weight:600">${money(c.held)}</td>
                <td style="text-align:right">${c.held > 0 ? `<button type="button" class="btn sm" data-pay="${c.id}">จ่ายเงิน</button>` : ''}</td>
              </tr>`).join('') || '<tr><td colspan="4" class="empty">ยังไม่มีเงินพักของผู้ฝากขาย</td></tr>'}
            </tbody>
          </table></div>
        </section>`;
    }

    function checkHtml() {
        return `
        <section class="card card-pad">
          <h3 style="margin:0 0 4px">สอบยอดกับบัญชีธนาคาร</h3>
          <div class="muted" style="font-size:12.5px;margin-bottom:10px">ยอดที่ควรมี ณ สิ้นวันที่เลือก เทียบกับยอดจริงในบัญชี</div>
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
            <label class="field">ยอด ณ วันที่ <input type="date" id="k-date" value="${escapeHtml(chk.date)}" max="${d.today}"></label>
            <div class="field">ยอดที่ควรมี (บาท) <div id="k-expected" style="font-weight:700;font-size:18px;padding:6px 0">${money(chk.expected)}</div></div>
            <label class="field">ยอดในบัญชีจริง (บาท) * <input type="number" id="k-actual" min="0" step="0.01" value="${escapeHtml(chk.actual)}" style="text-align:right"></label>
            <label class="field" style="flex:1;min-width:200px">หมายเหตุ <input id="k-note" maxlength="255" value="${escapeHtml(chk.note)}"></label>
            <button type="button" class="btn" id="k-save" ${chk.busy ? 'disabled' : ''}>${chk.busy ? 'กำลังบันทึก...' : 'บันทึกการสอบยอด'}</button>
          </div>
          <div id="k-diff" style="font-size:12.5px;margin-top:8px">${diffHtml()}</div>
          ${chk.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(chk.error)}</div>` : ''}
          ${d.checks.length ? `
          <details style="margin-top:12px">
            <summary style="cursor:pointer;font-weight:600;font-size:13px">ประวัติการสอบยอด (${d.checks.length})</summary>
            <div class="table-wrap" style="margin-top:8px"><table>
              <thead><tr><th>ยอด ณ วันที่</th><th class="num">ที่ควรมี</th><th class="num">ในบัญชีจริง</th><th class="num">ต่าง</th><th>หมายเหตุ</th><th>ผู้สอบยอด</th></tr></thead>
              <tbody>${d.checks.map((k) => `
                <tr>
                  <td style="white-space:nowrap">${escapeHtml(fmtDate(k.check_date))}</td>
                  <td class="num">${money(k.expected_amount)}</td>
                  <td class="num">${money(k.actual_amount)}</td>
                  <td class="num" style="${differs(k.diff, 0) ? 'color:var(--bad);font-weight:600' : ''}">${differs(k.diff, 0) ? signed(k.diff) : 'ตรงกัน'}</td>
                  <td style="font-size:12.5px">${escapeHtml(k.note || '')}</td>
                  <td class="muted" style="font-size:12.5px">${escapeHtml(k.user_name || '')}<div style="font-size:11.5px">${fmtDateTime(k.created_at)}</div></td>
                </tr>`).join('')}</tbody>
            </table></div>
          </details>` : ''}
        </section>`;
    }

    function movementsHtml() {
        return `
        <section class="card">
          <div class="card-head"><h2>รายการเข้าออกบัญชี</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>วันที่</th><th>เลขที่</th><th>ผู้ฝากขาย</th><th>รายการ</th><th class="num">เข้า</th><th class="num">ออก</th><th class="num">ยอดในบัญชี</th><th></th></tr></thead>
            <tbody>${d.movements.map((r) => {
                const cancelled = r.cancelled_at !== null;
                const isIn = r.direction === 'in';
                const cell = (on) => (on ? `<span style="${cancelled ? 'text-decoration:line-through' : 'font-weight:600'}">${money(r.amount)}</span>` : '');
                return `
              <tr${cancelled ? ' style="opacity:.6"' : ''}>
                <td class="muted" style="white-space:nowrap;font-size:12.5px">${fmtDateTime(r.at)}</td>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(r.doc_no)}</td>
                <td>${escapeHtml(r.consignor_code)} - ${escapeHtml(r.consignor_name)}</td>
                <td style="font-size:12.5px">${isIn ? 'พักเงินจากลิ้นชัก' : `จ่ายให้ผู้ฝากขาย (${escapeHtml(d.methods[r.method] || r.method)})`}
                  ${r.note ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.note)}</div>` : ''}
                  ${cancelled ? `<div><span class="pill muted" style="font-size:11px">ยกเลิกแล้ว</span> <span class="muted" style="font-size:11.5px">${escapeHtml(r.cancel_reason || '')}</span></div>` : ''}
                  <div class="muted" style="font-size:11.5px">โดย ${escapeHtml(r.user_name || '-')}</div></td>
                <td class="num">${cell(isIn)}</td>
                <td class="num">${cell(!isIn)}</td>
                <td class="num">${money(r.balance_after)}</td>
                <td style="text-align:right">${isAdmin && !isIn && !cancelled ? `<button type="button" class="btn sm secondary" data-cancel="${r.id}">ยกเลิก</button>` : ''}</td>
              </tr>`;
            }).join('') || '<tr><td colspan="8" class="empty">ยังไม่มีรายการ พักเงินได้จากสรุปบัญชีรายวัน ข้อ 4</td></tr>'}
            </tbody>
          </table></div>
        </section>`;
    }

    function payHtml() {
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="hp-title" style="width:520px">
            <div class="modal-head"><h2 id="hp-title">จ่ายเงินที่พักไว้</h2>
              <button type="button" class="modal-close" data-pay-close title="ปิด" aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div style="font-weight:600">${escapeHtml(pay.consignor.code)} - ${escapeHtml(pay.consignor.name)}</div>
              <div class="chip" style="margin:8px 0 14px">เงินที่พักไว้: <b>&nbsp;฿ ${money(pay.consignor.held)}</b></div>
              <label class="field" style="margin-bottom:12px">จำนวนเงินที่จ่าย (บาท) *
                <input id="hp-amount" type="number" step="0.01" min="0" max="${pay.consignor.held}" value="${escapeHtml(pay.amount)}"></label>
              <div class="field" style="margin-bottom:12px">ช่องทางจ่ายเงิน
                <div class="actions-row">${Object.entries(d.methods).map(([k, v]) => `<button type="button" class="tab-btn ${pay.method === k ? 'active' : ''}" data-method="${k}">${v}</button>`).join('')}</div>
              </div>
              <label class="field">หมายเหตุ <input id="hp-note" maxlength="255" value="${escapeHtml(pay.note)}" placeholder="เช่น เลขที่รายการโอน"></label>
              <div class="muted" style="font-size:12px;margin-top:10px">จ่ายจากบัญชีเพื่อผู้ฝากขาย ไม่กระทบเงินในลิ้นชักวันนี้</div>
              ${pay.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(pay.error)}</div>` : ''}
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-pay-close>ยกเลิก</button>
              <button type="button" class="btn" id="hp-save" ${pay.busy ? 'disabled' : ''}>${pay.busy ? 'กำลังบันทึก...' : 'บันทึกการจ่ายเงิน'}</button>
            </div>
          </div>
        </div>`;
    }

    function draw() {
        container.innerHTML = `
        <div class="hint"><div class="dot">i</div><div>เงินที่ดึงจากลิ้นชักมาพักไว้รอจ่ายผู้ฝากขาย พักเงินได้ที่<b>สรุปบัญชีรายวัน ข้อ 4</b> เลือกช่องทาง <b>พักเงินในบัญชี</b> และจ่ายออกจากหน้านี้เมื่อผู้ฝากขายร้องขอ</div></div>
        <section class="card card-pad" style="text-align:center">
          <div class="muted" style="font-size:12.5px">ยอดเงินที่ควรมีในบัญชีเพื่อผู้ฝากขาย</div>
          <div style="font-size:30px;font-weight:700;margin:6px 0">฿ ${money(d.expected)}</div>
          <div class="muted" style="font-size:12.5px">เงินที่พักทั้งหมด หักที่จ่ายให้ผู้ฝากขายแล้ว</div>
        </section>
        ${consignorsHtml()}
        ${checkHtml()}
        ${movementsHtml()}
        ${pay ? payHtml() : ''}`;
        wire();
    }

    function wire() {
        const $ = (s) => container.querySelector(s);
        container.querySelectorAll('[data-pay]').forEach((b) => b.addEventListener('click', () => {
            const c = d.consignors.find((x) => Number(x.id) === Number(b.dataset.pay));
            const channel = d.methods[c.pay_channel] ? c.pay_channel : 'cash';
            pay = { consignor: c, amount: String(c.held), method: channel, note: '', error: null, busy: false };
            draw();
            $('#hp-amount')?.focus();
        }));
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancelPayout(Number(b.dataset.cancel))));

        $('#k-date').addEventListener('change', async (e) => {
            if (!e.target.value) return;
            chk.date = e.target.value;
            const seq = ++chkSeq;
            try {
                const res = (await api.get(`/holds/expected?date=${encodeURIComponent(chk.date)}`)).data;
                if (seq !== chkSeq) return;
                chk.expected = res.expected;
                chk.error = null;
            } catch (err) {
                if (seq !== chkSeq) return;
                chk.error = err.message;
            }
            draw();
        });
        // Typed values update in place, so the box keeps its focus.
        $('#k-actual').addEventListener('input', (e) => { chk.actual = e.target.value; $('#k-diff').innerHTML = diffHtml(); });
        $('#k-note').addEventListener('input', (e) => { chk.note = e.target.value; });
        $('#k-save').addEventListener('click', saveCheck);

        if (!pay) return;
        container.querySelectorAll('[data-pay-close]').forEach((b) => b.addEventListener('click', () => { pay = null; draw(); }));
        $('#hp-amount').addEventListener('input', (e) => { pay.amount = e.target.value; });
        $('#hp-note').addEventListener('input', (e) => { pay.note = e.target.value; });
        container.querySelectorAll('[data-method]').forEach((b) => b.addEventListener('click', () => { pay.method = b.dataset.method; draw(); }));
        $('#hp-save').addEventListener('click', savePay);
    }

    async function savePay() {
        const amount = Math.round(Number(pay.amount) * 100) / 100;
        const fail = (msg) => { pay.error = msg; pay.busy = false; draw(); };
        if (!(amount > 0)) return fail('จำนวนเงินที่จ่ายต้องมากกว่า 0');
        if (amount > pay.consignor.held + 0.005) return fail(`จำนวนเงินเกินยอดที่พักไว้ (฿${money(pay.consignor.held)})`);
        const ok = await confirmDialog(
            `จ่ายเงินที่พักไว้ให้ ${pay.consignor.code} - ${pay.consignor.name} ฿${money(amount)} (${d.methods[pay.method]}) จากบัญชีเพื่อผู้ฝากขาย`,
            { title: 'ยืนยันการจ่ายเงิน?', okText: 'บันทึก' }
        );
        if (!ok) return;
        pay.busy = true;
        pay.error = null;
        draw();
        try {
            await api.post('/holds/payouts', { consignor_id: pay.consignor.id, amount, method: pay.method, note: pay.note.trim() });
            pay = null;
            await load();
            draw();
        } catch (e) {
            fail(e.message);
        }
    }

    async function saveCheck() {
        const fail = (msg) => { chk.error = msg; chk.busy = false; draw(); };
        if (chk.actual === '' || !(Number(chk.actual) >= 0)) return fail('กรุณากรอกยอดเงินในบัญชี');
        if (differs(chkDiff(), 0) && !chk.note.trim()) return fail('ยอดในบัญชีไม่ตรงกับยอดที่ควรมี กรุณาระบุหมายเหตุ');
        chk.busy = true;
        chk.error = null;
        draw();
        try {
            await api.post('/holds/checks', { check_date: chk.date, actual_amount: Number(chk.actual), note: chk.note.trim() });
            chk = null;
            await load();
            draw();
        } catch (e) {
            fail(e.message);
        }
    }

    async function cancelPayout(id) {
        const r = d.movements.find((x) => x.direction === 'out' && Number(x.id) === id);
        const reason = await promptDialog(
            `ยอด ฿${money(r.amount)} จะกลับมาเป็นเงินพักของ ${r.consignor_name}`,
            { title: `ยกเลิกการจ่ายเงิน ${r.doc_no}?`, okText: 'ยกเลิกการจ่ายเงิน', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
        );
        if (reason === null || reason === undefined || !String(reason).trim()) return;
        try {
            await api.post(`/holds/payouts/${id}/cancel`, { reason: String(reason).trim() });
            await load();
            draw();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    await load();
    draw();
}
