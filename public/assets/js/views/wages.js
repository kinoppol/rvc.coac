import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, todayISO } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';
import { printWageSlip } from '../print-slip.js';

const ROLE_LABEL = { admin: 'ผู้ดูแลระบบ', manager: 'ผู้จัดการร้าน', advisor: 'ครูที่ปรึกษา', cashier: 'พนักงานขาย' };
const UNIT = { day: 'วัน', hour: 'ชั่วโมง' };

/** ISO date of the first / last day of the month `offset` months from now. */
function monthRange(offset) {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth() + offset, 1);
    const last = offset === 0 ? now : new Date(now.getFullYear(), now.getMonth() + offset + 1, 0);
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return [iso(first), iso(last)];
}

/**
 * ค่าแรงพนักงาน: pay staff wages (a business expense, taken off the store's profit),
 * optionally in cash out of today's POS drawer; list, total per person, print a voucher, cancel.
 */
export async function render(container) {
    let [from, to] = monthRange(0);
    let employee = '';
    let data = null;
    let busy = false;
    let error = null;
    const form = { user_id: '', pay_date: todayISO(), period_from: '', period_to: '', wage_type: 'day', units: '', rate: '', amount: '', method: 'cash', from_drawer: false, note: '' };
    let amountTouched = false;

    async function load() {
        const q = new URLSearchParams({ from, to });
        if (employee) q.set('user_id', employee);
        data = (await api.get(`/wages?${q}`)).data;
        draw();
    }

    const autoAmount = () => {
        const u = Number(form.units);
        const r = Number(form.rate);
        return form.wage_type !== 'fixed' && u > 0 && r > 0 ? String(Math.round(u * r * 100) / 100) : '';
    };
    const canDrawer = () => form.method === 'cash' && form.pay_date === todayISO();

    function draw() {
        const d = data;
        const fixed = form.wage_type === 'fixed';
        container.innerHTML = `
        <div class="two-col">
          <section class="card card-pad">
            <h2 style="margin-bottom:12px">จ่ายค่าแรง</h2>
            <div class="form-grid">
              <label class="field" style="grid-column:span 2">พนักงาน *
                <select id="w-user"><option value="">— เลือกพนักงาน —</option>
                  ${d.staff.map((s) => `<option value="${s.id}" ${String(s.id) === String(form.user_id) ? 'selected' : ''}>${escapeHtml(s.full_name)} (${escapeHtml(ROLE_LABEL[s.role] || s.role)})</option>`).join('')}
                </select></label>
              <label class="field">วันที่จ่าย * <input type="date" id="w-date" value="${escapeHtml(form.pay_date)}" max="${todayISO()}"></label>
              <label class="field">ทำงานตั้งแต่ <input type="date" id="w-from" value="${escapeHtml(form.period_from)}"></label>
              <label class="field">ถึง <input type="date" id="w-to" value="${escapeHtml(form.period_to)}"></label>
              <label class="field">ประเภทค่าแรง
                <select id="w-type">${Object.entries(d.types).map(([k, v]) => `<option value="${k}" ${k === form.wage_type ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
              ${fixed ? '' : `
              <label class="field">จำนวน${UNIT[form.wage_type]} <input id="w-units" type="number" min="0" step="0.5" value="${escapeHtml(form.units)}"></label>
              <label class="field">อัตราต่อ${UNIT[form.wage_type]} (บาท) <input id="w-rate" type="number" min="0" step="1" value="${escapeHtml(form.rate)}"></label>`}
              <label class="field">จำนวนเงิน (บาท) * <input id="w-amount" type="number" min="0" step="0.01" value="${escapeHtml(form.amount)}"></label>
              <label class="field">วิธีจ่าย
                <select id="w-method">${Object.entries(d.methods).map(([k, v]) => `<option value="${k}" ${k === form.method ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
              <label class="field" style="grid-column:1/-1">หมายเหตุ <input id="w-note" maxlength="200" value="${escapeHtml(form.note)}"></label>
            </div>
            ${canDrawer() ? `
              <label style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;font-size:13.5px;${d.drawer_open ? 'cursor:pointer' : 'opacity:.6'}">
                <input type="checkbox" id="w-drawer" ${form.from_drawer && d.drawer_open ? 'checked' : ''} ${d.drawer_open ? '' : 'disabled'}>
                <span>จ่ายจากเงินในลิ้นชักวันนี้
                  <span class="muted" style="display:block;font-size:12px">${d.drawer_open ? 'ยอดเงินที่ควรมีในลิ้นชักตอนปิดยอดจะลดลงตามจำนวนนี้' : 'ใช้ไม่ได้: วันนี้ยังไม่ได้ระบุเงินในลิ้นชัก หรือปิดยอดแล้ว'}</span></span>
              </label>` : ''}
            ${error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(error)}</div>` : ''}
            <div class="muted" style="font-size:12.5px;margin-top:10px">ค่าแรงบันทึกเป็นต้นทุนของกิจการ หักจากกำไรของร้าน</div>
            <button type="button" class="btn" id="w-save" style="width:100%;margin-top:12px" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึกการจ่ายค่าแรง'}</button>
          </section>

          <section class="card card-pad">
            <h2 style="margin-bottom:4px">สรุปค่าแรง</h2>
            <div class="muted" style="font-size:12.5px;margin-bottom:10px">${escapeHtml(fmtDate(d.from))} – ${escapeHtml(fmtDate(d.to))}</div>
            <div style="font-size:26px;font-weight:700;margin-bottom:10px">฿ ${money(d.total)}</div>
            <div class="table-wrap"><table>
              <thead><tr><th>พนักงาน</th><th class="num">ครั้ง</th><th class="num">รวม (บาท)</th></tr></thead>
              <tbody>${d.by_employee.map((e) => `<tr><td>${escapeHtml(e.full_name)}</td><td class="num">${count(e.payments)}</td><td class="num">${money(e.amount)}</td></tr>`).join('')
                || '<tr><td colspan="3" class="empty">ยังไม่มีการจ่ายค่าแรงในช่วงนี้</td></tr>'}</tbody>
            </table></div>
          </section>
        </div>

        <section class="card card-pad">
          <div class="actions-row">
            <button type="button" class="tab-btn" data-month="0">เดือนนี้</button>
            <button type="button" class="tab-btn" data-month="-1">เดือนก่อน</button>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(from)}"></label>
            <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="f-to" value="${escapeHtml(to)}"></label>
            <select id="f-user"><option value="">พนักงานทุกคน</option>
              ${d.staff.map((s) => `<option value="${s.id}" ${String(s.id) === String(employee) ? 'selected' : ''}>${escapeHtml(s.full_name)}</option>`).join('')}</select>
          </div>
        </section>

        <section class="card">
          <div class="card-head"><h2>รายการจ่ายค่าแรง (${count(d.rows.length)})</h2></div>
          <div class="table-wrap" style="max-height:520px"><table>
            <thead><tr><th>เลขที่</th><th>วันที่จ่าย</th><th>พนักงาน</th><th>ช่วงทำงาน</th><th>รายละเอียด</th><th class="num">จำนวนเงิน</th><th>วิธีจ่าย</th><th></th></tr></thead>
            <tbody>${d.rows.map((w) => {
                const cancelled = w.cancelled_at !== null;
                return `<tr style="${cancelled ? 'opacity:.55' : ''}">
                  <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(w.doc_no)}</td>
                  <td>${escapeHtml(fmtDate(w.pay_date))}</td>
                  <td>${escapeHtml(w.employee_name || '-')}</td>
                  <td class="muted" style="font-size:12.5px">${w.period_from ? `${escapeHtml(fmtDate(w.period_from))}${w.period_to && w.period_to !== w.period_from ? ` – ${escapeHtml(fmtDate(w.period_to))}` : ''}` : '-'}</td>
                  <td style="font-size:12.5px">${escapeHtml(d.types[w.wage_type] || w.wage_type)}${w.units !== null && w.rate !== null ? ` · ${Number(w.units)} ${UNIT[w.wage_type] || ''} × ฿${money(w.rate)}` : ''}
                    ${w.note ? `<div class="muted">${escapeHtml(w.note)}</div>` : ''}
                    ${cancelled ? `<div><span class="pill bad">ยกเลิกแล้ว</span> <span class="muted">${escapeHtml(w.cancel_reason || '')}</span></div>` : ''}</td>
                  <td class="num" style="${cancelled ? 'text-decoration:line-through' : 'font-weight:600'}">${money(w.amount)}</td>
                  <td style="font-size:12.5px">${escapeHtml(d.methods[w.method] || w.method)}${w.drawer_date ? ' <span class="pill muted">จากลิ้นชัก</span>' : ''}</td>
                  <td style="white-space:nowrap;text-align:right">
                    <button type="button" class="btn secondary sm" data-print="${w.id}">พิมพ์</button>
                    ${cancelled ? '' : `<button type="button" class="btn secondary sm" data-cancel="${w.id}">ยกเลิก</button>`}</td>
                </tr>`;
            }).join('') || '<tr><td colspan="8" class="empty">ยังไม่มีรายการ</td></tr>'}</tbody>
          </table></div>
        </section>`;
        wire();
    }

    function wire() {
        const $ = (s) => container.querySelector(s);
        const bind = (sel, key, redraw = false) => $(sel)?.addEventListener(redraw ? 'change' : 'input', (e) => {
            form[key] = e.target.value;
            if (redraw) draw();
        });
        bind('#w-user', 'user_id');
        bind('#w-date', 'pay_date', true);
        bind('#w-from', 'period_from');
        bind('#w-to', 'period_to');
        bind('#w-method', 'method', true);
        bind('#w-note', 'note');
        $('#w-type').addEventListener('change', (e) => {
            form.wage_type = e.target.value;
            if (!amountTouched) form.amount = autoAmount();
            draw();
        });
        ['#w-units', '#w-rate'].forEach((sel) => $(sel)?.addEventListener('input', (e) => {
            form[sel === '#w-units' ? 'units' : 'rate'] = e.target.value;
            if (!amountTouched) $('#w-amount').value = form.amount = autoAmount();
        }));
        $('#w-amount').addEventListener('input', (e) => {
            form.amount = e.target.value;
            amountTouched = e.target.value !== '';
        });
        $('#w-drawer')?.addEventListener('change', (e) => { form.from_drawer = e.target.checked; });
        $('#w-save').addEventListener('click', save);

        container.querySelectorAll('[data-month]').forEach((b) => b.addEventListener('click', () => {
            [from, to] = monthRange(Number(b.dataset.month));
            load();
        }));
        $('#f-from').addEventListener('change', (e) => { if (e.target.value) { from = e.target.value; load(); } });
        $('#f-to').addEventListener('change', (e) => { if (e.target.value) { to = e.target.value; load(); } });
        $('#f-user').addEventListener('change', (e) => { employee = e.target.value; load(); });
        container.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => {
            printWageSlip(data.rows.find((w) => String(w.id) === b.dataset.print), data.types, data.methods);
        }));
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancel(b.dataset.cancel)));
    }

    async function save() {
        error = null;
        const amount = Number(form.amount);
        const staff = data.staff.find((s) => String(s.id) === String(form.user_id));
        if (!staff) { error = 'กรุณาเลือกพนักงาน'; draw(); return; }
        if (!(amount > 0)) { error = 'กรุณาระบุจำนวนเงินค่าแรง'; draw(); return; }
        const fromDrawer = canDrawer() && form.from_drawer && data.drawer_open;
        const ok = await confirmDialog(
            `จ่ายค่าแรง ${staff.full_name} ฿${money(amount)} (${data.methods[form.method]}${fromDrawer ? ' จากเงินในลิ้นชัก' : ''})`,
            { title: 'ยืนยันการจ่ายค่าแรง?', okText: 'บันทึก' }
        );
        if (!ok) return;
        busy = true;
        draw();
        try {
            const w = (await api.post('/wages', { ...form, amount, from_drawer: fromDrawer })).data;
            Object.assign(form, { units: '', amount: '', period_from: '', period_to: '', note: '', from_drawer: false });
            amountTouched = false;
            busy = false;
            await load();
            if (await confirmDialog(`บันทึก ${w.doc_no} แล้ว ต้องการพิมพ์ใบสำคัญจ่ายให้พนักงานลงชื่อรับเงินหรือไม่?`, { title: 'บันทึกการจ่ายค่าแรงแล้ว', okText: 'พิมพ์', cancelText: 'ไม่พิมพ์' })) {
                printWageSlip(w, data.types, data.methods);
            }
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    async function cancel(id) {
        const w = data.rows.find((x) => String(x.id) === String(id));
        const reason = await promptDialog(`${w.doc_no} · ${w.employee_name} ฿${money(w.amount)}`, { title: 'ยกเลิกการจ่ายค่าแรง', okText: 'ยกเลิกรายการ', placeholder: 'เหตุผลที่ยกเลิก', required: true, multiline: true, danger: true });
        if (reason === null) return;
        try {
            await api.post(`/wages/${id}/cancel`, { reason });
            await load();
        } catch (e) { alertDialog(e.message, { tone: 'error' }); }
    }

    await load();
}
