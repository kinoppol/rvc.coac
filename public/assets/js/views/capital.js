import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, todayISO } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

/**
 * เงินลงทุน: record capital put into the business (or taken back out). It is not
 * income, so it never counts as profit; รายงานงบลงทุน and ผลประกอบการ show it.
 */
export async function render(container) {
    let from = `${todayISO().slice(0, 4)}-01-01`;
    let to = todayISO();
    let data = null;
    let busy = false;
    let error = null;
    const form = { entry_date: todayISO(), type: 'invest', investor: '', amount: '', method: 'transfer', note: '' };

    async function load() {
        data = (await api.get(`/capital?from=${from}&to=${to}`)).data;
        draw();
    }

    function draw() {
        const d = data;
        container.innerHTML = `
        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">เงินลงทุนยกมา</div><div class="val">฿ ${money(d.opening)}</div><div class="sub">ก่อน ${escapeHtml(fmtDate(d.from))}</div></div>
          <div class="kpi"><div class="lbl">ลงทุนเพิ่มในช่วงนี้</div><div class="val">฿ ${money(d.invested)}</div><div class="sub">${escapeHtml(fmtDate(d.from))} – ${escapeHtml(fmtDate(d.to))}</div></div>
          <div class="kpi"><div class="lbl">ถอนทุนคืนในช่วงนี้</div><div class="val">฿ ${money(d.withdrawn)}</div><div class="sub">&nbsp;</div></div>
          <div class="kpi"><div class="lbl">เงินลงทุนคงเหลือ</div><div class="val">฿ ${money(d.closing)}</div><div class="sub">ณ ${escapeHtml(fmtDate(d.to))}</div></div>
        </div>

        <div class="two-col">
          <section class="card card-pad">
            <h2 style="margin-bottom:12px">บันทึกเงินลงทุน</h2>
            <div class="form-grid">
              <label class="field">ประเภท
                <select id="c-type">${Object.entries(d.types).map(([k, v]) => `<option value="${k}" ${k === form.type ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
              <label class="field">วันที่ * <input type="date" id="c-date" value="${escapeHtml(form.entry_date)}" max="${todayISO()}"></label>
              <label class="field" style="grid-column:1/-1">ผู้ลงทุน / แหล่งเงินทุน *
                <input id="c-investor" list="c-investors" maxlength="191" value="${escapeHtml(form.investor)}" placeholder="เช่น วิทยาลัย, สหกรณ์ร้านค้า, ชื่อสมาชิก">
                <datalist id="c-investors">${d.investors.map((n) => `<option value="${escapeHtml(n)}">`).join('')}</datalist></label>
              <label class="field">จำนวนเงิน (บาท) * <input id="c-amount" type="number" min="0" step="0.01" value="${escapeHtml(form.amount)}"></label>
              <label class="field">วิธีรับ/จ่ายเงิน
                <select id="c-method">${Object.entries(d.methods).map(([k, v]) => `<option value="${k}" ${k === form.method ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
              <label class="field" style="grid-column:1/-1">หมายเหตุ <input id="c-note" maxlength="200" value="${escapeHtml(form.note)}" placeholder="เช่น ทุนตั้งต้นเปิดร้าน, เพิ่มทุนซื้อตู้แช่"></label>
            </div>
            ${error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(error)}</div>` : ''}
            <div class="muted" style="font-size:12.5px;margin-top:10px">เงินลงทุนไม่ใช่รายได้ จึงไม่นับเป็นกำไร · ดูภาพรวมได้ที่รายงานสรุป › งบลงทุน และผลประกอบการ</div>
            <button type="button" class="btn" id="c-save" style="width:100%;margin-top:12px" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึก'}</button>
          </section>

          <section class="card card-pad">
            <h2 style="margin-bottom:10px">เงินลงทุนแยกตามผู้ลงทุน <span class="muted" style="font-size:13px;font-weight:400">ณ ${escapeHtml(fmtDate(d.to))}</span></h2>
            <div class="table-wrap"><table>
              <thead><tr><th>ผู้ลงทุน</th><th class="num">ลงทุน</th><th class="num">ถอนคืน</th><th class="num">คงเหลือ</th></tr></thead>
              <tbody>${d.by_investor.map((r) => `<tr><td>${escapeHtml(r.investor)}</td><td class="num">${money(r.invested)}</td><td class="num">${money(r.withdrawn)}</td><td class="num" style="font-weight:600">${money(r.balance)}</td></tr>`).join('')
                || '<tr><td colspan="4" class="empty">ยังไม่มีเงินลงทุน</td></tr>'}</tbody>
            </table></div>
          </section>
        </div>

        <section class="card">
          <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <h2>รายการเงินลงทุน (${count(d.rows.length)})</h2>
            <div class="report-row">
              <label>ตั้งแต่ <input type="date" id="f-from" value="${escapeHtml(from)}"></label>
              <label>ถึง <input type="date" id="f-to" value="${escapeHtml(to)}"></label>
            </div>
          </div>
          <div class="table-wrap" style="max-height:480px"><table>
            <thead><tr><th>เลขที่</th><th>วันที่</th><th>ประเภท</th><th>ผู้ลงทุน</th><th>วิธี</th><th class="num">จำนวนเงิน</th><th>หมายเหตุ</th><th></th></tr></thead>
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
        $('#c-save').addEventListener('click', save);
        $('#f-from').addEventListener('change', (e) => { if (e.target.value) { from = e.target.value; load(); } });
        $('#f-to').addEventListener('change', (e) => { if (e.target.value) { to = e.target.value; load(); } });
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancel(b.dataset.cancel)));
    }

    async function save() {
        error = null;
        const amount = Number(form.amount);
        if (!form.investor.trim()) { error = 'กรุณาระบุผู้ลงทุน / แหล่งเงินทุน'; draw(); return; }
        if (!(amount > 0)) { error = 'กรุณาระบุจำนวนเงิน'; draw(); return; }
        const ok = await confirmDialog(`${data.types[form.type]} ${form.investor.trim()} ฿${money(amount)} (${data.methods[form.method]})`, { title: 'ยืนยันบันทึกเงินลงทุน?', okText: 'บันทึก' });
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
