import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';
import { printClosingReport, printWageSlip } from '../print-slip.js';

const SKIP_LABEL = { drawer: 'ปิดยอดลิ้นชัก', wages: 'จ่ายค่าแรง', payouts: 'จ่ายเงินผู้ฝากขาย', purchases: 'ซื้อสินค้าเข้าร้าน' };
const PAY_METHOD = { cash: 'เงินสด', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };
const UNIT = { day: 'วัน', hour: 'ชั่วโมง' };

/**
 * สรุปบัญชีรายวัน: the person on duty for a weekday checks that day's figures, records the
 * cash handed to the college and its receipt; admin / manager set the roster and confirm.
 */
export async function render(container, { user, permissions = [] } = {}) {
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

    // ---------------- One day: the end-of-day report ----------------

    const can = (perm) => permissions.includes(perm);
    // Where to go to do a step that is still missing (only if this user may open that page).
    const LINKS = {
        drawer: ['drawer', 'sales.create', 'ไปปิดยอดลิ้นชัก'],
        wages: ['wages', 'wages.manage', 'ไปจ่ายค่าแรง'],
        payouts: ['withdraw', 'withdraw.create', 'ไปจ่ายเงินผู้ฝากขาย'],
        purchases: ['purchase', 'purchase.create', 'ไปบันทึกซื้อสินค้า'],
    };

    async function openDay(date) {
        try {
            const d = (await api.get(`/closing/${date}`)).data;
            modal = { el: document.createElement('div'), date, ...d, error: null, busy: false, editing: false, form: null };
            if (!d.closing && d.can_submit) startEdit();
            document.body.appendChild(modal.el);
            drawDay();
            if (d.can_submit) loadPayOptions(modal);
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    async function reloadDay() {
        const d = (await api.get(`/closing/${modal.date}`)).data;
        Object.assign(modal, d);
    }

    function startEdit() {
        const m = modal;
        const c = m.closing;
        m.editing = true;
        m.form = {
            cash_sent: String(m.figures.expected_send),
            float_kept: String(m.figures.float_kept),
            sent_to: c?.sent_to || 'งานการเงิน วิทยาลัย',
            receipt_no: c?.receipt_no || '',
            note: c?.note || '',
            actual: Object.fromEntries(m.accounts.map((a) => [a.method, c?.actual_accounts?.[a.method] != null ? String(c.actual_accounts[a.method]) : ''])),
        };
    }

    function closeDay() {
        modal?.el.remove();
        modal = null;
    }

    // Change kept in the drawer for the next day: as typed while editing, else as the server has it.
    const floatOf = (m) => (m.editing && m.form.float_kept !== '' ? Number(m.form.float_kept) || 0 : Number(m.figures.float_kept));
    // Cash to hand over: what is left in the drawer after paying out, less the change kept.
    const expectedSend = (m) => {
        const dr = m.figures.drawer;
        return dr ? Math.max(0, Math.round((dr.after_pay - floatOf(m)) * 100) / 100) : Number(m.figures.expected_send);
    };
    const isDiff = (a, b) => Math.abs(Number(a) - Number(b)) >= 0.005;
    const signed = (v) => `${v > 0 ? '+' : v < 0 ? '-' : ''}฿ ${money(Math.abs(v))}`;

    /** What still needs doing (and has not been skipped for now). */
    function todo(m) {
        const out = [];
        const sk = m.skips || {};
        if (!(m.figures.drawer && m.figures.drawer.counted_cash !== null) && !sk.drawer) out.push('drawer');
        if (!m.wages.rows.length && !sk.wages) out.push('wages');
        if (!m.payouts.rows.length && !sk.payouts) out.push('payouts');
        if (!m.purchases.rows.length && !sk.purchases) out.push('purchases');
        return out;
    }

    /** A section's footer: a link to do the missing step, "skip for now", or who skipped it. */
    function stepActions(m, item, missing, missingText) {
        const sk = (m.skips || {})[item];
        const [route, perm, label] = LINKS[item];
        const mayAct = m.can_submit;
        const href = `#/${route}${item === 'drawer' ? `?date=${m.date}` : ''}`;
        // Wages and consignor payouts are paid right on the report (item 3 / 4), not on their own page.
        const inline = mayAct && (item === 'wages' || (item === 'payouts' && m.date === data.today));
        if (!missing) return '';
        if (sk) {
            return `<div class="step-note muted" style="font-size:12.5px;margin-top:8px">⏭️ ข้ามไปก่อนโดย ${escapeHtml(sk.user_name || '-')} · ${fmtDateTime(sk.created_at)}${sk.note ? ` · ${escapeHtml(sk.note)}` : ''}
              ${mayAct ? `<button type="button" class="btn sm secondary no-print" data-unskip="${item}" style="margin-left:6px">ยกเลิกการข้าม</button>` : ''}
              ${can(perm) && !inline ? `<a class="btn sm secondary no-print" href="${href}" style="margin-left:6px">${label}</a>` : ''}</div>`;
        }
        return `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px">
            <span class="pill warn" style="font-size:12px">${missingText}</span>
            ${inline ? '' : can(perm) ? `<a class="btn sm no-print" href="${href}">${label}</a>` : `<span class="muted no-print" style="font-size:12px">(ให้ผู้มีสิทธิ์ดำเนินการ)</span>`}
            ${mayAct ? `<button type="button" class="btn sm secondary no-print" data-skip="${item}">ข้ามไปก่อน</button>` : ''}
          </div>`;
    }

    function drawDay() {
        const m = modal;
        const f = m.figures;
        const c = m.closing;
        const dr = f.drawer;
        const W = data.weekdays;
        const weekday = W[new Date(m.date + 'T00:00:00').getDay() || 7];
        const line = (label, v, style = '') => `<tr><td>${label}</td><td class="num" style="${style}">${v}</td></tr>`;
        const left = todo(m);
        const actual = (method) => m.editing ? m.form.actual[method] : c?.actual_accounts?.[method];
        const sumAccExpected = m.accounts.reduce((s, a) => s + a.expected, 0);

        const drawerHtml = dr ? `
            ${openingHtml(m, dr, line)}
            ${line('+ ขายได้เป็นเงินสด', `฿ ${money(f.cash_sales)}`)}
            ${f.purchase_out ? line('- ซื้อสินค้าด้วยเงินในลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 5)</span>', `฿ ${money(f.purchase_out)}`) : ''}
            ${line('<b>เงินสดที่ควรมีในลิ้นชักตอนปิดยอด</b>', `<b>฿ ${money(dr.should_have)}</b>`)}
            ${dr.counted_cash !== null ? `
              ${countedHtml(m, dr, line)}
              ${line('ส่วนต่าง', signed(dr.counted_cash - dr.should_have), isDiff(dr.counted_cash, dr.should_have) ? 'color:var(--bad);font-weight:600' : '')}` : ''}
            ${f.cash_out || f.payout_out ? `
              ${f.cash_out ? line('- จ่ายค่าแรงจากลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 3)</span>', `฿ ${money(f.cash_out)}`) : ''}
              ${f.payout_out ? line('- จ่ายเงินผู้ฝากขายจากลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 4)</span>', `฿ ${money(f.payout_out)}`) : ''}
              ${line(`<b>เงินสดคงเหลือในลิ้นชักหลังจ่าย</b>${dr.counted_cash === null ? ' <span class="muted" style="font-size:12px">(ยังไม่ได้นับ)</span>' : ''}`, `<b>฿ ${money(dr.after_pay)}</b>`)}` : ''}
            ${line('- คงไว้ในลิ้นชักเป็นเงินทอนวันถัดไป', `<span id="cl-float-v">฿ ${money(floatOf(m))}</span>`)}
            ${line('<b>เงินสดที่ต้องส่งวิทยาลัย</b> <span class="muted" style="font-size:12px">(ข้อ 7)</span>', `<b id="cl-send-v">฿ ${money(expectedSend(m))}</b>`)}`
            : line('เงินในลิ้นชักตอนเปิดร้าน', '<span class="muted">ไม่ได้ระบุ</span>');

        const scroll = m.el.querySelector('.modal-body')?.scrollTop || 0;
        m.el.innerHTML = `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-label="รายงานสรุปบัญชีวันที่ ${fmtDate(m.date)}" style="width:960px">
            <div class="modal-head">
              <h2>รายงานสรุปบัญชีประจำวัน${weekday} ${fmtDate(m.date)} ${statusPill(c)}</h2>
              <button type="button" class="modal-close" data-close aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body" id="cl-report">
              <div class="muted" style="font-size:12.5px;margin-bottom:10px">เวรสรุปบัญชี: ${m.on_duty.length ? names(m.on_duty) : 'ไม่มีเวร'}</div>
              ${left.length
                  ? `<div class="hint no-print" style="margin:0 0 14px"><div class="dot">!</div><div>ยังต้องดำเนินการ: <b>${left.map((k) => escapeHtml(SKIP_LABEL[k])).join(', ')}</b> — กดลิงก์ในแต่ละหัวข้อเพื่อไปทำ หรือกด <b>ข้ามไปก่อน</b></div></div>`
                  : '<div class="pill ok no-print" style="margin-bottom:14px">ข้อมูลของวันครบแล้ว</div>'}

              <div class="two-col" style="gap:14px;align-items:start">
                <section class="card card-pad">
                  <h3 style="margin:0 0 8px">1. ยอดขาย</h3>
                  <table class="cl-t"><tbody>
                    ${line('<b>ยอดขายทั้งหมด</b>', `<b>฿ ${money(f.total_sales)}</b> <span class="muted">(${f.sales_count} บิล)</span>`)}
                    ${f.methods.map((x) => line(`&nbsp;&nbsp;${escapeHtml(x.label)}`, `฿ ${money(x.amount)}`)).join('')}
                    ${f.unknown_sales ? line('&nbsp;&nbsp;นำเข้าจาก POS (ไม่ทราบวิธีชำระ)', `฿ ${money(f.unknown_sales)}`) : ''}
                  </tbody></table>
                </section>

                <section class="card card-pad">
                <h3 style="margin:0 0 8px">2. เงินเข้าบัญชีที่รับโอน / QR-Code / บัตรเครดิต</h3>
                ${m.accounts.length ? `
                <div class="table-wrap"><table>
                  <thead><tr><th>ช่องทาง</th><th class="num">ควรได้รับ (ตามยอดขาย)</th><th class="num">ได้รับจริง</th><th class="num">ส่วนต่าง</th></tr></thead>
                  <tbody>${m.accounts.map((a) => {
                      const got = actual(a.method);
                      const has = got !== undefined && got !== null && got !== '';
                      return `<tr>
                        <td>${escapeHtml(a.label)}</td>
                        <td class="num">${money(a.expected)}</td>
                        <td class="num">${m.editing
                            ? `<input type="number" min="0" step="0.01" data-acc="${a.method}" value="${escapeHtml(got ?? '')}" placeholder="ดูจากบัญชี" style="width:130px;text-align:right">`
                            : has ? `<b>${money(got)}</b>` : '<span class="muted">ยังไม่ได้ตรวจ</span>'}</td>
                        <td class="num" data-accdiff="${a.method}">${has ? `<span style="${isDiff(got, a.expected) ? 'color:var(--bad);font-weight:600' : ''}">${signed(Number(got) - a.expected)}</span>` : '-'}</td>
                      </tr>`;
                  }).join('')}
                  ${m.accounts.length > 1 ? `<tr><td><b>รวม</b></td><td class="num"><b>${money(sumAccExpected)}</b></td><td></td><td></td></tr>` : ''}
                  </tbody>
                </table></div>
                ${!m.editing && !c ? '<div class="muted" style="font-size:12px;margin-top:6px">ตรวจยอดในแอปธนาคาร / เครื่องรูดบัตร แล้วกรอกตอนบันทึกสรุปบัญชี (ข้อ 7)</div>' : ''}`
                : '<div class="muted">ร้านไม่ได้เปิดรับชำระด้วยการโอน / QR-Code / บัตรเครดิต</div>'}
                </section>
              </div>

              <div class="two-col" style="gap:14px;align-items:start;margin-top:14px">
                <section class="card card-pad">
                  <h3 style="margin:0 0 8px">3. การจ่ายค่าแรง</h3>
                  ${m.wages.rows.length ? `<table class="cl-t"><tbody>
                    ${m.wages.rows.map((w) => {
                        const fromDrawer = w.drawer_date === m.date;
                        const otherDay = w.drawer_date && !fromDrawer;
                        return `<tr><td>${escapeHtml(w.employee_name || '-')} <span class="muted" style="font-size:11.5px">${escapeHtml(w.doc_no)} · ${w.method === 'cash' ? 'เงินสด' : 'โอน'}</span>
                      ${w.method === 'cash' ? `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:3px">
                        ${fromDrawer ? '<span class="pill" style="font-size:11.5px">จ่ายจากเงินในลิ้นชักวันนี้</span>'
                            : otherDay ? `<span class="pill" style="font-size:11.5px">จ่ายจากลิ้นชักวันที่ ${fmtDate(w.drawer_date)}</span>`
                            : '<span class="pill ok" style="font-size:11.5px">ไม่ได้ใช้เงินจากลิ้นชักวันนี้</span>'}
                        ${m.can_submit && !otherDay && (fromDrawer || dr) ? `<button type="button" class="btn sm secondary no-print" data-wgdrawer="${w.id}" data-on="${fromDrawer ? 0 : 1}">${fromDrawer ? 'ไม่ได้ใช้เงินจากลิ้นชัก' : 'จ่ายจากลิ้นชักวันนี้'}</button>` : ''}
                      </div>` : ''}</td>
                      <td class="num" style="vertical-align:top;${w.method !== 'cash' || fromDrawer ? '' : 'color:var(--muted)'}">฿ ${money(w.amount)}</td></tr>`;
                    }).join('')}
                    ${line('<b>รวม</b>', `<b>฿ ${money(m.wages.total)}</b>`)}
                    ${m.wages.from_drawer ? line('&nbsp;&nbsp;จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="font-size:12px">(หักในข้อ 6)</span>', `฿ ${money(m.wages.from_drawer)}`) : ''}
                  </tbody></table>
                  ${m.wages.rows.some((w) => w.method === 'cash') ? '<div class="muted" style="font-size:12px;margin-top:6px">ค่าแรงที่จ่ายเงินสดจากเงินในลิ้นชัก กด <b>จ่ายจากลิ้นชักวันนี้</b> เพื่อหักจากเงินสดที่ต้องส่ง</div>' : ''}` : '<div class="muted">ไม่มีการจ่ายค่าแรงวันนี้</div>'}
                  ${stepActions(m, 'wages', !m.wages.rows.length, 'ยังไม่มีการจ่ายค่าแรง')}
                  ${wageFormHtml(m)}
                </section>

                <section class="card card-pad">
                  <h3 style="margin:0 0 8px">4. การจ่ายเงินค่าสินค้าฝากขายให้ผู้ฝากขาย</h3>
                  ${m.payouts.rows.length ? `<table class="cl-t"><tbody>
                    ${m.payouts.rows.map((w) => `<tr><td>${escapeHtml(w.consignor_code)} - ${escapeHtml(w.consignor_name)} <span class="muted" style="font-size:11.5px">${escapeHtml(w.doc_no)} · ${PAY_METHOD[w.method] || w.method}</span>
                      ${w.method === 'cash' ? `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:3px">
                        ${w.from_drawer ? '<span class="pill" style="font-size:11.5px">จ่ายจากเงินในลิ้นชักวันนี้</span>' : '<span class="pill ok" style="font-size:11.5px">ไม่ได้ใช้เงินจากลิ้นชักวันนี้</span>'}
                        ${w.flagged_by ? `<span class="muted" style="font-size:11px">ระบุโดย ${escapeHtml(w.flagged_by)}</span>` : ''}
                        ${m.can_submit ? `<button type="button" class="btn sm secondary no-print" data-wdrawer="${w.id}" data-on="${w.from_drawer ? 0 : 1}">${w.from_drawer ? 'ไม่ได้ใช้เงินจากลิ้นชัก' : 'จ่ายจากลิ้นชักวันนี้'}</button>` : ''}
                      </div>` : ''}</td>
                      <td class="num" style="vertical-align:top;${w.from_drawer ? '' : 'color:var(--muted)'}">฿ ${money(w.amount)}</td></tr>`).join('')}
                    ${line('<b>รวม</b>', `<b>฿ ${money(m.payouts.total)}</b>${m.payouts.cash ? ` <span class="muted" style="font-size:11.5px">(เงินสด ฿${money(m.payouts.cash)})</span>` : ''}`)}
                    ${m.payouts.from_drawer ? line('&nbsp;&nbsp;จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="font-size:12px">(หักในข้อ 6)</span>', `฿ ${money(m.payouts.from_drawer)}`) : ''}
                  </tbody></table>
                  ${m.payouts.cash ? '<div class="muted" style="font-size:12px;margin-top:6px">จ่ายเป็นเงินสดถือว่าจ่ายจากเงินในลิ้นชักของวันนั้น ถ้าใช้เงินอื่น กด <b>ไม่ได้ใช้เงินจากลิ้นชัก</b></div>' : ''}` : '<div class="muted">ไม่มีการจ่ายเงินผู้ฝากขายวันนี้</div>'}
                  ${m.payouts.pending_count ? pendingHtml(m) : ''}
                  ${stepActions(m, 'payouts', !m.payouts.rows.length, 'ยังไม่มีการจ่ายเงินผู้ฝากขาย')}
                  ${payoutFormHtml(m)}
                </section>
              </div>

              <section class="card card-pad" style="margin-top:14px">
                <h3 style="margin:0 0 8px">5. การเบิกเงินซื้อสินค้าอื่น ๆ เข้าร้าน</h3>
                ${m.purchases.rows.length ? `<table class="cl-t"><tbody>
                  ${m.purchases.rows.map((p) => `<tr><td>${escapeHtml(p.supplier_name || '-')} <span class="muted" style="font-size:11.5px">${escapeHtml(p.doc_no)}${p.receipt_no ? ` · ใบเสร็จ ${escapeHtml(p.receipt_no)}` : ''} · ${p.funding === 'profit' ? 'จากกำไร' : 'จากงบลงทุน'}</span>
                      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:3px">
                        ${p.from_drawer ? '<span class="pill" style="font-size:11.5px">จ่ายจากเงินในลิ้นชักวันนี้</span>' : '<span class="pill ok" style="font-size:11.5px">ไม่ได้ใช้เงินจากลิ้นชักวันนี้</span>'}
                        ${p.flagged_by ? `<span class="muted" style="font-size:11px">ระบุโดย ${escapeHtml(p.flagged_by)}</span>` : ''}
                        ${m.can_submit ? `<button type="button" class="btn sm secondary no-print" data-pdrawer="${p.id}" data-on="${p.from_drawer ? 0 : 1}">${p.from_drawer ? 'ไม่ได้ใช้เงินจากลิ้นชัก' : 'จ่ายจากลิ้นชักวันนี้'}</button>` : ''}
                      </div></td>
                    <td class="num" style="vertical-align:top;${p.from_drawer ? '' : 'color:var(--muted)'}">฿ ${money(p.total)}</td></tr>`).join('')}
                  ${line('<b>รวม</b>', `<b>฿ ${money(m.purchases.total)}</b>`)}
                  ${line('&nbsp;&nbsp;จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="font-size:12px">(หักในข้อ 6)</span>', `฿ ${money(m.purchases.from_drawer)}`)}
                </tbody></table>
                <div class="muted" style="font-size:12px;margin-top:6px">ปกติถือว่าจ่ายจากเงินในลิ้นชักของวันนั้น ถ้าใช้เงินอื่น (เช่น เงินที่เบิกจากกำไรวันก่อน) กด <b>ไม่ได้ใช้เงินจากลิ้นชัก</b></div>` : '<div class="muted">ไม่มีการซื้อสินค้าเข้าร้านวันนี้</div>'}
                ${stepActions(m, 'purchases', !m.purchases.rows.length, 'ยังไม่มีการบันทึกซื้อสินค้า')}
              </section>

              <section class="card card-pad" style="margin-top:14px">
                <h3 style="margin:0 0 8px">6. เงินสดในลิ้นชัก</h3>
                <table class="cl-t" style="max-width:620px"><tbody>${drawerHtml}</tbody></table>
                ${dr && dr.counted_cash !== null ? `<div class="muted" style="font-size:11.5px;margin-top:4px">ปิดยอดโดย ${escapeHtml(dr.closed_by_name || '-')} ${dr.closed_at ? fmtDateTime(dr.closed_at) : ''}</div>` : ''}
                ${stepActions(m, 'drawer', !(dr && dr.counted_cash !== null), dr ? 'ยังไม่ได้นับเงินปิดยอดลิ้นชัก' : 'ไม่ได้ระบุเงินในลิ้นชัก / ยังไม่ปิดยอด')}
              </section>

              <section class="card card-pad" style="margin-top:14px">
                <h3 style="margin:0 0 8px">7. ส่งเงินสดให้วิทยาลัย</h3>
                ${sendHtml(m)}
              </section>
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" id="cl-print">พิมพ์รายงาน</button>
              <button type="button" class="btn secondary" data-close>ปิด</button>
            </div>
          </div>
        </div>`;
        m.el.querySelector('.modal-body').scrollTop = scroll;
        wireDay();
    }

    function sendHtml(m) {
        const f = m.figures;
        const c = m.closing;
        const row = (label, v, style = '') => `<tr><td>${label}</td><td class="num" style="${style}">${v}</td></tr>`;
        if (m.editing) {
            return `
            <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:start">
              ${f.drawer ? `<label class="field">เงินทอนวันถัดไป (บาท) <input data-f="float_kept" type="number" min="0" step="0.25" value="${escapeHtml(m.form.float_kept)}">
                <span class="muted" style="font-size:11.5px;font-weight:400">คงไว้ในลิ้นชัก · เงินตั้งต้นวันนี้ ฿${money(f.drawer.opening_cash)} · เหลือในลิ้นชัก ฿${money(f.drawer.after_pay)}</span></label>` : ''}
              <label class="field">จำนวนเงินสดที่ส่ง (บาท) * <input data-f="cash_sent" type="number" min="0" step="0.25" value="${escapeHtml(m.form.cash_sent)}">
                <span class="muted" style="font-size:11.5px;font-weight:400">ควรส่ง <span id="cl-expected">฿${money(expectedSend(m))}</span> <span id="cl-diff">${diffText(Number(m.form.cash_sent) - expectedSend(m))}</span></span></label>
              <label class="field">ส่งให้ <input data-f="sent_to" maxlength="150" value="${escapeHtml(m.form.sent_to)}"></label>
              <label class="field">เลขที่ใบเสร็จ / ใบรับเงิน <input data-f="receipt_no" maxlength="60" value="${escapeHtml(m.form.receipt_no)}"></label>
            </div>
            <label class="field" style="margin-top:10px">หมายเหตุ <span class="muted" style="font-weight:400">(จำเป็นถ้ายอดเงินไม่ตรง)</span><textarea data-f="note" rows="2" maxlength="500">${escapeHtml(m.form.note)}</textarea></label>
            <div class="muted" style="font-size:12px;margin-top:6px">ยอดที่ควรส่ง = เงินที่นับได้ตอนปิดยอดลิ้นชัก (ถ้ายังไม่ปิดยอด ใช้เงินที่ควรมี) หักค่าแรง เงินผู้ฝากขาย และค่าสินค้าที่จ่ายจากลิ้นชัก แล้วหักเงินที่คงไว้เป็นเงินทอนวันถัดไป (ดูข้อ 6)</div>
            ${m.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(m.error)}</div>` : ''}
            <div class="no-print" style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
              ${c ? '<button type="button" class="btn secondary" id="cl-cancel">ยกเลิก</button>' : ''}
              <button type="button" class="btn" id="cl-save" ${m.busy ? 'disabled' : ''}>${m.busy ? 'กำลังบันทึก...' : 'บันทึกสรุปบัญชี'}</button>
            </div>`;
        }
        if (!c) return `<div class="muted">ยังไม่มีการสรุปบัญชีวันนี้${m.can_submit ? '' : ' — สรุปได้เฉพาะผู้ที่มีเวรวันนี้ ผู้จัดการ หรือผู้ดูแลระบบ'}</div>`;
        const self = Number(c.closer_id) === Number(user?.id);
        return `
          <table class="cl-t"><tbody>
            ${row('<b>เงินสดที่ส่ง</b>', `<b>฿ ${money(c.cash_sent)}</b>`)}
            ${row('ยอดที่ควรส่ง (ตอนสรุป)', `฿ ${money(c.expected_send)}`)}
            ${c.float_kept != null ? row('คงไว้ในลิ้นชักเป็นเงินทอนวันถัดไป', `฿ ${money(c.float_kept)}`) : ''}
            ${isDiff(c.cash_sent, c.expected_send) ? row('ส่วนต่าง', signed(Number(c.cash_sent) - Number(c.expected_send)), 'color:var(--bad);font-weight:600') : ''}
            ${row('ส่งให้', escapeHtml(c.sent_to || '-'))}
            ${row('เลขที่ใบเสร็จ', escapeHtml(c.receipt_no || '-'))}
            ${c.note ? row('หมายเหตุ', escapeHtml(c.note)) : ''}
          </tbody></table>
          <div class="muted" style="font-size:12px;margin-top:6px">สรุปโดย ${escapeHtml(c.closer_name || '-')} · ${fmtDateTime(c.updated_at)}</div>
          ${c.verified_at ? `<div class="pill ok" style="font-size:12px;margin-top:6px">ยืนยันโดย ${escapeHtml(c.verified_by_name || '-')} · ${fmtDateTime(c.verified_at)}</div>` : ''}
          ${isDiff(c.total_sales, f.total_sales) ? `<div class="pill warn" style="font-size:12px;margin-top:6px">ยอดขายเปลี่ยนไปหลังสรุป (ตอนสรุป ฿${money(c.total_sales)}) — ควรสรุปใหม่</div>` : ''}
          ${m.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(m.error)}</div>` : ''}
          <div class="no-print" style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px">
            ${m.can_submit && (!c.verified_at || data.can_manage) ? '<button type="button" class="btn secondary" id="cl-edit">แก้ไข / สรุปใหม่</button>' : ''}
            ${data.can_manage && !c.verified_at ? (self
                ? '<span class="muted" style="font-size:12px;align-self:center">คุณเป็นผู้สรุปวันนี้ ให้ผู้จัดการหรือผู้ดูแลระบบคนอื่นยืนยัน</span>'
                : `<button type="button" class="btn" id="cl-verify" ${m.busy ? 'disabled' : ''}>ยืนยันว่าวิทยาลัยได้รับเงินแล้ว</button>`) : ''}
          </div>`;
    }

    function wireDay() {
        const m = modal;
        const f = m.figures;
        m.el.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeDay));
        m.el.querySelector('#cl-print').addEventListener('click', printReport);
        m.el.querySelectorAll('[data-f]').forEach((inp) => inp.addEventListener('input', () => {
            const before = expectedSend(m);
            m.form[inp.dataset.f] = inp.value;
            if (inp.dataset.f === 'float_kept') {
                // The suggested amount to send follows the change kept, unless it was typed in.
                if (Number(m.form.cash_sent) === before) {
                    m.form.cash_sent = String(expectedSend(m));
                    m.el.querySelector('[data-f="cash_sent"]').value = m.form.cash_sent;
                }
                m.el.querySelector('#cl-float-v').textContent = `฿ ${money(floatOf(m))}`;
                m.el.querySelector('#cl-send-v').textContent = `฿ ${money(expectedSend(m))}`;
                m.el.querySelector('#cl-expected').textContent = `฿${money(expectedSend(m))}`;
            }
            m.el.querySelector('#cl-diff').textContent = diffText(Number(m.form.cash_sent) - expectedSend(m));
        }));
        m.el.querySelectorAll('[data-acc]').forEach((inp) => inp.addEventListener('input', () => {
            m.form.actual[inp.dataset.acc] = inp.value;
            const a = m.accounts.find((x) => x.method === inp.dataset.acc);
            const cell = m.el.querySelector(`[data-accdiff="${a.method}"]`);
            cell.innerHTML = inp.value === '' ? '-'
                : `<span style="${isDiff(inp.value, a.expected) ? 'color:var(--bad);font-weight:600' : ''}">${signed(Number(inp.value) - a.expected)}</span>`;
        }));
        m.el.querySelectorAll('[data-skip]').forEach((b) => b.addEventListener('click', () => skip(b.dataset.skip, true)));
        m.el.querySelectorAll('[data-pdrawer]').forEach((b) => b.addEventListener('click', () => setDrawer('purchases', Number(b.dataset.pdrawer), b.dataset.on === '1')));
        m.el.querySelectorAll('[data-wgdrawer]').forEach((b) => b.addEventListener('click', () => setDrawer('wages', Number(b.dataset.wgdrawer), b.dataset.on === '1')));
        m.el.querySelectorAll('[data-wdrawer]').forEach((b) => b.addEventListener('click', () => setDrawer('payouts', Number(b.dataset.wdrawer), b.dataset.on === '1')));
        m.el.querySelectorAll('[data-unskip]').forEach((b) => b.addEventListener('click', () => skip(b.dataset.unskip, false)));
        m.el.querySelectorAll('a[href^="#/"]').forEach((a) => a.addEventListener('click', closeDay));
        m.el.querySelector('#cl-save')?.addEventListener('click', save);
        m.el.querySelector('#cl-cancel')?.addEventListener('click', () => { m.editing = false; m.error = null; drawDay(); });
        m.el.querySelector('#cl-edit')?.addEventListener('click', () => { startEdit(); drawDay(); });
        m.el.querySelector('#cl-verify')?.addEventListener('click', verify);
        wirePay(m);
        m.el.querySelector('[data-open-edit]')?.addEventListener('click', () => editOpening(m));
        m.el.querySelector('[data-open-undo]')?.addEventListener('click', () => undoOpening(m));
        m.el.querySelector('[data-counted-edit]')?.addEventListener('click', () => editCounted(m));
        m.el.querySelector('[data-counted-undo]')?.addEventListener('click', () => undoCounted(m));
    }

    async function skip(item, on) {
        const m = modal;
        let note = null;
        if (on) {
            note = await promptDialog(`ข้าม "${SKIP_LABEL[item]}" ของวันนี้ไปก่อน (ยังกลับมาทำทีหลังได้)`, {
                title: 'ข้ามไปก่อน', okText: 'ข้ามไปก่อน', placeholder: 'เหตุผล (ไม่บังคับ) เช่น ผู้ฝากยังไม่มารับเงิน',
            });
            if (note === null || note === undefined || docModalGone(m)) return;
        }
        try {
            m.skips = (await api.post(`/closing/${m.date}/skip`, { item, on, note })).data;
            m.error = null;
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        drawDay();
    }

    const docModalGone = (m) => modal !== m;

    /* ---- Opening cash: the staff's count, corrected by whoever sums up the day (with a reason) ---- */

    function openingHtml(m, dr, line) {
        const adj = dr.opening_adjust;
        const hist = dr.opening_history || [];
        const btns = m.can_submit ? `<span class="no-print" style="display:inline-flex;gap:6px;margin-left:8px">
            <button type="button" class="btn sm secondary" data-open-edit>${adj ? 'แก้อีกครั้ง' : 'แก้ยอด'}</button>
            ${adj ? '<button type="button" class="btn sm secondary" data-open-undo>ยกเลิกการแก้ไข</button>' : ''}</span>` : '';
        const histHtml = hist.length ? `<tr class="no-print"><td colspan="2" style="padding-top:2px">
            <details style="font-size:12px"><summary class="muted" style="cursor:pointer">ประวัติการแก้เงินตอนเปิดร้าน (${hist.length})</summary>
            ${hist.map((h) => `<div class="muted" style="margin:3px 0 0 14px">${fmtDateTime(h.created_at)} · ${escapeHtml(h.user_name || '-')} ·
              ${h.amount === null ? 'ยกเลิกการแก้ไข' : `แก้เป็น ฿${money(h.amount)}`}${h.reason ? ` · ${escapeHtml(h.reason)}` : ''}</div>`).join('')}
            </details></td></tr>` : '';
        if (!adj) {
            return `${line(`เงินในลิ้นชักตอนเปิดร้าน <span class="muted" style="font-size:12px">(พนักงานนับ)</span>${btns}`, `฿ ${money(dr.opening_cash)}`)}${histHtml}`;
        }
        return `
            ${line('<span class="muted">เงินในลิ้นชักตอนเปิดร้าน ที่พนักงานนับ</span>', `<span class="muted" style="text-decoration:line-through">฿ ${money(dr.opening_counted)}</span>`)}
            ${line(`<b>เงินในลิ้นชักตอนเปิดร้าน</b> <span class="pill warn" style="font-size:11.5px">ผู้สรุปบัญชีแก้</span>${btns}
              <div class="muted" style="font-size:12px;margin-top:2px">เหตุผล: ${escapeHtml(adj.reason || '-')} · ${escapeHtml(adj.user_name || '-')} ${fmtDateTime(adj.created_at)}
                · ต่างจากที่นับ ${signed(adj.amount - dr.opening_counted)}</div>`, `<b>฿ ${money(dr.opening_cash)}</b>`)}
            ${histHtml}`;
    }

    async function editOpening(m) {
        const dr = m.figures.drawer;
        const amount = await promptDialog(`พนักงานนับได้ ฿${money(dr.opening_counted)}${dr.opening_adjust ? ` · ตอนนี้ใช้ ฿${money(dr.opening_cash)}` : ''}`, {
            title: 'แก้เงินในลิ้นชักตอนเปิดร้าน (บาท)', okText: 'ถัดไป', value: String(dr.opening_cash), required: 'กรุณากรอกจำนวนเงิน',
        });
        if (amount === null || amount === undefined || docModalGone(m)) return;
        const v = Number(String(amount).replace(/,/g, ''));
        if (!Number.isFinite(v) || v < 0) {
            alertDialog('จำนวนเงินไม่ถูกต้อง', { tone: 'error' });
            return;
        }
        const reason = await promptDialog(`แก้จาก ฿${money(dr.opening_counted)} (พนักงานนับ) เป็น ฿${money(v)}`, {
            title: 'เหตุผลที่แก้', okText: 'บันทึก', placeholder: 'เช่น นับผิด ธนบัตร 100 บาทติดกัน 1 ใบ', required: 'กรุณาระบุเหตุผล', multiline: true,
        });
        if (reason === null || reason === undefined || docModalGone(m)) return;
        await postOpening(m, { amount: v, reason: String(reason).trim() });
    }

    async function undoOpening(m) {
        const dr = m.figures.drawer;
        const note = await promptDialog(`กลับไปใช้ยอดที่พนักงานนับ ฿${money(dr.opening_counted)} (แทน ฿${money(dr.opening_cash)})`, {
            title: 'ยกเลิกการแก้เงินตอนเปิดร้าน?', okText: 'ยกเลิกการแก้ไข', placeholder: 'หมายเหตุ (ไม่บังคับ)', danger: true,
        });
        if (note === null || note === undefined || docModalGone(m)) return;
        await postOpening(m, { amount: null, reason: String(note).trim() });
    }

    /* ---- Cash counted at closing: the staff's count, corrected by whoever sums up the day (with a reason) ---- */

    function countedHtml(m, dr, line) {
        const adj = dr.counted_adjust;
        const hist = dr.counted_history || [];
        const label = '<b>เงินที่มีอยู่จริงในลิ้นชัก</b> <span class="muted" style="font-size:12px">(นับตอนปิดยอด)</span>';
        const btns = m.can_submit ? `<span class="no-print" style="display:inline-flex;gap:6px;margin-left:8px">
            <button type="button" class="btn sm secondary" data-counted-edit>${adj ? 'แก้อีกครั้ง' : 'แก้ยอด'}</button>
            ${adj ? '<button type="button" class="btn sm secondary" data-counted-undo>ยกเลิกการแก้ไข</button>' : ''}</span>` : '';
        const histHtml = hist.length ? `<tr class="no-print"><td colspan="2" style="padding-top:2px">
            <details style="font-size:12px"><summary class="muted" style="cursor:pointer">ประวัติการแก้เงินที่นับตอนปิดยอด (${hist.length})</summary>
            ${hist.map((h) => `<div class="muted" style="margin:3px 0 0 14px">${fmtDateTime(h.created_at)} · ${escapeHtml(h.user_name || '-')} ·
              ${h.amount === null ? 'ยกเลิกการแก้ไข' : `แก้เป็น ฿${money(h.amount)}`}${h.reason ? ` · ${escapeHtml(h.reason)}` : ''}</div>`).join('')}
            </details></td></tr>` : '';
        if (!adj) {
            return `${line(`${label}${btns}`, `<b>฿ ${money(dr.counted_cash)}</b>`)}${histHtml}`;
        }
        return `
            ${line('<span class="muted">เงินที่มีอยู่จริงในลิ้นชัก ที่พนักงานนับตอนปิดยอด</span>', `<span class="muted" style="text-decoration:line-through">฿ ${money(dr.counted_staff)}</span>`)}
            ${line(`${label} <span class="pill warn" style="font-size:11.5px">ผู้สรุปบัญชีแก้</span>${btns}
              <div class="muted" style="font-size:12px;margin-top:2px">เหตุผล: ${escapeHtml(adj.reason || '-')} · ${escapeHtml(adj.user_name || '-')} ${fmtDateTime(adj.created_at)}
                · ต่างจากที่นับ ${signed(adj.amount - dr.counted_staff)}</div>`, `<b>฿ ${money(dr.counted_cash)}</b>`)}
            ${histHtml}`;
    }

    async function editCounted(m) {
        const dr = m.figures.drawer;
        const amount = await promptDialog(`พนักงานนับได้ ฿${money(dr.counted_staff)}${dr.counted_adjust ? ` · ตอนนี้ใช้ ฿${money(dr.counted_cash)}` : ''} · ควรมี ฿${money(dr.should_have)}`, {
            title: 'แก้เงินที่มีอยู่จริงในลิ้นชักตอนปิดยอด (บาท)', okText: 'ถัดไป', value: String(dr.counted_cash), required: 'กรุณากรอกจำนวนเงิน',
        });
        if (amount === null || amount === undefined || docModalGone(m)) return;
        const v = Number(String(amount).replace(/,/g, ''));
        if (!Number.isFinite(v) || v < 0) {
            alertDialog('จำนวนเงินไม่ถูกต้อง', { tone: 'error' });
            return;
        }
        const reason = await promptDialog(`แก้จาก ฿${money(dr.counted_staff)} (พนักงานนับ) เป็น ฿${money(v)}`, {
            title: 'เหตุผลที่แก้', okText: 'บันทึก', placeholder: 'เช่น นับใหม่แล้วได้ไม่ตรง ธนบัตรติดกัน', required: 'กรุณาระบุเหตุผล', multiline: true,
        });
        if (reason === null || reason === undefined || docModalGone(m)) return;
        await postOpening(m, { amount: v, reason: String(reason).trim() }, 'counted');
    }

    async function undoCounted(m) {
        const dr = m.figures.drawer;
        const note = await promptDialog(`กลับไปใช้ยอดที่พนักงานนับ ฿${money(dr.counted_staff)} (แทน ฿${money(dr.counted_cash)})`, {
            title: 'ยกเลิกการแก้เงินที่นับตอนปิดยอด?', okText: 'ยกเลิกการแก้ไข', placeholder: 'หมายเหตุ (ไม่บังคับ)', danger: true,
        });
        if (note === null || note === undefined || docModalGone(m)) return;
        await postOpening(m, { amount: null, reason: String(note).trim() }, 'counted');
    }

    async function postOpening(m, body, what = 'opening') {
        try {
            const r = (await api.post(`/closing/${m.date}/${what}`, body)).data;
            if (docModalGone(m)) return;
            // The float kept for the next day follows the opening cash unless it was typed in.
            const keepFloat = m.editing && Number(m.form.float_kept) === Number(m.figures.float_kept);
            const keepSend = m.editing && Number(m.form.cash_sent) === expectedSend(m);
            Object.assign(m, r);
            if (keepFloat) m.form.float_kept = String(m.figures.float_kept);
            if (keepSend) m.form.cash_sent = String(expectedSend(m));
            load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        drawDay();
    }

    /* ---- Items 3 and 4 paid right on the report: a wage, a consignor payout, a waiting withdrawal ---- */

    const newWage = () => ({ user_id: '', wage_type: 'day', units: '', rate: '', amount: '', method: 'cash', from_drawer: true, note: '', touched: false });
    const newPayout = () => ({
        consignor_id: '', period: 'today', from: '', to: '', data: null, loading: false, picked: new Set(),
        amount: '', touched: false, reason: '', method: 'cash', from_drawer: true, stock: {}, returns: new Set(),
    });
    /** Pieces of a consignor product still on the shelf (the payout data lists those not at 0). */
    const pfLeft = (d, id) => Number(d.stock.find((x) => Number(x.id) === Number(id))?.qty_stock || 0);

    async function loadPayOptions(m) {
        try {
            const o = (await api.get(`/closing/${m.date}/pay-options`)).data;
            if (docModalGone(m)) return;
            m.opts = o;
            drawDay();
        } catch {
            // the report still works; the pay buttons stay hidden
        }
    }

    const wageAuto = (w) => {
        const u = Number(w.units);
        const r = Number(w.rate);
        return w.wage_type !== 'fixed' && u > 0 && r > 0 ? String(Math.round(u * r * 100) / 100) : '';
    };

    function wageFormHtml(m) {
        const o = m.opts;
        if (!m.can_submit || !o) return '';
        if (!m.wf) return '<button type="button" class="btn sm no-print" data-pay-open="wages" style="margin-top:10px">+ จ่ายค่าแรง</button>';
        const w = m.wf;
        const fixed = w.wage_type === 'fixed';
        return `
        <div class="no-print cl-pay" style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px">
          <div style="font-weight:600;margin-bottom:8px">จ่ายค่าแรง · ลงวันที่ ${fmtDate(m.date)}</div>
          <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));gap:10px">
            <label class="field" style="grid-column:1/-1">พนักงาน *
              <select data-wf="user_id"><option value="">— เลือกพนักงาน —</option>
                ${o.staff.map((s) => `<option value="${s.id}" ${String(s.id) === String(w.user_id) ? 'selected' : ''}>${escapeHtml(s.full_name)}</option>`).join('')}</select></label>
            <label class="field">ประเภทค่าแรง
              <select data-wf="wage_type">${Object.entries(o.types).map(([k, v]) => `<option value="${k}" ${k === w.wage_type ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}</select></label>
            ${fixed ? '' : `
            <label class="field">จำนวน${UNIT[w.wage_type] || ''} <input data-wf="units" type="number" min="0" step="0.5" value="${escapeHtml(w.units)}"></label>
            <label class="field">อัตราต่อ${UNIT[w.wage_type] || ''} <input data-wf="rate" type="number" min="0" step="1" value="${escapeHtml(w.rate)}"></label>`}
            <label class="field">จำนวนเงิน (บาท) * <input data-wf="amount" type="number" min="0" step="0.01" value="${escapeHtml(w.amount)}"></label>
            <label class="field">วิธีจ่าย
              <select data-wf="method">${Object.entries(o.methods).map(([k, v]) => `<option value="${k}" ${k === w.method ? 'selected' : ''}>${escapeHtml(v)}</option>`).join('')}</select></label>
            <label class="field" style="grid-column:1/-1">หมายเหตุ <input data-wf="note" maxlength="200" value="${escapeHtml(w.note)}"></label>
          </div>
          ${w.method === 'cash' ? `
          <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:13px;${o.drawer ? 'cursor:pointer' : 'opacity:.6'}">
            <input type="checkbox" data-wf="from_drawer" ${w.from_drawer && o.drawer ? 'checked' : ''} ${o.drawer ? '' : 'disabled'} style="margin-top:3px">
            <span>จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="display:block;font-size:11.5px;font-weight:400">${o.drawer ? 'หักจากเงินสดที่ต้องส่งวิทยาลัย (ข้อ 6)' : 'ใช้ไม่ได้: วันนี้ไม่ได้ระบุเงินในลิ้นชัก'}</span></span>
          </label>` : ''}
          ${m.payError?.kind === 'wages' ? `<div class="error-box" style="margin-top:10px">${escapeHtml(m.payError.msg)}</div>` : ''}
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
            <button type="button" class="btn sm secondary" data-pay-close="wages">ยกเลิก</button>
            <button type="button" class="btn sm" data-pay-save="wages" ${m.payBusy ? 'disabled' : ''}>${m.payBusy ? 'กำลังบันทึก...' : 'บันทึกการจ่ายค่าแรง'}</button>
          </div>
        </div>`;
    }

    /** Monday of the week the report's day falls in (ISO). */
    const weekStart = (iso) => {
        const d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };
    const pfRange = (m) => {
        const p = m.pf;
        if (p.period === 'week') return [weekStart(m.date), m.date];
        if (p.period === 'custom') return [p.from || m.date, p.to || m.date];
        return [m.date, m.date];
    };
    // The consignor's share of the chosen products' unpaid sales: the amount to pay unless typed in.
    const pfSales = (m) => Math.round((m.pf.data?.sales || []).filter((x) => m.pf.picked.has(x.product_id)).reduce((t, x) => t + x.share, 0) * 100) / 100;
    const pfAmount = (m) => (m.pf.touched ? Number(m.pf.amount) : pfSales(m));

    /** Loads the unpaid sales of the chosen consignor and period; all products start ticked. */
    async function loadPayoutSales(m) {
        const p = m.pf;
        if (!p.consignor_id) { p.data = null; drawDay(); return; }
        const [from, to] = pfRange(m);
        p.loading = true;
        drawDay();
        try {
            const d = (await api.get(`/closing/${m.date}/payout-sales?${new URLSearchParams({ consignor_id: p.consignor_id, from, to })}`)).data;
            if (docModalGone(m) || m.pf !== p) return;
            p.data = d;
            p.picked = new Set(d.sales.map((x) => x.product_id));
            if (!p.touched) p.amount = String(pfSales(m));
        } catch (e) {
            m.payError = { kind: 'payouts', msg: e.message };
        }
        p.loading = false;
        drawDay();
    }

    /** Difference from the sales, the reason it needs, and the balance after: redrawn as the amount is typed. */
    function payoutDiffHtml(m) {
        const p = m.pf;
        const d = p.data;
        if (!d) return '';
        const amount = pfAmount(m);
        const diff = Math.round((amount - pfSales(m)) * 100) / 100;
        const after = Math.round((d.balance - amount) * 100) / 100;
        return `
          ${Math.abs(diff) >= 0.005 ? `<div class="chip" style="margin-top:6px">ต่างจากยอดขาย <b>&nbsp;${signed(diff)}</b></div>
            <input data-pf="reason" maxlength="150" placeholder="เหตุผลที่จ่ายต่างจากยอดขาย (จำเป็น) เช่น สินค้าสูญหาย 2 ชิ้น ร้านรับผิดชอบ" value="${escapeHtml(p.reason)}" style="width:100%;margin-top:6px">` : ''}
          <div class="muted" style="font-size:12px;margin-top:6px">ยอดคงเหลือในบัญชีผู้ฝาก ฿${money(d.balance)} → หลังจ่าย
            <b style="${after < 0 ? 'color:var(--bad)' : ''}">${after < 0 ? '-' : ''}฿${money(Math.abs(after))}</b>${after < 0 ? ' (ติดลบ หักคืนจากยอดขายครั้งต่อไป)' : ''}</div>`;
    }

    function payoutFormHtml(m) {
        const o = m.opts;
        if (!m.can_submit || !o || !o.can_payout) return '';
        if (!m.pf) return '<button type="button" class="btn sm no-print" data-pay-open="payouts" style="margin-top:10px">+ จ่ายเงินผู้ฝากขาย</button>';
        const p = m.pf;
        const d = p.data;
        const [from, to] = pfRange(m);
        const periods = [['today', 'วันนี้'], ['week', 'สัปดาห์นี้'], ['custom', 'กำหนดเอง']];
        const canReturn = can('return.create');
        const salesHtml = !p.consignor_id ? '' : p.loading ? '<div class="muted" style="margin-top:8px">กำลังโหลดรายการขาย...</div>' : !d ? '' : `
          ${d.ready ? '' : '<div class="error-box" style="margin-top:8px;font-size:12.5px">ยังแยกรายการที่เบิกแล้วไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0057) ก่อน</div>'}
          <div style="font-weight:600;font-size:13px;margin-top:10px">รายการขายที่ยังไม่ได้เบิก ${from === to ? fmtDate(from) : `${fmtDate(from)} – ${fmtDate(to)}`}</div>
          ${d.sales.length ? `<table class="cl-t" style="font-size:13px"><tbody>
            ${d.sales.map((x) => {
                const left = pfLeft(d, x.product_id);
                return `<tr><td><label style="display:flex;gap:6px;align-items:flex-start;cursor:pointer">
                <input type="checkbox" data-pf-pick="${x.product_id}" ${p.picked.has(x.product_id) ? 'checked' : ''} style="margin-top:3px">
                <span>${escapeHtml(x.name)} <span class="muted" style="font-size:11.5px">ขาย ${x.qty} ชิ้น ฿${money(x.sales)}</span></span></label></td>
              <td class="num" style="white-space:nowrap">฿ ${money(x.share)}
                ${canReturn ? `<label class="muted" style="display:flex;gap:4px;align-items:center;justify-content:flex-end;font-size:11.5px;margin-top:2px;${left > 0 ? 'cursor:pointer' : 'opacity:.6'}"
                  title="${left > 0 ? `คืนสินค้าที่เหลือทั้งหมด ${left} ชิ้นให้ผู้ฝาก: ปรับยอดคงเหลือเป็น 0 (สินค้ายังอยู่ในสต๊อก รับฝากเพิ่มได้ตามปกติ)` : 'ไม่มีสินค้าเหลือให้คืน'}">
                  <input type="checkbox" data-pf-return="${x.product_id}" ${p.returns.has(x.product_id) && left > 0 ? 'checked' : ''} ${left > 0 ? '' : 'disabled'}>
                  คืนสินค้า${left > 0 ? ` (${left} ชิ้น)` : ''}</label>` : ''}</td></tr>`;
            }).join('')}
            <tr><td><b>ส่วนของผู้ฝาก (รายการที่เลือก)</b></td><td class="num"><b>฿ ${money(pfSales(m))}</b></td></tr>
          </tbody></table>` : '<div class="muted" style="font-size:12.5px;margin-top:4px">ไม่มีรายการขายที่ยังไม่ได้เบิกในช่วงนี้</div>'}`;
        const stockHtml = !d || !d.stock.length ? '' : `
          <details style="margin-top:10px" ${Object.values(p.stock).some((x) => x.on) || d.stock.some((x) => p.returns.has(Number(x.id)) && x.qty_stock > 0) ? 'open' : ''}>
            <summary style="cursor:pointer;font-weight:600;font-size:13px">ปรับยอดคงเหลือสินค้า (เช่น สินค้าสูญหาย)</summary>
            <table class="cl-t" style="font-size:13px"><tbody>
            ${d.stock.map((x) => {
                // Returning the goods sets what is left to 0: the product stays in the list (and in stock records), shown at 0.
                if (p.returns.has(Number(x.id)) && x.qty_stock > 0) {
                    return `<tr><td><label style="display:flex;gap:6px;align-items:flex-start">
                    <input type="checkbox" checked disabled style="margin-top:3px">
                    <span>${escapeHtml(x.name)} <span class="muted" style="font-size:11.5px">ในระบบเหลือ ${x.qty_stock} ชิ้น</span>
                      <span class="pill warn" title="ติ๊ก คืนสินค้า ไว้ในรายการขายด้านบน: คืนให้ผู้ฝาก ${x.qty_stock} ชิ้น ยอดคงเหลือเป็น 0 สินค้ายังอยู่ในสต๊อก รับฝากเพิ่มได้ตามปกติ">คืนให้ผู้ฝาก</span></span></label></td>
                  <td class="num" style="white-space:nowrap">เหลือ <input type="number" value="0" disabled style="width:70px;text-align:right"> ชิ้น</td></tr>`;
                }
                const st = p.stock[x.id] || { on: false, qty: '0' };
                return `<tr><td><label style="display:flex;gap:6px;align-items:flex-start;cursor:pointer">
                    <input type="checkbox" data-pf-stock="${x.id}" ${st.on ? 'checked' : ''} style="margin-top:3px">
                    <span>${escapeHtml(x.name)} <span class="muted" style="font-size:11.5px">ในระบบเหลือ ${x.qty_stock} ชิ้น</span></span></label></td>
                  <td class="num" style="white-space:nowrap">เหลือ <input type="number" min="0" step="1" data-pf-stock-qty="${x.id}" value="${escapeHtml(st.qty)}" ${st.on ? '' : 'disabled'} style="width:70px;text-align:right"> ชิ้น</td></tr>`;
            }).join('')}
            </tbody></table>
          </details>`;
        return `
        <div class="no-print cl-pay" style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px">
          <div style="font-weight:600;margin-bottom:8px">จ่ายเงินให้ผู้ฝากขาย</div>
          <label class="field" style="margin-bottom:8px">ผู้ฝากขาย *
            <select data-pf="consignor_id"><option value="">— เลือกผู้ฝากขาย —</option>
              ${o.consignors.map((x) => `<option value="${x.id}" ${String(x.id) === String(p.consignor_id) ? 'selected' : ''}>${escapeHtml(x.code)} - ${escapeHtml(x.name)} (฿${money(x.balance)})</option>`).join('')}</select></label>
          <div style="font-size:13px;font-weight:600">จ่ายตามรายการขายวันที่</div>
          <div class="actions-row" style="margin-top:4px">
            ${periods.map(([k, v]) => `<button type="button" class="tab-btn ${p.period === k ? 'active' : ''}" data-pf-period="${k}">${v}</button>`).join('')}
          </div>
          ${p.period === 'custom' ? `<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px;font-size:13px">
            ตั้งแต่ <input type="date" data-pf-date="from" value="${escapeHtml(from)}" max="${m.date}"> ถึง <input type="date" data-pf-date="to" value="${escapeHtml(to)}" max="${m.date}"></div>` : ''}
          ${salesHtml}
          <label class="field" style="margin-top:10px">จำนวนเงินที่จ่าย (บาท) *
            <input data-pf="amount" type="number" step="0.25" value="${escapeHtml(p.touched ? p.amount : String(pfSales(m)))}" ${d ? '' : 'disabled'}>
            <span class="muted" style="font-size:11.5px;font-weight:400">ตามรายการขายที่เลือกโดยอัตโนมัติ · แก้เป็นยอดอื่นได้พร้อมระบุเหตุผล${p.touched ? ' · <a href="#" data-pf-reset>ใช้ยอดตามรายการขาย</a>' : ''}</span></label>
          <div data-pf-diff>${payoutDiffHtml(m)}</div>
          ${stockHtml}
          <div class="actions-row" style="margin-top:10px">
            ${Object.entries(PAY_METHOD).map(([k, v]) => `<button type="button" class="tab-btn ${p.method === k ? 'active' : ''}" data-pf-method="${k}">${v}</button>`).join('')}
          </div>
          ${p.method === 'cash' ? `
          <label style="display:flex;gap:8px;align-items:flex-start;margin-top:8px;font-size:13px;cursor:pointer">
            <input type="checkbox" data-pf="from_drawer" ${p.from_drawer ? 'checked' : ''} style="margin-top:3px">
            <span>จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="display:block;font-size:11.5px;font-weight:400">หักจากเงินสดที่ต้องส่งวิทยาลัย (ข้อ 6) · ไม่ติ๊กถ้าใช้เงินอื่น</span></span>
          </label>` : ''}
          ${m.payError?.kind === 'payouts' ? `<div class="error-box" style="margin-top:10px">${escapeHtml(m.payError.msg)}</div>` : ''}
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
            <button type="button" class="btn sm secondary" data-pay-close="payouts">ยกเลิก</button>
            <button type="button" class="btn sm" data-pay-save="payouts" ${m.payBusy || !d ? 'disabled' : ''}>${m.payBusy ? 'กำลังบันทึก...' : 'บันทึกการจ่ายเงิน'}</button>
          </div>
        </div>`;
    }

    /** Withdrawals waiting to be paid: paid from today's report with one click. */
    function pendingHtml(m) {
        const o = m.opts;
        const head = `<span class="pill warn" style="font-size:12px">คำขอเบิกรอจ่าย ${m.payouts.pending_count} รายการ ฿${money(m.payouts.pending_amount)}</span>`;
        if (!m.can_submit || !o?.can_payout || !o.pending.length) {
            return `<div style="margin-top:8px">${head}${can('withdraw.create') ? '<a class="btn sm no-print" href="#/withdraw" style="margin-left:6px">ไปจ่ายเงิน</a>' : ''}</div>`;
        }
        return `<div class="no-print" style="margin-top:10px">${head}
          <table class="cl-t" style="margin-top:6px"><tbody>
          ${o.pending.map((r) => `<tr><td>${escapeHtml(r.consignor_code)} - ${escapeHtml(r.consignor_name)} <span class="muted" style="font-size:11.5px">${escapeHtml(r.doc_no)} · ${PAY_METHOD[r.method] || r.method} · ขอ ${fmtDateTime(r.requested_at)}</span></td>
            <td class="num" style="white-space:nowrap">฿ ${money(r.amount)} <button type="button" class="btn sm" data-pay-approve="${r.id}" style="margin-left:6px" ${m.payBusy ? 'disabled' : ''}>จ่าย</button></td></tr>`).join('')}
          </tbody></table></div>`;
    }

    function wirePay(m) {
        const $ = (s) => m.el.querySelector(s);
        m.el.querySelectorAll('[data-pay-open]').forEach((b) => b.addEventListener('click', () => {
            if (b.dataset.payOpen === 'wages') m.wf = newWage(); else m.pf = newPayout();
            m.payError = null;
            drawDay();
            m.el.querySelector(b.dataset.payOpen === 'wages' ? '[data-wf="user_id"]' : '[data-pf="consignor_id"]')?.focus();
        }));
        m.el.querySelectorAll('[data-pay-close]').forEach((b) => b.addEventListener('click', () => {
            if (b.dataset.payClose === 'wages') m.wf = null; else m.pf = null;
            m.payError = null;
            drawDay();
        }));
        m.el.querySelectorAll('[data-pay-save]').forEach((b) => b.addEventListener('click', () => (b.dataset.paySave === 'wages' ? payWage(m) : payPayout(m))));
        m.el.querySelectorAll('[data-pay-approve]').forEach((b) => b.addEventListener('click', () => approvePending(m, Number(b.dataset.payApprove))));

        // Wage form: selects and the checkbox redraw, typed values update in place (the box keeps its focus).
        m.el.querySelectorAll('[data-wf]').forEach((inp) => {
            const k = inp.dataset.wf;
            if (inp.type === 'checkbox') return inp.addEventListener('change', () => { m.wf.from_drawer = inp.checked; });
            if (inp.tagName === 'SELECT') {
                return inp.addEventListener('change', () => {
                    m.wf[k] = inp.value;
                    if (k === 'wage_type' && !m.wf.touched) m.wf.amount = wageAuto(m.wf);
                    drawDay();
                });
            }
            inp.addEventListener('input', () => {
                m.wf[k] = inp.value;
                if (k === 'amount') m.wf.touched = inp.value !== '';
                if ((k === 'units' || k === 'rate') && !m.wf.touched) $('[data-wf="amount"]').value = m.wf.amount = wageAuto(m.wf);
            });
        });

        if (!m.pf) return;
        const pf = m.pf;
        const redrawDiff = () => { const el = $('[data-pf-diff]'); if (el) { el.innerHTML = payoutDiffHtml(m); wireReason(); } };
        const wireReason = () => $('[data-pf="reason"]')?.addEventListener('input', (e) => { pf.reason = e.target.value; });
        wireReason();
        $('[data-pf="consignor_id"]')?.addEventListener('change', (e) => {
            Object.assign(pf, { consignor_id: e.target.value, data: null, amount: '', touched: false, reason: '', stock: {} });
            m.payError = null;
            loadPayoutSales(m);
        });
        $('[data-pf="from_drawer"]')?.addEventListener('change', (e) => { pf.from_drawer = e.target.checked; });
        $('[data-pf="amount"]')?.addEventListener('input', (e) => {
            pf.amount = e.target.value;
            pf.touched = e.target.value !== '';
            redrawDiff();
        });
        $('[data-pf-reset]')?.addEventListener('click', (e) => { e.preventDefault(); pf.touched = false; pf.reason = ''; drawDay(); });
        m.el.querySelectorAll('[data-pf-period]').forEach((b) => b.addEventListener('click', () => {
            pf.period = b.dataset.pfPeriod;
            if (pf.period === 'custom' && !pf.from) [pf.from, pf.to] = [weekStart(m.date), m.date];
            loadPayoutSales(m);
        }));
        m.el.querySelectorAll('[data-pf-date]').forEach((inp) => inp.addEventListener('change', () => {
            if (!inp.value) return;
            pf[inp.dataset.pfDate] = inp.value;
            loadPayoutSales(m);
        }));
        m.el.querySelectorAll('[data-pf-pick]').forEach((cb) => cb.addEventListener('change', () => {
            const id = Number(cb.dataset.pfPick);
            if (cb.checked) pf.picked.add(id); else pf.picked.delete(id);
            drawDay();
        }));
        m.el.querySelectorAll('[data-pf-return]').forEach((cb) => cb.addEventListener('change', () => {
            const id = Number(cb.dataset.pfReturn);
            if (cb.checked) { pf.returns.add(id); delete pf.stock[id]; } else pf.returns.delete(id);
            drawDay();
        }));
        m.el.querySelectorAll('[data-pf-stock]').forEach((cb) => cb.addEventListener('change', () => {
            const id = cb.dataset.pfStock;
            pf.stock[id] = { qty: '0', ...pf.stock[id], on: cb.checked };
            drawDay();
        }));
        m.el.querySelectorAll('[data-pf-stock-qty]').forEach((inp) => inp.addEventListener('input', () => {
            const id = inp.dataset.pfStockQty;
            pf.stock[id] = { on: true, ...pf.stock[id], qty: inp.value };
        }));
        m.el.querySelectorAll('[data-pf-method]').forEach((b) => b.addEventListener('click', () => { pf.method = b.dataset.pfMethod; drawDay(); }));
    }

    /** Takes a fresh report after a payment: the drawer and the cash to send follow, the day list too. */
    function applyReport(m, r) {
        const keepSend = m.editing && Number(m.form.cash_sent) === expectedSend(m);
        Object.assign(m, r);
        if (keepSend) m.form.cash_sent = String(expectedSend(m));
        load();
        loadPayOptions(m); // balances and waiting withdrawals changed
    }

    async function payWage(m) {
        const w = m.wf;
        const o = m.opts;
        const staff = o.staff.find((s) => String(s.id) === String(w.user_id));
        const amount = Number(w.amount);
        const fail = (msg) => { m.payError = { kind: 'wages', msg }; drawDay(); };
        if (!staff) return fail('กรุณาเลือกพนักงาน');
        if (!(amount > 0)) return fail('กรุณาระบุจำนวนเงินค่าแรง');
        const fromDrawer = w.method === 'cash' && w.from_drawer && o.drawer;
        const ok = await confirmDialog(
            `จ่ายค่าแรง ${staff.full_name} ฿${money(amount)} (${o.methods[w.method]}${fromDrawer ? ' จากเงินในลิ้นชักวันนี้' : ''}) ลงวันที่ ${fmtDate(m.date)}`,
            { title: 'ยืนยันการจ่ายค่าแรง?', okText: 'บันทึก' }
        );
        if (!ok || docModalGone(m)) return;
        m.payBusy = true;
        m.payError = null;
        drawDay();
        try {
            const res = await api.post(`/closing/${m.date}/wages`, {
                user_id: w.user_id, wage_type: w.wage_type, units: w.units, rate: w.rate, amount, method: w.method, note: w.note, from_drawer: fromDrawer,
            });
            if (docModalGone(m)) return;
            m.payBusy = false;
            m.wf = null;
            applyReport(m, res.data);
            drawDay();
            if (await confirmDialog(`บันทึก ${res.wage.doc_no} แล้ว ต้องการพิมพ์ใบสำคัญจ่ายให้พนักงานลงชื่อรับเงินหรือไม่?`, { title: 'บันทึกการจ่ายค่าแรงแล้ว', okText: 'พิมพ์', cancelText: 'ไม่พิมพ์' })) {
                printWageSlip(res.wage, o.types, o.methods);
            }
        } catch (e) {
            m.payBusy = false;
            fail(e.message);
        }
    }

    async function payPayout(m) {
        const p = m.pf;
        const d = p.data;
        const c = m.opts.consignors.find((x) => String(x.id) === String(p.consignor_id));
        const amount = Math.round(pfAmount(m) * 100) / 100;
        const sales = pfSales(m);
        const differs = Math.abs(amount - sales) >= 0.005;
        const fail = (msg) => { m.payError = { kind: 'payouts', msg }; drawDay(); };
        if (!c || !d) return fail('กรุณาเลือกผู้ฝากขาย');
        if (!(amount > 0)) return fail('จำนวนเงินที่จ่ายต้องมากกว่า 0');
        if (differs && !p.reason.trim()) return fail('ยอดที่จ่ายไม่ตรงกับยอดขาย กรุณาระบุเหตุผล');
        const stock = [];
        const returns = [...p.returns].filter((id) => pfLeft(d, id) > 0);
        for (const [id, st] of Object.entries(p.stock)) {
            if (!st.on || p.returns.has(Number(id))) continue;
            const qty = Number(st.qty);
            if (st.qty === '' || !Number.isInteger(qty) || qty < 0) return fail('จำนวนคงเหลือที่ปรับต้องเป็นจำนวนเต็มไม่ติดลบ');
            stock.push({ product_id: Number(id), qty });
        }
        const [from, to] = pfRange(m);
        const after = d.balance - amount;
        const fromDrawer = p.method === 'cash' && p.from_drawer;
        const names = (id) => d.stock.find((x) => String(x.id) === String(id))?.name || id;
        const ok = await confirmDialog(
            `จ่ายเงิน ${c.code} - ${c.name} ฿${money(amount)} (${PAY_METHOD[p.method]}${p.method === 'cash' ? (fromDrawer ? ' จากเงินในลิ้นชักวันนี้' : ' ไม่ได้ใช้เงินจากลิ้นชัก') : ''})`
            + ` · รายการขาย ${from === to ? fmtDate(from) : `${fmtDate(from)} – ${fmtDate(to)}`} ฿${money(sales)}`
            + (differs ? ` · ต่างจากยอดขาย ${signed(amount - sales)}: ${p.reason.trim()}` : '')
            + (after < 0 ? ` · บัญชีผู้ฝากจะติดลบ ฿${money(-after)}` : '')
            + (stock.length ? ` · ปรับคงเหลือ ${stock.map((x) => `${names(x.product_id)} เป็น ${x.qty} ชิ้น`).join(', ')}` : '')
            + (returns.length ? ` · คืนสินค้าให้ผู้ฝาก (ปรับยอดคงเหลือเป็น 0): ${returns.map((id) => `${names(id)} ${pfLeft(d, id)} ชิ้น`).join(', ')}` : ''),
            { title: 'ยืนยันการจ่ายเงินผู้ฝากขาย?', okText: 'บันทึก' }
        );
        if (!ok || docModalGone(m)) return;
        m.payBusy = true;
        m.payError = null;
        drawDay();
        try {
            const res = await api.post(`/closing/${m.date}/payouts`, {
                consignor_id: c.id, from, to, product_ids: [...p.picked], amount, reason: differs ? p.reason.trim() : '',
                stock, returns, method: p.method, from_drawer: fromDrawer,
            });
            if (docModalGone(m)) return;
            m.payBusy = false;
            m.pf = null;
            applyReport(m, res.data);
        } catch (e) {
            m.payBusy = false;
            return fail(e.message);
        }
        drawDay();
    }

    async function approvePending(m, id) {
        const r = m.opts.pending.find((x) => x.id === id || Number(x.id) === id);
        if (!r) return;
        const ok = await confirmDialog(
            `${r.doc_no} · ${r.consignor_code} - ${r.consignor_name} ฿${money(r.amount)} (${PAY_METHOD[r.method] || r.method})${r.method === 'cash' ? ' จ่ายจากเงินในลิ้นชักวันนี้' : ''}`,
            { title: 'จ่ายเงินตามคำขอเบิก?', okText: 'จ่ายเงิน' }
        );
        if (!ok || docModalGone(m)) return;
        m.payBusy = true;
        drawDay();
        try {
            const res = await api.post(`/closing/${m.date}/payouts/${id}/approve`);
            if (docModalGone(m)) return;
            applyReport(m, res.data);
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        m.payBusy = false;
        drawDay();
    }

    /** A purchase / consignor payout / wage paid from today's drawer or with other money: the drawer and the cash to send follow. */
    async function setDrawer(kind, id, fromDrawer) {
        const m = modal;
        try {
            const r = (await api.post(`/closing/${m.date}/${kind}/${id}`, { from_drawer: fromDrawer })).data;
            if (docModalGone(m)) return;
            const keepSend = m.editing && Number(m.form.cash_sent) === expectedSend(m);
            Object.assign(m, r);
            if (keepSend) m.form.cash_sent = String(expectedSend(m)); // the suggested amount follows
            load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        drawDay();
    }

    /** A plain one-page accounting sheet of the day, with what is being typed if the summary is being edited. */
    function printReport() {
        const m = modal;
        const c = m.closing;
        const src = m.editing ? { ...m.form, actual: m.form.actual } : { ...(c || {}), actual: c?.actual_accounts };
        const status = !c ? 'ยังไม่สรุป' : c.verified_at ? 'ยืนยันแล้ว' : 'สรุปแล้ว รอยืนยัน';
        printClosingReport(m, {
            weekday: data.weekdays[new Date(m.date + 'T00:00:00').getDay() || 7],
            status,
            send: {
                expected_send: m.editing || !c ? expectedSend(m) : Number(c.expected_send),
                float_kept: m.editing ? floatOf(m) : (c?.float_kept != null ? Number(c.float_kept) : null),
                cash_sent: src.cash_sent, sent_to: src.sent_to, receipt_no: src.receipt_no, note: src.note, actual: src.actual,
            },
        });
    }

    function diffText(diff) {
        if (!Number.isFinite(diff) || Math.abs(diff) < 0.005) return '· ตรงกับยอดที่ควรส่ง';
        return diff < 0 ? `· ส่งขาด ฿${money(-diff)} (ระบุหมายเหตุ)` : `· ส่งเกิน ฿${money(diff)} (ระบุหมายเหตุ)`;
    }

    async function save() {
        const m = modal;
        if (m.form.cash_sent === '' || !(Number(m.form.cash_sent) >= 0)) {
            m.error = 'กรุณากรอกจำนวนเงินสดที่ส่ง';
        } else {
            const missing = m.accounts.find((a) => m.form.actual[a.method] === '' || !(Number(m.form.actual[a.method]) >= 0));
            m.error = missing ? `กรุณากรอกยอดที่ได้รับจริง: ${missing.label} (ข้อ 2)` : null;
        }
        if (m.error) { drawDay(); return; }
        m.busy = true;
        drawDay();
        try {
            m.closing = (await api.post(`/closing/${m.date}`, {
                cash_sent: Number(m.form.cash_sent), float_kept: m.figures.drawer ? floatOf(m) : null, sent_to: m.form.sent_to, receipt_no: m.form.receipt_no, note: m.form.note,
                actual_accounts: Object.fromEntries(Object.entries(m.form.actual).map(([k, v]) => [k, Number(v)])),
            })).data;
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
