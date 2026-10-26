import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, promptDialog } from '../dialog.js';

const SKIP_LABEL = { drawer: 'ปิดยอดลิ้นชัก', wages: 'จ่ายค่าแรง', payouts: 'จ่ายเงินผู้ฝากขาย', purchases: 'ซื้อสินค้าเข้าร้าน' };
const PAY_METHOD = { cash: 'เงินสด', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };

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
        if (!missing) return '';
        if (sk) {
            return `<div class="step-note muted" style="font-size:12.5px;margin-top:8px">⏭️ ข้ามไปก่อนโดย ${escapeHtml(sk.user_name || '-')} · ${fmtDateTime(sk.created_at)}${sk.note ? ` · ${escapeHtml(sk.note)}` : ''}
              ${mayAct ? `<button type="button" class="btn sm secondary no-print" data-unskip="${item}" style="margin-left:6px">ยกเลิกการข้าม</button>` : ''}
              ${can(perm) ? `<a class="btn sm secondary no-print" href="${href}" style="margin-left:6px">${label}</a>` : ''}</div>`;
        }
        return `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px">
            <span class="pill warn" style="font-size:12px">${missingText}</span>
            ${can(perm) ? `<a class="btn sm no-print" href="${href}">${label}</a>` : `<span class="muted no-print" style="font-size:12px">(ให้ผู้มีสิทธิ์ดำเนินการ)</span>`}
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
            ${line('เงินในลิ้นชักตอนเปิดร้าน', `฿ ${money(dr.opening_cash)}`)}
            ${line('+ ขายได้เป็นเงินสด', `฿ ${money(f.cash_sales)}`)}
            ${line('<b>เงินสดที่ควรมีในลิ้นชักตอนปิดยอด</b>', `<b>฿ ${money(dr.should_have)}</b>`)}
            ${dr.counted_cash !== null ? `
              ${line('<b>เงินที่มีอยู่จริงในลิ้นชัก</b> <span class="muted" style="font-size:12px">(นับตอนปิดยอด)</span>', `<b>฿ ${money(dr.counted_cash)}</b>`)}
              ${line('ส่วนต่าง', signed(dr.counted_cash - dr.should_have), isDiff(dr.counted_cash, dr.should_have) ? 'color:var(--bad);font-weight:600' : '')}` : ''}
            ${f.cash_out || f.payout_out || f.purchase_out ? `
              ${f.cash_out ? line('- จ่ายค่าแรงจากลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 4)</span>', `฿ ${money(f.cash_out)}`) : ''}
              ${f.payout_out ? line('- จ่ายเงินผู้ฝากขายจากลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 5)</span>', `฿ ${money(f.payout_out)}`) : ''}
              ${f.purchase_out ? line('- ซื้อสินค้าด้วยเงินในลิ้นชัก <span class="muted" style="font-size:12px">(ข้อ 6)</span>', `฿ ${money(f.purchase_out)}`) : ''}
              ${line(`<b>เงินสดคงเหลือในลิ้นชักหลังจ่าย</b>${dr.counted_cash === null ? ' <span class="muted" style="font-size:12px">(ยังไม่ได้นับ)</span>' : ''}`, `<b>฿ ${money(dr.after_pay)}</b>`)}` : ''}
            ${line('- คงไว้ในลิ้นชักเป็นเงินทอนวันถัดไป', `<span id="cl-float-v">฿ ${money(floatOf(m))}</span>`)}
            ${line('<b>เงินสดที่ต้องส่งวิทยาลัย</b> <span class="muted" style="font-size:12px">(ข้อ 7)</span>', `<b id="cl-send-v">฿ ${money(expectedSend(m))}</b>`)}`
            : line('เงินในลิ้นชักตอนเปิดร้าน', '<span class="muted">ไม่ได้ระบุ</span>');

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
                  <h3 style="margin:0 0 8px">2. เงินสดในลิ้นชัก</h3>
                  <table class="cl-t"><tbody>${drawerHtml}</tbody></table>
                  ${dr && dr.counted_cash !== null ? `<div class="muted" style="font-size:11.5px;margin-top:4px">ปิดยอดโดย ${escapeHtml(dr.closed_by_name || '-')} ${dr.closed_at ? fmtDateTime(dr.closed_at) : ''}</div>` : ''}
                  ${stepActions(m, 'drawer', !(dr && dr.counted_cash !== null), dr ? 'ยังไม่ได้นับเงินปิดยอดลิ้นชัก' : 'ไม่ได้ระบุเงินในลิ้นชัก / ยังไม่ปิดยอด')}
                </section>
              </div>

              <section class="card card-pad" style="margin-top:14px">
                <h3 style="margin:0 0 8px">3. เงินเข้าบัญชีที่รับโอน / QR-Code / บัตรเครดิต</h3>
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

              <div class="two-col" style="gap:14px;align-items:start;margin-top:14px">
                <section class="card card-pad">
                  <h3 style="margin:0 0 8px">4. การจ่ายค่าแรง</h3>
                  ${m.wages.rows.length ? `<table class="cl-t"><tbody>
                    ${m.wages.rows.map((w) => line(`${escapeHtml(w.employee_name || '-')} <span class="muted" style="font-size:11.5px">${escapeHtml(w.doc_no)} · ${w.drawer_date === m.date ? 'จ่ายจากลิ้นชัก' : w.method === 'cash' ? 'เงินสด' : 'โอน'}</span>`, `฿ ${money(w.amount)}`)).join('')}
                    ${line('<b>รวม</b>', `<b>฿ ${money(m.wages.total)}</b>`)}
                  </tbody></table>` : '<div class="muted">ไม่มีการจ่ายค่าแรงวันนี้</div>'}
                  ${stepActions(m, 'wages', !m.wages.rows.length, 'ยังไม่มีการจ่ายค่าแรง')}
                </section>

                <section class="card card-pad">
                  <h3 style="margin:0 0 8px">5. การจ่ายเงินค่าสินค้าฝากขายให้ผู้ฝากขาย</h3>
                  ${m.payouts.rows.length ? `<table class="cl-t"><tbody>
                    ${m.payouts.rows.map((w) => `<tr><td>${escapeHtml(w.consignor_code)} - ${escapeHtml(w.consignor_name)} <span class="muted" style="font-size:11.5px">${escapeHtml(w.doc_no)} · ${PAY_METHOD[w.method] || w.method}</span>
                      ${w.method === 'cash' ? `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:3px">
                        ${w.from_drawer ? '<span class="pill" style="font-size:11.5px">จ่ายจากเงินในลิ้นชักวันนี้</span>' : '<span class="pill ok" style="font-size:11.5px">ไม่ได้ใช้เงินจากลิ้นชักวันนี้</span>'}
                        ${w.flagged_by ? `<span class="muted" style="font-size:11px">ระบุโดย ${escapeHtml(w.flagged_by)}</span>` : ''}
                        ${m.can_submit ? `<button type="button" class="btn sm secondary no-print" data-wdrawer="${w.id}" data-on="${w.from_drawer ? 0 : 1}">${w.from_drawer ? 'ไม่ได้ใช้เงินจากลิ้นชัก' : 'จ่ายจากลิ้นชักวันนี้'}</button>` : ''}
                      </div>` : ''}</td>
                      <td class="num" style="vertical-align:top;${w.from_drawer ? '' : 'color:var(--muted)'}">฿ ${money(w.amount)}</td></tr>`).join('')}
                    ${line('<b>รวม</b>', `<b>฿ ${money(m.payouts.total)}</b>${m.payouts.cash ? ` <span class="muted" style="font-size:11.5px">(เงินสด ฿${money(m.payouts.cash)})</span>` : ''}`)}
                    ${m.payouts.from_drawer ? line('&nbsp;&nbsp;จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="font-size:12px">(หักในข้อ 2)</span>', `฿ ${money(m.payouts.from_drawer)}`) : ''}
                  </tbody></table>
                  ${m.payouts.cash ? '<div class="muted" style="font-size:12px;margin-top:6px">จ่ายเป็นเงินสดถือว่าจ่ายจากเงินในลิ้นชักของวันนั้น ถ้าใช้เงินอื่น กด <b>ไม่ได้ใช้เงินจากลิ้นชัก</b></div>' : ''}` : '<div class="muted">ไม่มีการจ่ายเงินผู้ฝากขายวันนี้</div>'}
                  ${m.payouts.pending_count ? `<div style="margin-top:8px"><span class="pill warn" style="font-size:12px">คำขอเบิกรอจ่าย ${m.payouts.pending_count} รายการ ฿${money(m.payouts.pending_amount)}</span>
                    ${can('withdraw.create') ? '<a class="btn sm no-print" href="#/withdraw" style="margin-left:6px">ไปจ่ายเงิน</a>' : ''}</div>` : ''}
                  ${stepActions(m, 'payouts', !m.payouts.rows.length, 'ยังไม่มีการจ่ายเงินผู้ฝากขาย')}
                </section>
              </div>

              <section class="card card-pad" style="margin-top:14px">
                <h3 style="margin:0 0 8px">6. การเบิกเงินซื้อสินค้าอื่น ๆ เข้าร้าน</h3>
                ${m.purchases.rows.length ? `<table class="cl-t"><tbody>
                  ${m.purchases.rows.map((p) => `<tr><td>${escapeHtml(p.supplier_name || '-')} <span class="muted" style="font-size:11.5px">${escapeHtml(p.doc_no)}${p.receipt_no ? ` · ใบเสร็จ ${escapeHtml(p.receipt_no)}` : ''} · ${p.funding === 'profit' ? 'จากกำไร' : 'จากงบลงทุน'}</span>
                      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:3px">
                        ${p.from_drawer ? '<span class="pill" style="font-size:11.5px">จ่ายจากเงินในลิ้นชักวันนี้</span>' : '<span class="pill ok" style="font-size:11.5px">ไม่ได้ใช้เงินจากลิ้นชักวันนี้</span>'}
                        ${p.flagged_by ? `<span class="muted" style="font-size:11px">ระบุโดย ${escapeHtml(p.flagged_by)}</span>` : ''}
                        ${m.can_submit ? `<button type="button" class="btn sm secondary no-print" data-pdrawer="${p.id}" data-on="${p.from_drawer ? 0 : 1}">${p.from_drawer ? 'ไม่ได้ใช้เงินจากลิ้นชัก' : 'จ่ายจากลิ้นชักวันนี้'}</button>` : ''}
                      </div></td>
                    <td class="num" style="vertical-align:top;${p.from_drawer ? '' : 'color:var(--muted)'}">฿ ${money(p.total)}</td></tr>`).join('')}
                  ${line('<b>รวม</b>', `<b>฿ ${money(m.purchases.total)}</b>`)}
                  ${line('&nbsp;&nbsp;จ่ายจากเงินในลิ้นชักวันนี้ <span class="muted" style="font-size:12px">(หักในข้อ 2)</span>', `฿ ${money(m.purchases.from_drawer)}`)}
                </tbody></table>
                <div class="muted" style="font-size:12px;margin-top:6px">ปกติถือว่าจ่ายจากเงินในลิ้นชักของวันนั้น ถ้าใช้เงินอื่น (เช่น เงินที่เบิกจากกำไรวันก่อน) กด <b>ไม่ได้ใช้เงินจากลิ้นชัก</b></div>` : '<div class="muted">ไม่มีการซื้อสินค้าเข้าร้านวันนี้</div>'}
                ${stepActions(m, 'purchases', !m.purchases.rows.length, 'ยังไม่มีการบันทึกซื้อสินค้า')}
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
        wireDay();
    }

    function sendHtml(m) {
        const f = m.figures;
        const c = m.closing;
        const row = (label, v, style = '') => `<tr><td>${label}</td><td class="num" style="${style}">${v}</td></tr>`;
        if (m.editing) {
            return `
            <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
              ${f.drawer ? `<label class="field">คงไว้ในลิ้นชักเป็นเงินทอนวันถัดไป (บาท) <input data-f="float_kept" type="number" min="0" step="0.25" value="${escapeHtml(m.form.float_kept)}">
                <span class="muted" style="font-size:11.5px;font-weight:400">เงินตั้งต้นวันนี้ ฿${money(f.drawer.opening_cash)} · เหลือในลิ้นชัก ฿${money(f.drawer.after_pay)}</span></label>` : ''}
              <label class="field">จำนวนเงินสดที่ส่ง (บาท) * <input data-f="cash_sent" type="number" min="0" step="0.25" value="${escapeHtml(m.form.cash_sent)}">
                <span class="muted" style="font-size:11.5px;font-weight:400">ควรส่ง <span id="cl-expected">฿${money(expectedSend(m))}</span> <span id="cl-diff">${diffText(Number(m.form.cash_sent) - expectedSend(m))}</span></span></label>
              <label class="field">ส่งให้ <input data-f="sent_to" maxlength="150" value="${escapeHtml(m.form.sent_to)}"></label>
              <label class="field">เลขที่ใบเสร็จ / ใบรับเงิน <input data-f="receipt_no" maxlength="60" value="${escapeHtml(m.form.receipt_no)}"></label>
            </div>
            <label class="field" style="margin-top:10px">หมายเหตุ <span class="muted" style="font-weight:400">(จำเป็นถ้ายอดเงินไม่ตรง)</span><textarea data-f="note" rows="2" maxlength="500">${escapeHtml(m.form.note)}</textarea></label>
            <div class="muted" style="font-size:12px;margin-top:6px">ยอดที่ควรส่ง = เงินที่นับได้ตอนปิดยอดลิ้นชัก (ถ้ายังไม่ปิดยอด ใช้เงินที่ควรมี) หักค่าแรง เงินผู้ฝากขาย และค่าสินค้าที่จ่ายจากลิ้นชัก แล้วหักเงินที่คงไว้เป็นเงินทอนวันถัดไป (ดูข้อ 2)</div>
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
        m.el.querySelectorAll('[data-wdrawer]').forEach((b) => b.addEventListener('click', () => setDrawer('payouts', Number(b.dataset.wdrawer), b.dataset.on === '1')));
        m.el.querySelectorAll('[data-unskip]').forEach((b) => b.addEventListener('click', () => skip(b.dataset.unskip, false)));
        m.el.querySelectorAll('a[href^="#/"]').forEach((a) => a.addEventListener('click', closeDay));
        m.el.querySelector('#cl-save')?.addEventListener('click', save);
        m.el.querySelector('#cl-cancel')?.addEventListener('click', () => { m.editing = false; m.error = null; drawDay(); });
        m.el.querySelector('#cl-edit')?.addEventListener('click', () => { startEdit(); drawDay(); });
        m.el.querySelector('#cl-verify')?.addEventListener('click', verify);
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

    /** A purchase / consignor payout paid from today's drawer or with other money: the drawer and the cash to send follow. */
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

    function printReport() {
        const body = modal.el.querySelector('#cl-report').cloneNode(true);
        body.querySelectorAll('.no-print, button, input, textarea').forEach((x) => {
            if (x.tagName === 'INPUT' || x.tagName === 'TEXTAREA') x.replaceWith(document.createTextNode(x.value || '-'));
            else x.remove();
        });
        const title = modal.el.querySelector('.modal-head h2').textContent.trim();
        const css = new URL('assets/css/app.css', location.href.split('#')[0]).href;
        const w = window.open('', '_blank');
        if (!w) return;
        w.document.write(`<!doctype html><html lang="th"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title>
          <link rel="stylesheet" href="${css}"><style>body{padding:24px;background:#fff}.card{break-inside:avoid;box-shadow:none}.cl-t{width:100%}.cl-t td{padding:3px 0}</style></head>
          <body><h2 style="margin:0 0 4px">${escapeHtml(title)}</h2>${body.innerHTML}
          <div style="display:flex;justify-content:space-around;margin-top:48px;text-align:center"><div>ลงชื่อ ................................<br>ผู้สรุปบัญชี</div><div>ลงชื่อ ................................<br>ผู้รับเงิน</div></div></body></html>`);
        w.document.close();
        setTimeout(() => w.print(), 500);
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
            m.error = missing ? `กรุณากรอกยอดที่ได้รับจริง: ${missing.label} (ข้อ 3)` : null;
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
