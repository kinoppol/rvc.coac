import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

const METHODS = { cash: 'เงินสด', transfer: 'โอนเงินเข้าบัญชี', qr: 'สแกน QR-Code', card: 'บัตรเครดิต' };
const EVENT_LABEL = {
    open: ['ระบุเงินในลิ้นชักก่อนขาย', 'ok'], open_late: ['ระบุเงินในลิ้นชักหลังเริ่มขาย', 'warn'], skip: ['ข้ามการระบุเงินในลิ้นชัก', 'warn'],
    close: ['ปิดยอด', 'ok'], recount: ['นับเงินปิดยอดใหม่', 'muted'], adjust_open: ['แก้เงินตั้งต้น', 'muted'], payment_edit: ['แก้วิธีชำระเงิน', 'muted'],
    wage_out: ['จ่ายค่าแรงจากลิ้นชัก', 'muted'], wage_cancel: ['ยกเลิกจ่ายค่าแรง', 'muted'],
};

/**
 * เงินในลิ้นชัก / ปิดยอดประจำวัน: opening cash, cash from sales and the closing count,
 * plus the day's totals per payment method. Admin / store manager also correct payment
 * methods, fix the opening count and review what staff did (skips, late counts).
 */
export async function render(container, { params = {} } = {}) {
    let date = params.date || null;
    let data = null;
    let behavior = null;
    let editing = null; // { sale, lines: [{ method, amount }], error, busy }

    async function load() {
        const res = await api.get(`/drawer/summary${date ? `?date=${encodeURIComponent(date)}` : ''}`);
        data = res.data;
        date = data.date;
        if (data.can_manage && !behavior) {
            behavior = await api.get('/drawer/behavior?days=30').catch(() => null);
        }
        draw();
    }

    function draw() {
        const d = data;
        const dr = d.drawer;
        const closed = dr?.closed_at;
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between;align-items:center">
            <div><div style="font-weight:700;font-size:16px">เงินในลิ้นชักประจำวันที่ ${escapeHtml(fmtDate(d.date))}</div>
              <div class="muted" style="font-size:12.5px">${d.date === d.today ? 'วันทำการปัจจุบัน' : 'ย้อนหลัง'} · ยอดขาย ${count(d.total.sales)} บิล ฿${money(d.total.amount)}</div></div>
            ${d.can_manage ? `<label class="field" style="flex-direction:row;align-items:center;gap:8px">วันที่ <input type="date" id="dr-date" value="${escapeHtml(d.date)}"></label>` : ''}
          </div>
        </section>

        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">เงินตั้งต้นในลิ้นชัก</div>
            <div class="val">${dr ? `฿ ${money(dr.opening_cash)}` : '—'}</div>
            <div class="sub">${dr ? `${escapeHtml(dr.opened_by_name || '-')} · ${escapeHtml(fmtDateTime(dr.opened_at))}
              ${Number(dr.late_open) ? `<br><span class="pill warn">ระบุหลังเริ่มขาย</span> นับได้ ฿${money(dr.counted_at_open)} − เงินสดที่ขายไปแล้ว ฿${money(dr.cash_sales_before_open)}` : ''}
              ${dr.open_note ? `<br>หมายเหตุ: ${escapeHtml(dr.open_note)}` : ''}` : '<span class="pill warn">ยังไม่ได้ระบุ</span>'}
              ${dr && d.can_manage ? '<br><button type="button" class="btn secondary sm" id="dr-adjust" style="margin-top:6px">แก้เงินตั้งต้น</button>' : ''}</div></div>
          <div class="kpi"><div class="lbl">เงินสดจากการขาย</div><div class="val">฿ ${money(d.cash_sales)}</div>
            <div class="sub">รับเงินสดสุทธิหลังทอนเงิน</div></div>
          <div class="kpi"><div class="lbl">เงินสดที่ควรมีในลิ้นชัก</div><div class="val">${d.expected_cash !== null ? `฿ ${money(d.expected_cash)}` : '—'}</div>
            <div class="sub">เงินตั้งต้น + เงินสดจากการขาย${d.cash_out ? ` − จ่ายค่าแรงจากลิ้นชัก ฿${money(d.cash_out)}` : ''}</div></div>
          <div class="kpi"><div class="lbl">ปิดยอด</div>
            ${closed ? `<div class="val">฿ ${money(dr.counted_cash)}</div>
              <div class="sub">${diffHtml(Number(dr.counted_cash) - Number(d.expected_cash))}<br>${escapeHtml(dr.closed_by_name || '-')} · ${escapeHtml(fmtDateTime(dr.closed_at))}
                ${dr.close_note ? `<br>หมายเหตุ: ${escapeHtml(dr.close_note)}` : ''}</div>`
              : `<div class="val">—</div><div class="sub">ยังไม่ได้ปิดยอด</div>`}</div>
        </div>

        <div class="two-col">
          <section class="card card-pad">
            <h2 style="margin-bottom:10px">ยอดขายตามวิธีชำระเงิน</h2>
            <div class="table-wrap"><table>
              <thead><tr><th>วิธีชำระ</th><th class="num">จำนวนบิล</th><th class="num">ยอดเงิน</th></tr></thead>
              <tbody>
                ${d.methods.map((m) => `<tr><td>${escapeHtml(m.label)}</td><td class="num">${count(m.sales)}</td><td class="num">${money(m.amount)}</td></tr>`).join('')}
                ${d.unknown.sales ? `<tr><td class="muted">ไม่ทราบวิธีชำระ (นำเข้าจาก POS ภายนอก)</td><td class="num">${count(d.unknown.sales)}</td><td class="num">${money(d.unknown.amount)}</td></tr>` : ''}
                ${!d.methods.length && !d.unknown.sales ? '<tr><td colspan="3" class="empty">ยังไม่มียอดขาย</td></tr>' : ''}
              </tbody>
              <tfoot><tr style="font-weight:700"><td>รวม</td><td class="num">${count(d.total.sales)}</td><td class="num">${money(d.total.amount)}</td></tr></tfoot>
            </table></div>
            <div class="muted" style="font-size:12px;margin-top:8px">บิลที่ชำระแบบผสมนับในทุกวิธีที่ใช้ชำระ</div>
          </section>

          <section class="card card-pad">
            <h2 style="margin-bottom:10px">${!dr ? 'ระบุเงินในลิ้นชักตอนเปิดร้าน' : closed ? 'นับเงินปิดยอดใหม่' : 'ปิดยอด (นับเงินในลิ้นชักเมื่อปิดร้าน)'}</h2>
            ${!dr && d.date === d.today ? `
                 <div class="muted" style="margin-bottom:10px">ยังไม่ได้ระบุเงินในลิ้นชักของวันนี้ — นับเงินสดทั้งหมดในลิ้นชักแล้วใส่จำนวนเงิน
                   ${d.cash_sales > 0 ? `<br><b>วันนี้ขายเป็นเงินสดไปแล้ว ฿${money(d.cash_sales)}</b> ระบบจะหักยอดนี้ออกจากเงินที่นับได้ให้อัตโนมัติ` : ''}</div>
                 <label class="field">เงินในลิ้นชัก (บาท) <input id="dr-open" inputmode="decimal" placeholder="0.00"></label>
                 <label class="field" style="margin-top:10px">หมายเหตุ (ถ้ามี) <input id="dr-open-note" maxlength="200"></label>
                 <button type="button" class="btn" id="dr-open-save" style="width:100%;margin-top:12px">บันทึกเงินในลิ้นชักตอนเปิดร้าน</button>`
              : !dr ? '<div class="muted">วันนี้ไม่มีการระบุเงินในลิ้นชัก</div>'
              : closed && !d.can_manage ? '<div class="pill ok">ปิดยอดของวันนี้แล้ว</div>'
              : `<label class="field">เงินสดที่นับได้ในลิ้นชัก (บาท) <input id="dr-count" inputmode="decimal" placeholder="0.00"></label>
                 <div id="dr-diff" class="muted" style="margin:8px 0;font-size:13.5px">เงินที่ควรมี ฿${money(d.expected_cash)}</div>
                 <label class="field">หมายเหตุ (ถ้ามี) <input id="dr-note" maxlength="200" placeholder="เช่น นำเงินฝากธนาคาร / เงินขาดเพราะ..."></label>
                 <button type="button" class="btn" id="dr-close" style="width:100%;margin-top:12px">${closed ? 'บันทึกการนับใหม่' : 'ปิดยอด'}</button>`}
          </section>
        </div>

        ${d.can_manage ? manageHtml() : ''}
        ${editing ? editHtml() : ''}`;
        wire();
    }

    function diffHtml(diff) {
        diff = Math.round(diff * 100) / 100;
        if (Math.abs(diff) < 0.005) return '<span class="pill ok">ตรงกับยอดที่ควรมี</span>';
        return diff > 0 ? `<span class="pill warn">เงินเกิน ฿${money(diff)}</span>` : `<span class="pill bad">เงินขาด ฿${money(-diff)}</span>`;
    }

    function payText(p) {
        return p.length ? p.map((x) => `${METHODS[x.method] || x.method} ${money(x.amount)}`).join(' + ') : '<span class="muted">ไม่ทราบ (นำเข้า)</span>';
    }

    function manageHtml() {
        const d = data;
        return `
        <section class="card">
          <div class="card-head"><h2>รายการขายของวัน (${count(d.sales.length)}) · แก้วิธีชำระเงิน</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>เวลา</th><th>พนักงาน</th><th>วิธีชำระ</th><th class="num">ยอดขาย</th><th></th></tr></thead>
            <tbody>${d.sales.map((s) => `<tr>
              <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(s.doc_no)}</td>
              <td class="muted">${escapeHtml(fmtDateTime(s.sale_datetime))}${Number(s.after_hours) ? ' <span class="pill muted">นอกเวลา</span>' : ''}</td>
              <td>${escapeHtml(s.cashier_name || '-')}</td>
              <td>${payText(s.payments)}</td>
              <td class="num">${money(s.total_value)}</td>
              <td style="text-align:right">${s.payments.length ? `<button type="button" class="btn secondary sm" data-edit-pay="${s.id}">แก้วิธีชำระ</button>` : ''}</td>
            </tr>`).join('') || '<tr><td colspan="6" class="empty">ยังไม่มีรายการขาย</td></tr>'}</tbody>
          </table></div>
        </section>

        <section class="card">
          <div class="card-head"><h2>บันทึกการทำงานเรื่องลิ้นชักของวัน</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>เวลา</th><th>ผู้ทำรายการ</th><th>รายการ</th><th class="num">จำนวนเงิน</th><th>รายละเอียด</th></tr></thead>
            <tbody>${d.events.map((e) => {
                const [label, tone] = EVENT_LABEL[e.event] || [e.event, 'muted'];
                return `<tr><td class="muted">${escapeHtml(fmtDateTime(e.created_at))}</td><td>${escapeHtml(e.user_name || '-')}</td>
                  <td><span class="pill ${tone}">${escapeHtml(label)}</span></td>
                  <td class="num">${e.amount !== null ? money(e.amount) : ''}</td>
                  <td style="font-size:12.5px">${escapeHtml(e.detail || '')}${e.subject_name ? `<div class="muted">พนักงานที่ขาย: ${escapeHtml(e.subject_name)}</div>` : ''}</td></tr>`;
            }).join('') || '<tr><td colspan="5" class="empty">ยังไม่มีบันทึก</td></tr>'}</tbody>
          </table></div>
        </section>

        ${behavior ? `
        <section class="card">
          <div class="card-head"><h2>สรุปพฤติกรรมพนักงาน ${count(behavior.days)} วันล่าสุด</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>พนักงาน</th><th class="num" title="ระบุเงินในลิ้นชักก่อนเริ่มขาย">ระบุก่อนขาย</th><th class="num" title="ระบุหลังจากขายไปแล้ว">ระบุหลังเริ่มขาย</th>
              <th class="num">ข้าม (ครั้ง)</th><th class="num">ข้าม (วัน)</th><th class="num">ปิดยอด</th><th class="num" title="บิลที่พนักงานคนนี้ขาย แล้วต้องแก้วิธีชำระภายหลัง">บิลที่ถูกแก้วิธีชำระ</th></tr></thead>
            <tbody>${behavior.data.map((u) => `<tr><td>${escapeHtml(u.full_name)}</td>
              <td class="num">${count(u.opens)}</td>
              <td class="num" style="${u.late_opens ? 'color:var(--warn);font-weight:600' : ''}">${count(u.late_opens)}</td>
              <td class="num" style="${u.skips ? 'color:var(--warn);font-weight:600' : ''}">${count(u.skips)}</td>
              <td class="num">${count(u.skip_days)}</td>
              <td class="num">${count(u.closes)}</td>
              <td class="num" style="${u.corrected ? 'color:var(--warn);font-weight:600' : ''}">${count(u.corrected)}</td></tr>`).join('')
              || '<tr><td colspan="7" class="empty">ยังไม่มีบันทึก</td></tr>'}</tbody>
          </table></div>
        </section>` : ''}`;
    }

    function editHtml() {
        const e = editing;
        const sum = e.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0);
        const total = Number(e.sale.total_value);
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:520px">
            <div class="modal-head"><h2>แก้วิธีชำระเงิน ${escapeHtml(e.sale.doc_no)}</h2>
              <button type="button" class="modal-close" data-ed="cancel" aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div class="muted" style="margin-bottom:10px">ยอดขาย ฿${money(total)} · พนักงาน ${escapeHtml(e.sale.cashier_name || '-')} · เดิม: ${payText(e.sale.payments)}</div>
              <table><thead><tr><th>วิธีชำระ</th><th class="num">จำนวนเงิน</th><th></th></tr></thead><tbody>
                ${e.lines.map((l, i) => `<tr>
                  <td><select data-ed-method="${i}">${Object.entries(METHODS).map(([k, v]) => `<option value="${k}" ${k === l.method ? 'selected' : ''}>${v}</option>`).join('')}</select></td>
                  <td class="num"><input data-ed-amount="${i}" inputmode="decimal" value="${escapeHtml(String(l.amount))}" style="width:110px;text-align:right"></td>
                  <td>${e.lines.length > 1 ? `<button type="button" class="btn secondary sm" data-ed-del="${i}">✕</button>` : ''}</td></tr>`).join('')}
              </tbody></table>
              <button type="button" class="btn secondary sm" data-ed="add" style="margin-top:8px">+ เพิ่มวิธีชำระ (ชำระแบบผสม)</button>
              <div id="ed-sum" style="margin-top:10px;font-size:13.5px">${sumHtml(sum, total)}</div>
              ${e.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(e.error)}</div>` : ''}
              <div class="muted" style="font-size:12px;margin-top:8px">การแก้ไขจะบันทึกในประวัติ และนับเป็นบิลที่ถูกแก้วิธีชำระของพนักงานที่ขาย</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn secondary" data-ed="cancel">ยกเลิก</button>
              <button type="button" class="btn" data-ed="save" ${e.busy ? 'disabled' : ''}>${e.busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div>
          </div>
        </div>`;
    }

    function sumHtml(sum, total) {
        const diff = Math.round((sum - total) * 100) / 100;
        return `รวม ฿${money(sum)} ${Math.abs(diff) < 0.005 ? '<span class="pill ok">ตรงกับยอดขาย</span>' : `<span class="pill bad">${diff > 0 ? 'เกิน' : 'ขาด'} ฿${money(Math.abs(diff))}</span>`}`;
    }

    function wire() {
        container.querySelector('#dr-date')?.addEventListener('change', (e) => {
            if (!e.target.value) return;
            date = e.target.value;
            load();
        });
        container.querySelector('#dr-open-save')?.addEventListener('click', async () => {
            const raw = container.querySelector('#dr-open').value.trim().replace(/,/g, '');
            const v = Number(raw);
            if (raw === '' || !(v >= 0)) { alertDialog('กรุณาใส่จำนวนเงินในลิ้นชัก (ถ้าไม่มีเงินให้ใส่ 0)', { tone: 'error' }); return; }
            try {
                await api.post('/drawer/open', { amount: v, note: container.querySelector('#dr-open-note').value });
                await load();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        });
        const countEl = container.querySelector('#dr-count');
        countEl?.addEventListener('input', () => {
            const v = Number(countEl.value.replace(/,/g, ''));
            container.querySelector('#dr-diff').innerHTML = countEl.value.trim() === '' || !Number.isFinite(v)
                ? `เงินที่ควรมี ฿${money(data.expected_cash)}`
                : `เงินที่ควรมี ฿${money(data.expected_cash)} · ${diffHtml(v - data.expected_cash)}`;
        });
        container.querySelector('#dr-close')?.addEventListener('click', async () => {
            const raw = countEl.value.trim().replace(/,/g, '');
            const v = Number(raw);
            if (raw === '' || !(v >= 0)) { alertDialog('กรุณาใส่จำนวนเงินที่นับได้', { tone: 'error' }); return; }
            const diff = Math.round((v - data.expected_cash) * 100) / 100;
            const ok = await confirmDialog(
                `นับได้ ฿${money(v)} · เงินที่ควรมี ฿${money(data.expected_cash)} · ${Math.abs(diff) < 0.005 ? 'ตรงกัน' : `${diff > 0 ? 'เกิน' : 'ขาด'} ฿${money(Math.abs(diff))}`}`,
                { title: 'ยืนยันปิดยอด?', okText: 'ปิดยอด' }
            );
            if (!ok) return;
            try {
                await api.post('/drawer/close', { amount: v, note: container.querySelector('#dr-note').value, date: data.date });
                await load();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        });
        container.querySelector('#dr-adjust')?.addEventListener('click', async () => {
            const amount = await promptDialog('เงินตั้งต้นที่ถูกต้อง (บาท)', { title: 'แก้เงินตั้งต้นในลิ้นชัก', value: String(Number(data.drawer.opening_cash)), required: true });
            if (amount === null) return;
            const v = Number(String(amount).replace(/,/g, ''));
            if (!(v >= 0)) { alertDialog('จำนวนเงินไม่ถูกต้อง', { tone: 'error' }); return; }
            const note = await promptDialog('เหตุผลที่แก้ไข', { title: 'แก้เงินตั้งต้นในลิ้นชัก', required: true, multiline: true });
            if (note === null) return;
            try {
                await api.put(`/drawer/${data.date}/opening`, { amount: v, note });
                await load();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        });
        container.querySelectorAll('[data-edit-pay]').forEach((b) => b.addEventListener('click', () => {
            const sale = data.sales.find((s) => String(s.id) === b.dataset.editPay);
            editing = { sale, lines: sale.payments.map((p) => ({ method: p.method, amount: Number(p.amount) })), error: null, busy: false };
            draw();
        }));
        if (editing) wireEdit();
    }

    function wireEdit() {
        const e = editing;
        const total = Number(e.sale.total_value);
        const refreshSum = () => {
            container.querySelector('#ed-sum').innerHTML = sumHtml(e.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0), total);
        };
        container.querySelectorAll('[data-ed-method]').forEach((s) => s.addEventListener('change', () => { e.lines[Number(s.dataset.edMethod)].method = s.value; }));
        container.querySelectorAll('[data-ed-amount]').forEach((inp) => inp.addEventListener('input', () => {
            e.lines[Number(inp.dataset.edAmount)].amount = inp.value.replace(/,/g, '');
            refreshSum();
        }));
        container.querySelectorAll('[data-ed-del]').forEach((b) => b.addEventListener('click', () => {
            e.lines.splice(Number(b.dataset.edDel), 1);
            draw();
        }));
        container.querySelectorAll('[data-ed]').forEach((b) => b.addEventListener('click', async () => {
            const act = b.dataset.ed;
            if (act === 'cancel') { editing = null; draw(); return; }
            if (act === 'add') {
                const rest = Math.max(0, Math.round((total - e.lines.reduce((s, l) => s + (Number(l.amount) || 0), 0)) * 100) / 100);
                const used = new Set(e.lines.map((l) => l.method));
                e.lines.push({ method: Object.keys(METHODS).find((k) => !used.has(k)) || 'cash', amount: rest });
                draw();
                return;
            }
            e.busy = true; e.error = null; draw();
            try {
                await api.put(`/sales/${e.sale.id}/payments`, { payments: e.lines.map((l) => ({ method: l.method, amount: Number(l.amount) || 0 })) });
                editing = null;
                await load();
            } catch (err) {
                e.busy = false;
                e.error = err.message;
                draw();
            }
        }));
    }

    await load();
}
