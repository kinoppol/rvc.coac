import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog } from '../dialog.js';

/**
 * สรุปบัญชีรายวัน: the person on duty for a weekday checks that day's figures, records the
 * cash handed to the college and its receipt; admin / manager set the roster and confirm.
 */
export async function render(container, { user } = {}) {
    let data = null;
    let error = null;
    let modal = null; // { el, date, figures, closing, on_duty, can_submit, form, error, busy }
    let rosterEdit = null; // weekday => Set of user ids, while editing

    async function load() {
        try {
            data = (await api.get('/closing')).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    const names = (list) => list.map((p) => escapeHtml(p.full_name)).join(', ');

    function statusPill(c) {
        if (!c) return '<span class="pill warn">ยังไม่สรุป</span>';
        return c.verified_at ? '<span class="pill ok">ยืนยันแล้ว</span>' : '<span class="pill">สรุปแล้ว รอยืนยัน</span>';
    }

    function draw() {
        if (!data) {
            container.innerHTML = error ? `<div class="error-box">${escapeHtml(error)}</div>` : '';
            return;
        }
        const W = data.weekdays;
        const mine = data.my_duties.map((d) => `วัน${W[d]}`).join(', ');
        container.innerHTML = `
        <div class="hint" style="margin:0 0 16px"><div class="dot">i</div><div>
          ${mine ? `เวรสรุปบัญชีของคุณ: <b>${escapeHtml(mine)}</b> · ` : (data.can_manage ? '' : '<b>คุณยังไม่มีเวรสรุปบัญชี</b> (ให้ผู้ดูแลระบบกำหนดเวร) · ')}
          ในวันที่เป็นเวร ตรวจยอดขายของวัน แล้วนำเงินสดส่งวิทยาลัย และบันทึกจำนวนเงินที่ส่งพร้อมเลขที่ใบเสร็จ</div></div>
        ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
        <section class="card">
          <div class="card-head"><h2>สรุปบัญชีรายวัน (14 วันล่าสุด)</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>วันที่</th><th>เวร</th><th class="num">ยอดขายรวม</th><th class="num">เงินสด</th><th class="num">เงินโอน / QR</th><th class="num">บัตร</th><th class="num">นำเข้า POS</th><th class="num">ควรส่ง</th><th class="num">ส่งแล้ว</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>${data.days.map((d) => {
                const c = d.closing;
                const myDay = data.my_duties.includes(d.weekday);
                const short = c && Math.abs(Number(c.cash_sent) - Number(c.expected_send)) >= 0.005;
                return `
              <tr${myDay && !c ? ' style="background:var(--surface2)"' : ''}>
                <td style="white-space:nowrap">${W[d.weekday]}<div class="muted" style="font-size:12px">${fmtDate(d.date)}</div></td>
                <td style="font-size:12.5px">${d.on_duty.length ? names(d.on_duty) : '<span class="muted">ไม่มีเวร</span>'}</td>
                <td class="num">${money(d.total_sales)}</td>
                <td class="num">${money(d.cash_sales)}</td>
                <td class="num">${money(d.transfer_sales)}</td>
                <td class="num">${money(d.card_sales)}</td>
                <td class="num">${money(d.unknown_sales)}</td>
                <td class="num">${money(c ? c.expected_send : d.expected_send)}</td>
                <td class="num">${c ? `<b${short ? ' style="color:var(--bad)"' : ''}>${money(c.cash_sent)}</b>` : '-'}</td>
                <td>${statusPill(c)}${c ? `<div class="muted" style="font-size:11.5px">${escapeHtml(c.closer_name || '')}</div>` : ''}</td>
                <td style="text-align:right"><button type="button" class="btn sm ${myDay && !c ? '' : 'secondary'}" data-day="${d.date}">${c ? 'ดู' : (myDay || data.can_manage ? 'สรุปบัญชี' : 'ดู')}</button></td>
              </tr>`;
            }).join('')}
            </tbody>
          </table></div>
        </section>
        ${data.can_manage ? rosterHtml() : ''}`;
        container.querySelectorAll('[data-day]').forEach((b) => b.addEventListener('click', () => openDay(b.dataset.day)));
        if (data.can_manage) wireRoster();
    }

    // ---------------- Duty roster (admin / manager) ----------------

    function rosterHtml() {
        const W = data.weekdays;
        if (!rosterEdit) {
            return `
            <section class="card" style="margin-top:16px">
              <div class="card-head"><h2>เวรผู้สรุปบัญชีตามวันในสัปดาห์</h2><button type="button" class="btn sm secondary" id="roster-edit">กำหนดเวร</button></div>
              <div class="table-wrap"><table><tbody>
                ${Object.entries(W).map(([k, label]) => `<tr><td style="width:120px;font-weight:600">วัน${label}</td><td>${data.roster[k].length ? names(data.roster[k]) : '<span class="muted">ยังไม่มีเวร</span>'}</td></tr>`).join('')}
              </tbody></table></div>
            </section>`;
        }
        const ROLE = { closer: 'ผู้สรุปบัญชี', manager: 'ผู้จัดการ', admin: 'ผู้ดูแลระบบ' };
        return `
        <section class="card" style="margin-top:16px">
          <div class="card-head"><h2>กำหนดเวรผู้สรุปบัญชี</h2></div>
          ${data.candidates.length ? '' : '<div class="card-pad muted">ยังไม่มีผู้ใช้บทบาท ผู้สรุปบัญชีรายวัน — เพิ่มได้ที่ ตั้งค่าระบบ › ผู้ใช้งาน</div>'}
          <div class="table-wrap"><table><tbody>
            ${Object.entries(W).map(([k, label]) => `<tr><td style="width:120px;font-weight:600;vertical-align:top">วัน${label}</td><td>
              <div style="display:flex;flex-wrap:wrap;gap:6px 18px">${data.candidates.map((p) => `
                <label style="display:flex;align-items:center;gap:6px;font-size:13.5px"><input type="checkbox" data-duty="${k}" value="${p.id}" ${rosterEdit[k].has(Number(p.id)) ? 'checked' : ''}>
                  ${escapeHtml(p.full_name)} <span class="muted" style="font-size:11.5px">(${ROLE[p.role] || p.role})</span></label>`).join('')}</div>
            </td></tr>`).join('')}
          </tbody></table></div>
          <div class="card-pad" style="display:flex;gap:8px;justify-content:flex-end">
            <button type="button" class="btn secondary" id="roster-cancel">ยกเลิก</button>
            <button type="button" class="btn" id="roster-save">บันทึกเวร</button>
          </div>
        </section>`;
    }

    function wireRoster() {
        container.querySelector('#roster-edit')?.addEventListener('click', () => {
            rosterEdit = Object.fromEntries(Object.keys(data.weekdays).map((k) => [k, new Set(data.roster[k].map((p) => Number(p.id)))]));
            draw();
        });
        if (!rosterEdit) return;
        container.querySelectorAll('[data-duty]').forEach((cb) => cb.addEventListener('change', () => {
            const set = rosterEdit[cb.dataset.duty];
            if (cb.checked) set.add(Number(cb.value)); else set.delete(Number(cb.value));
        }));
        container.querySelector('#roster-cancel').addEventListener('click', () => { rosterEdit = null; draw(); });
        container.querySelector('#roster-save').addEventListener('click', async () => {
            try {
                data.roster = (await api.put('/closing-roster', {
                    roster: Object.fromEntries(Object.entries(rosterEdit).map(([k, set]) => [k, [...set]])),
                })).data;
                rosterEdit = null;
                await load();
            } catch (e) {
                alertDialog(e.message, { tone: 'error' });
            }
        });
    }

    // ---------------- One day ----------------

    async function openDay(date) {
        try {
            const d = (await api.get(`/closing/${date}`)).data;
            const c = d.closing;
            modal = {
                el: document.createElement('div'), date, ...d, error: null, busy: false,
                editing: !c && d.can_submit,
                form: {
                    cash_sent: String(c ? Number(c.cash_sent) : d.figures.expected_send),
                    sent_to: c?.sent_to || 'งานการเงิน วิทยาลัย',
                    receipt_no: c?.receipt_no || '',
                    note: c?.note || '',
                },
            };
            document.body.appendChild(modal.el);
            drawDay();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    function closeDay() {
        modal?.el.remove();
        modal = null;
    }

    function drawDay() {
        const m = modal;
        const f = m.figures;
        const c = m.closing;
        const dr = f.drawer;
        const W = data.weekdays;
        const row = (label, v, opts = '') => `<div style="display:flex;justify-content:space-between;gap:12px;${opts}"><span class="muted">${label}</span><span>${v}</span></div>`;
        const diff = Number(m.form.cash_sent) - Number(f.expected_send);
        m.el.innerHTML = `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-label="สรุปบัญชีวันที่ ${fmtDate(m.date)}" style="width:820px">
            <div class="modal-head">
              <h2>สรุปบัญชี วัน${W[new Date(m.date + 'T00:00:00').getDay() || 7]} ${fmtDate(m.date)} ${statusPill(c)}</h2>
              <button type="button" class="modal-close" data-close aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              <div class="muted" style="font-size:12.5px;margin-bottom:12px">เวร: ${m.on_duty.length ? names(m.on_duty) : 'ไม่มีเวร'}</div>
              <div class="two-col" style="gap:16px">
                <section class="card card-pad" style="display:flex;flex-direction:column;gap:7px;font-size:13.5px">
                  <h3 style="margin:0 0 4px">ยอดขายของวัน${c ? ' <span class="muted" style="font-weight:400;font-size:12px">(ตอนนี้)</span>' : ''}</h3>
                  ${row('ยอดขายรวม', `<b>฿ ${money(f.total_sales)}</b> (${f.sales_count} บิล)`)}
                  ${f.methods.map((x) => row(escapeHtml(x.label), `฿ ${money(x.amount)}`)).join('')}
                  ${f.unknown_sales ? row('นำเข้าจาก POS (ไม่ทราบวิธีชำระ)', `฿ ${money(f.unknown_sales)}`) : ''}
                  ${row('จ่ายค่าแรงจากลิ้นชัก', `- ฿ ${money(f.cash_out)}`)}
                  <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                  ${dr ? `
                    ${row('เงินตั้งต้นในลิ้นชัก (คงไว้)', `฿ ${money(dr.opening_cash)}`)}
                    ${dr.counted_cash !== null
                        ? `${row('นับเงินตอนปิดยอด', `฿ ${money(dr.counted_cash)}`)}
                           ${dr.cash_diff ? row('เงินขาด / เกินตอนปิดยอด', `<span style="color:${dr.cash_diff < 0 ? 'var(--bad)' : 'inherit'}">฿ ${money(dr.cash_diff)}</span>`) : ''}
                           <div class="muted" style="font-size:11.5px">ปิดยอดโดย ${escapeHtml(dr.closed_by_name || '-')} ${dr.closed_at ? fmtDateTime(dr.closed_at) : ''}</div>`
                        : '<div class="pill warn" style="font-size:12px">ลิ้นชักยังไม่ได้ปิดยอด — ยอดที่ควรส่งคำนวณจากยอดขายเงินสด</div>'}`
                    : '<div class="pill warn" style="font-size:12px">วันนี้ไม่ได้ระบุเงินในลิ้นชัก</div>'}
                  ${row('<b>เงินสดที่ควรส่งวิทยาลัย</b>', `<b>฿ ${money(f.expected_send)}</b>`, 'font-size:15px')}
                </section>
                <section class="card card-pad" style="display:flex;flex-direction:column;gap:10px">
                  <h3 style="margin:0">ส่งเงินสดให้วิทยาลัย</h3>
                  ${m.editing ? `
                    <label class="field">จำนวนเงินสดที่ส่ง (บาท) * <input data-f="cash_sent" type="number" min="0" step="0.25" value="${escapeHtml(m.form.cash_sent)}"></label>
                    <div class="muted" id="cl-diff" style="font-size:12.5px">${diffText(diff)}</div>
                    <label class="field">ส่งให้ <input data-f="sent_to" maxlength="150" value="${escapeHtml(m.form.sent_to)}"></label>
                    <label class="field">เลขที่ใบเสร็จ / ใบรับเงิน <input data-f="receipt_no" maxlength="60" value="${escapeHtml(m.form.receipt_no)}"></label>
                    <label class="field">หมายเหตุ <span class="muted" style="font-weight:400">(จำเป็นถ้ายอดไม่ตรง)</span><textarea data-f="note" rows="2" maxlength="500">${escapeHtml(m.form.note)}</textarea></label>
                    ${m.error ? `<div class="error-box">${escapeHtml(m.error)}</div>` : ''}
                    <div style="display:flex;gap:8px;justify-content:flex-end">
                      ${c ? '<button type="button" class="btn secondary" id="cl-cancel">ยกเลิก</button>' : ''}
                      <button type="button" class="btn" id="cl-save" ${m.busy ? 'disabled' : ''}>${m.busy ? 'กำลังบันทึก...' : 'บันทึกสรุปบัญชี'}</button>
                    </div>`
                  : c ? `
                    <div style="display:flex;flex-direction:column;gap:7px;font-size:13.5px">
                      ${row('เงินสดที่ส่ง', `<b>฿ ${money(c.cash_sent)}</b>`)}
                      ${row('ยอดที่ควรส่ง (ตอนสรุป)', `฿ ${money(c.expected_send)}`)}
                      ${Math.abs(Number(c.cash_sent) - Number(c.expected_send)) >= 0.005 ? row('ส่วนต่าง', `<span style="color:var(--bad)">฿ ${money(Number(c.cash_sent) - Number(c.expected_send))}</span>`) : ''}
                      ${row('ส่งให้', escapeHtml(c.sent_to || '-'))}
                      ${row('เลขที่ใบเสร็จ', escapeHtml(c.receipt_no || '-'))}
                      ${c.note ? row('หมายเหตุ', escapeHtml(c.note)) : ''}
                      <div class="muted" style="font-size:12px">สรุปโดย ${escapeHtml(c.closer_name || '-')} · ${fmtDateTime(c.updated_at)}</div>
                      ${c.verified_at ? `<div class="pill ok" style="font-size:12px">ยืนยันโดย ${escapeHtml(c.verified_by_name || '-')} · ${fmtDateTime(c.verified_at)}</div>` : ''}
                      ${Math.abs(Number(c.total_sales) - Number(f.total_sales)) >= 0.005 ? `<div class="pill warn" style="font-size:12px">ยอดขายเปลี่ยนไปหลังสรุป (ตอนสรุป ฿${money(c.total_sales)}) — ควรสรุปใหม่</div>` : ''}
                    </div>
                    ${m.error ? `<div class="error-box">${escapeHtml(m.error)}</div>` : ''}
                    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
                      ${m.can_submit && (!c.verified_at || data.can_manage) ? '<button type="button" class="btn secondary" id="cl-edit">แก้ไข / สรุปใหม่</button>' : ''}
                      ${data.can_manage && !c.verified_at ? `<button type="button" class="btn" id="cl-verify" ${m.busy ? 'disabled' : ''}>ยืนยันว่าวิทยาลัยได้รับเงินแล้ว</button>` : ''}
                    </div>`
                  : `<div class="muted">ยังไม่มีการสรุปบัญชีวันนี้${m.can_submit ? '' : ' — สรุปได้เฉพาะผู้ที่มีเวรวันนี้'}</div>`}
                </section>
              </div>
            </div>
            <div class="modal-foot"><span></span><button type="button" class="btn secondary" data-close>ปิด</button></div>
          </div>
        </div>`;
        m.el.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeDay));
        m.el.querySelectorAll('[data-f]').forEach((inp) => inp.addEventListener('input', () => {
            m.form[inp.dataset.f] = inp.value;
            if (inp.dataset.f === 'cash_sent') m.el.querySelector('#cl-diff').textContent = diffText(Number(inp.value) - Number(f.expected_send));
        }));
        m.el.querySelector('#cl-save')?.addEventListener('click', save);
        m.el.querySelector('#cl-cancel')?.addEventListener('click', () => { m.editing = false; m.error = null; drawDay(); });
        m.el.querySelector('#cl-edit')?.addEventListener('click', () => { m.editing = true; m.form.cash_sent = String(Number(m.figures.expected_send)); drawDay(); });
        m.el.querySelector('#cl-verify')?.addEventListener('click', verify);
    }

    function diffText(diff) {
        if (!Number.isFinite(diff) || Math.abs(diff) < 0.005) return 'ตรงกับยอดที่ควรส่ง';
        return diff < 0 ? `ส่งขาด ฿${money(-diff)} — กรุณาระบุหมายเหตุ` : `ส่งเกิน ฿${money(diff)} — กรุณาระบุหมายเหตุ`;
    }

    async function save() {
        const m = modal;
        if (m.form.cash_sent === '' || !(Number(m.form.cash_sent) >= 0)) {
            m.error = 'กรุณากรอกจำนวนเงินสดที่ส่ง';
            drawDay();
            return;
        }
        m.busy = true;
        drawDay();
        try {
            m.closing = (await api.post(`/closing/${m.date}`, { ...m.form, cash_sent: Number(m.form.cash_sent) })).data;
            m.editing = false;
            m.error = null;
            load();
        } catch (e) {
            m.error = e.message;
        }
        m.busy = false;
        drawDay();
    }

    async function verify() {
        const m = modal;
        m.busy = true;
        drawDay();
        try {
            m.closing = (await api.post(`/closing/${m.date}/verify`, {})).data;
            m.error = null;
            load();
        } catch (e) {
            m.error = e.message;
        }
        m.busy = false;
        drawDay();
    }

    window.addEventListener('hashchange', closeDay, { once: true });

    await load();
}
