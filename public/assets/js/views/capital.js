import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, todayISO } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

/**
 * งบลงทุน: purchases bought with invested money (counted automatically) plus other money
 * invested, less money withdrawn from the profit, month by month next to the net profit.
 * This page records the withdrawals and other investments.
 */
export async function render(container) {
    let from = `${todayISO().slice(0, 4)}-01-01`;
    let to = todayISO();
    let data = null;
    let busy = false;
    let error = null;
    const form = { entry_date: todayISO(), type: 'withdraw', investor: '', amount: '', method: 'transfer', note: '' };

    async function load() {
        data = (await api.get(`/capital?from=${from}&to=${to}`)).data;
        draw();
    }

    function draw() {
        const d = data;
        container.innerHTML = `
        <div class="hint"><div class="dot">i</div><div>
          <b>งบลงทุน</b> = เงินซื้อของเข้ากิจการทุกรายการ (ยกเว้นใบซื้อที่ระบุว่า "ซื้อด้วยเงินกำไรของกิจการ") + เงินลงทุนอื่น − เงินที่เบิกออกจากกำไร ·
          ใบซื้อนับเข้างบลงทุนให้อัตโนมัติ หน้านี้ใช้บันทึก<b>การเบิกเงินออกจากกำไร</b> และเงินลงทุนอื่นที่ไม่ใช่การซื้อของ
        </div></div>
        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">งบลงทุนยกมา</div><div class="val">฿ ${money(d.opening)}</div><div class="sub">ก่อน ${escapeHtml(fmtDate(d.from))}</div></div>
          <div class="kpi"><div class="lbl">ซื้อของด้วยเงินลงทุน (ช่วงนี้)</div><div class="val">฿ ${money(d.capital_purchases)}</div>
            <div class="sub">ลงทุนอื่น ฿ ${money(d.other_invest)}</div></div>
          <div class="kpi"><div class="lbl">เบิกเงินออกจากกำไร (ช่วงนี้)</div><div class="val">฿ ${money(d.profit_withdrawn)}</div>
            <div class="sub">กำไรสุทธิช่วงนี้ ฿ ${money(d.net_profit)}</div></div>
          <div class="kpi"><div class="lbl">งบลงทุนคงเหลือ</div><div class="val">฿ ${money(d.closing)}</div>
            <div class="sub">ณ ${escapeHtml(fmtDate(d.to))} · กำไรสะสมคงเหลือ ฿ ${money(d.retained_profit)}</div></div>
        </div>

        <section class="card">
          <div class="card-head"><h2>สรุปรายเดือน: งบลงทุนและกำไรสุทธิ</h2></div>
          <div class="table-wrap"><table>
            <thead><tr><th>เดือน</th><th class="num">ซื้อของด้วยเงินลงทุน</th><th class="num">ลงทุนอื่น</th><th class="num">เบิกออกจากกำไร</th>
              <th class="num">งบลงทุนคงเหลือ</th><th class="num">กำไรสุทธิ</th><th class="num">ซื้อของด้วยเงินกำไร</th><th class="num">กำไรสะสมคงเหลือ (หลังเบิก)</th></tr></thead>
            <tbody>${d.months.map((m, i) => `<tr style="${i === 0 ? 'color:var(--muted)' : ''}">
              <td>${escapeHtml(m.period)}</td>
              ${['capital_purchases', 'other_invest', 'profit_withdrawn'].map((k) => `<td class="num">${m[k] === null ? '' : money(m[k])}</td>`).join('')}
              <td class="num" style="font-weight:600">${money(m.capital_balance)}</td>
              <td class="num" style="${Number(m.net_profit) < 0 ? 'color:var(--bad)' : ''}">${m.net_profit === null ? '' : money(m.net_profit)}</td>
              <td class="num">${m.profit_purchases === null ? '' : money(m.profit_purchases)}</td>
              <td class="num" style="font-weight:600;${Number(m.retained_profit) < 0 ? 'color:var(--bad)' : ''}">${money(m.retained_profit)}</td></tr>`).join('')}</tbody>
          </table></div>
          <div class="muted" style="font-size:12px;padding:10px 16px">กำไรสุทธิ = รายได้ของร้าน − รายจ่าย (ต้นทุนสินค้าที่ขาย ค่าวัสดุ ค่าแรง) ตามรายงานผลประกอบการ ·
            กำไรสะสมคงเหลือ = กำไรสุทธิสะสม − เงินที่เบิกออกจากกำไร</div>
        </section>

        <section class="card card-pad">
          <h2 style="margin-bottom:12px">บันทึกการเบิกเงินออกจากกำไร / ลงทุนเพิ่ม</h2>
          <div class="form-grid">
            <label class="field">ประเภท
              <select id="c-type">${Object.entries(d.types).map(([k, v]) => `<option value="${k}" ${k === form.type ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
            <label class="field">วันที่ * <input type="date" id="c-date" value="${escapeHtml(form.entry_date)}" max="${todayISO()}"></label>
            <label class="field">${form.type === 'withdraw' ? 'ผู้รับเงิน' : 'ผู้ลงทุน / แหล่งเงิน'} *
              <input id="c-investor" list="c-investors" maxlength="191" value="${escapeHtml(form.investor)}" placeholder="เช่น วิทยาลัย, สหกรณ์ร้านค้า, ชื่อสมาชิก">
              <datalist id="c-investors">${d.investors.map((n) => `<option value="${escapeHtml(n)}">`).join('')}</datalist></label>
            <label class="field">จำนวนเงิน (บาท) * <input id="c-amount" type="number" min="0" step="0.01" value="${escapeHtml(form.amount)}"></label>
            <label class="field">วิธีรับ/จ่ายเงิน
              <select id="c-method">${Object.entries(d.methods).map(([k, v]) => `<option value="${k}" ${k === form.method ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
            <label class="field">หมายเหตุ <input id="c-note" maxlength="200" value="${escapeHtml(form.note)}" placeholder="${form.type === 'withdraw' ? 'เช่น ส่งคืนเงินลงทุนให้วิทยาลัย' : 'เช่น เงินทุนตั้งต้นที่ยังไม่ได้ซื้อของ'}"></label>
          </div>
          ${error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(error)}</div>` : ''}
          <div class="muted" style="font-size:12.5px;margin-top:10px">เบิกเงินออกจากกำไร: งบลงทุนคงเหลือลดลงตามจำนวนนี้ · ลงทุนเพิ่ม: ใช้กับเงินลงทุนที่ไม่ได้ผ่านใบซื้อ (ใบซื้อนับให้อัตโนมัติแล้ว ไม่ต้องบันทึกซ้ำ)</div>
          <button type="button" class="btn" id="c-save" style="margin-top:12px;min-width:220px" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึก'}</button>
        </section>

        <section class="card">
          <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <h2>รายการเบิกเงินออกจากกำไร / ลงทุนเพิ่ม (${count(d.rows.length)})</h2>
            <div class="report-row">
              <label>ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(from)}"></label>
              <label>ถึง <input type="date" id="f-to" value="${escapeHtml(to)}"></label>
            </div>
          </div>
          <div class="table-wrap" style="max-height:480px"><table>
            <thead><tr><th>เลขที่</th><th>วันที่</th><th>ประเภท</th><th>ผู้รับเงิน / ผู้ลงทุน</th><th>วิธี</th><th class="num">จำนวนเงิน</th><th>หมายเหตุ</th><th></th></tr></thead>
            <tbody>${d.rows.map((e) => {
                const cancelled = e.cancelled_at !== null;
                return `<tr style="${cancelled ? 'opacity:.55' : ''}">
                  <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(e.doc_no)}</td>
                  <td>${escapeHtml(fmtDate(e.entry_date))}</td>
                  <td><span class="pill ${e.type === 'invest' ? 'ok' : 'warn'}">${escapeHtml(d.types[e.type])}</span></td>
                  <td>${escapeHtml(e.investor)}</td>
                  <td>${escapeHtml(d.methods[e.method] || e.method)}</td>
                  <td class="num" style="${cancelled ? 'text-decoration:line-through' : 'font-weight:600'}">${e.type === 'withdraw' ? '−' : ''}${money(e.amount)}</td>
                  <td style="font-size:12.5px">${escapeHtml(e.note || '')}${cancelled ? `<div><span class="pill bad">ยกเลิกแล้ว</span> <span class="muted">${escapeHtml(e.cancel_reason || '')}</span></div>` : ''}</td>
                  <td style="text-align:right">${cancelled ? '' : `<button type="button" class="btn secondary sm" data-cancel="${e.id}">ยกเลิก</button>`}</td>
                </tr>`;
            }).join('') || '<tr><td colspan="8" class="empty">ไม่มีรายการในช่วงนี้</td></tr>'}</tbody>
          </table></div>
        </section>`;
        wire();
    }

    function wire() {
        const $ = (s) => container.querySelector(s);
        [['#c-type', 'type'], ['#c-date', 'entry_date'], ['#c-investor', 'investor'], ['#c-amount', 'amount'], ['#c-method', 'method'], ['#c-note', 'note']]
            .forEach(([sel, key]) => $(sel).addEventListener('input', (e) => { form[key] = e.target.value; }));
        $('#c-type').addEventListener('change', draw); // labels follow the type
        $('#c-save').addEventListener('click', save);
        $('#f-from').addEventListener('change', (e) => { if (e.target.value) { from = e.target.value; load(); } });
        $('#f-to').addEventListener('change', (e) => { if (e.target.value) { to = e.target.value; load(); } });
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancel(b.dataset.cancel)));
    }

    async function save() {
        error = null;
        const amount = Number(form.amount);
        if (!form.investor.trim()) { error = 'กรุณาระบุผู้รับเงิน / ผู้ลงทุน'; draw(); return; }
        if (!(amount > 0)) { error = 'กรุณาระบุจำนวนเงิน'; draw(); return; }
        const ok = await confirmDialog(`${data.types[form.type]} ${form.investor.trim()} ฿${money(amount)} (${data.methods[form.method]})`, { title: 'ยืนยันการบันทึก?', okText: 'บันทึก' });
        if (!ok) return;
        busy = true;
        draw();
        try {
            await api.post('/capital', { ...form, amount });
            Object.assign(form, { amount: '', note: '' });
            busy = false;
            await load();
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    async function cancel(id) {
        const e = data.rows.find((x) => String(x.id) === String(id));
        const reason = await promptDialog(`${e.doc_no} · ${e.investor} ฿${money(e.amount)}`, { title: 'ยกเลิกรายการเงินลงทุน', okText: 'ยกเลิกรายการ', placeholder: 'เหตุผลที่ยกเลิก', required: true, multiline: true, danger: true });
        if (reason === null) return;
        try {
            await api.post(`/capital/${id}/cancel`, { reason });
            await load();
        } catch (err) { alertDialog(err.message, { tone: 'error' }); }
    }

    await load();
}
