import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';
import { printPassbook } from '../print-slip.js';

export async function render(container, { params, user }) {
    const options = (await api.get('/consignors/options')).data;
    let selectedId = params.id ? Number(params.id) : (options[0]?.id ?? null);
    // Admin check: sales of products changed to consignment later that are still the store's.
    const isAdmin = user?.role === 'admin';
    let check = null; // { rows, busy } for the selected consignor once checked
    // 'all' = every entry, 'daily' = one row per day, 'YYYY-MM-DD' = that day's entries
    let view = 'all';

    async function load() {
        if (!selectedId) {
            container.innerHTML = `<div class="empty">ยังไม่มีผู้ฝากขายในระบบ</div>`;
            return;
        }
        const res = await api.get(`/consignors/${selectedId}/passbook`);
        draw(res);
    }

    function checkHtml() {
        if (!isAdmin) return '';
        const head = `<button type="button" class="btn secondary" id="btn-check" style="margin-top:8px" ${check?.busy ? 'disabled' : ''}
            title="หายอดขายของสินค้าที่เปลี่ยนเป็นสินค้าฝากขายภายหลัง ซึ่งยังไม่เข้าบัญชีผู้ฝาก">${check?.busy ? 'กำลังตรวจสอบ...' : 'ตรวจสอบยอดการขาย'}</button>`;
        if (!check?.rows) return head;
        if (!check.rows.length) return `${head}<div class="pill ok" style="margin-top:12px">ยอดขายเข้าบัญชีครบแล้ว ไม่มีรายการค้าง</div>`;
        const sum = (k) => check.rows.reduce((s, r) => s + Number(r[k]), 0);
        return `${head}
          <div style="text-align:left;margin-top:14px;border-top:1px solid var(--border);padding-top:12px">
            <div style="font-weight:600;margin-bottom:4px">พบยอดขายที่ยังไม่เข้าบัญชี ${count(check.rows.length)} รายการ</div>
            <div class="muted" style="font-size:12px;margin-bottom:8px">ขายไปตอนสินค้ายังบันทึกเป็นของร้าน ก่อนเปลี่ยนเป็นสินค้าฝากขาย</div>
            <div class="table-wrap"><table>
              <thead><tr><th>สินค้า</th><th class="num">ชิ้น</th><th class="num">ยอดขาย</th><th class="num">ค่าฝาก</th><th class="num">ผู้ฝากได้</th></tr></thead>
              <tbody>${check.rows.map((r) => `<tr>
                <td>${escapeHtml(r.name)}<div class="muted" style="font-size:11.5px">${fmtDateTime(r.first_sale)}${r.first_sale !== r.last_sale ? ` – ${fmtDateTime(r.last_sale)}` : ''}</div></td>
                <td class="num">${count(r.qty)}</td><td class="num">${money(r.sale)}</td><td class="num">${money(r.fee)}</td>
                <td class="num">${money(Number(r.sale) - Number(r.fee))}</td></tr>`).join('')}</tbody>
              <tfoot><tr style="font-weight:700"><td>รวม</td><td class="num">${count(sum('qty'))}</td><td class="num">${money(sum('sale'))}</td>
                <td class="num">${money(sum('fee'))}</td><td class="num">${money(sum('sale') - sum('fee'))}</td></tr></tfoot>
            </table></div>
            <button type="button" class="btn" id="btn-claim" style="width:100%;margin-top:10px" ${check.busy ? 'disabled' : ''}>ดึงยอดขายเข้าบัญชีผู้ฝาก</button>
          </div>`;
    }

    async function runCheck() {
        check = { ...check, busy: true };
        await load();
        try {
            check = { rows: (await api.get(`/consignors/${selectedId}/unclaimed-sales`)).data, busy: false };
        } catch (e) {
            check = null;
            alertDialog(e.message, { tone: 'error' });
        }
        await load();
    }

    async function claim() {
        const net = check.rows.reduce((s, r) => s + Number(r.sale) - Number(r.fee), 0);
        const ok = await confirmDialog(
            `ลงบัญชียอดขาย ${count(check.rows.length)} รายการ และหักค่าฝากขาย — ยอดคงเหลือของผู้ฝากจะเพิ่มขึ้น ฿${money(net)} และยอดนี้จะออกจากกำไรของร้าน`,
            { title: 'ดึงยอดขายเข้าบัญชีผู้ฝาก?', okText: 'ดึงยอดขาย' }
        );
        if (!ok) return;
        check = { ...check, busy: true };
        await load();
        try {
            const r = (await api.post(`/consignors/${selectedId}/claim-sales`)).data;
            check = { rows: [], busy: false };
            await load();
            alertDialog(`ลงบัญชียอดขาย ${count(r.qty)} ชิ้น ฿${money(r.sale)} หักค่าฝากขาย ฿${money(r.fee)} แล้ว`, { title: 'ดึงยอดขายแล้ว' });
        } catch (e) {
            check = { ...check, busy: false };
            await load();
            alertDialog(e.message, { tone: 'error' });
        }
    }

    /** Entries grouped by day (oldest first): brought forward, in, out and the closing balance. */
    function byDay(entries) {
        const days = [];
        let prev = 0;
        for (const e of entries) {
            const key = String(e.created_at).slice(0, 10);
            let d = days[days.length - 1];
            if (!d || d.date !== key) {
                d = { date: key, opening: prev, in: 0, out: 0, count: 0, closing: prev };
                days.push(d);
            }
            d.in += Number(e.amount_in);
            d.out += Number(e.amount_out);
            d.count += 1;
            d.closing = Number(e.balance_after);
            prev = d.closing;
        }
        return days;
    }

    function entryRows(list) {
        return list.map((e) => `
                  <tr>
                    <td class="muted">${fmtDateTime(e.created_at)}</td>
                    <td><div>${escapeHtml(e.description)}</div><div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(e.ref_no)}</div></td>
                    <td class="num">${Number(e.amount_in) ? money(e.amount_in) : ''}</td>
                    <td class="num">${Number(e.amount_out) ? money(e.amount_out) : ''}</td>
                    <td class="num" style="font-weight:600">${money(e.balance_after)}</td>
                  </tr>`).join('');
    }

    const foot = (label, tin, tout, bal, extra = '') => `
              <tfoot>
                <tr style="border-top:2px solid var(--border);font-weight:700">
                  <td colspan="2">${label}</td>
                  <td class="num">${money(tin)}</td>
                  <td class="num">${money(tout)}</td>
                  <td class="num">${money(bal)}</td>${extra}
                </tr>
              </tfoot>`;

    function tableHtml(entries, balance) {
        const days = byDay(entries);
        if (view === 'daily') {
            const rows = [...days].reverse(); // newest day first
            return `
            <div class="table-wrap"><table>
              <thead><tr><th>วันที่</th><th class="num">ยกมา</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th><th class="num">คงเหลือสิ้นวัน</th><th></th></tr></thead>
              <tbody>${rows.map((d) => `
                <tr>
                  <td>${escapeHtml(fmtDate(d.date))}<div class="muted" style="font-size:11.5px">${count(d.count)} รายการ</div></td>
                  <td class="num muted">${money(d.opening)}</td>
                  <td class="num">${d.in ? money(d.in) : ''}</td>
                  <td class="num">${d.out ? money(d.out) : ''}</td>
                  <td class="num" style="font-weight:600">${money(d.closing)}</td>
                  <td style="text-align:right"><button type="button" class="btn secondary sm" data-day="${d.date}">ดูรายการ</button></td>
                </tr>`).join('') || '<tr><td colspan="6" class="empty">ยังไม่มีรายการ</td></tr>'}</tbody>
              ${foot(`รวมทั้งสิ้น ${count(days.length)} วัน`, days.reduce((s, d) => s + d.in, 0), days.reduce((s, d) => s + d.out, 0), balance, '<td></td>')}
            </table></div>`;
        }
        if (view !== 'all') {
            const day = days.find((d) => d.date === view);
            const list = entries.filter((e) => String(e.created_at).slice(0, 10) === view);
            // A day with no entries carries the balance of the last day before it.
            const opening = day ? day.opening : (days.filter((d) => d.date < view).pop()?.closing ?? 0);
            return `
            <div class="table-wrap"><table>
              <thead><tr><th>วันที่</th><th>รายการ</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th><th class="num">คงเหลือ</th></tr></thead>
              <tbody>
                <tr><td class="muted">${escapeHtml(fmtDate(view))}</td><td class="muted">ยอดยกมาจากวันก่อน</td><td></td><td></td><td class="num" style="font-weight:600">${money(opening)}</td></tr>
                ${entryRows(list) || '<tr><td colspan="5" class="empty">ไม่มีรายการในวันนี้</td></tr>'}
              </tbody>
              ${foot(`รวมวันที่ ${escapeHtml(fmtDate(view))}`, day?.in ?? 0, day?.out ?? 0, day?.closing ?? opening)}
            </table></div>`;
        }
        const totalIn = entries.reduce((s, e) => s + Number(e.amount_in), 0);
        const totalOut = entries.reduce((s, e) => s + Number(e.amount_out), 0);
        return `
            <div class="table-wrap"><table>
              <thead><tr><th>วันที่</th><th>รายการ</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th><th class="num">คงเหลือ</th></tr></thead>
              <tbody>${entryRows(entries)}
              </tbody>
              ${foot('รวมทั้งสิ้น', totalIn, totalOut, balance)}
            </table></div>`;
    }

    function draw({ consignor, entries, balance }) {
        const isDay = view !== 'all' && view !== 'daily';

        container.innerHTML = `
        <div class="two-col">
          <section class="card card-pad">
            <label class="field" style="margin-bottom:14px">เลือกผู้ฝากขาย
              <select id="sel-consignor">
                ${options.map((o) => `<option value="${o.id}" ${o.id === selectedId ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}
              </select>
            </label>
            <div style="border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:14px">
              <div style="font-weight:700;font-size:16px">${escapeHtml(consignor.name)}</div>
              <div class="muted" style="font-size:12.5px">${escapeHtml(consignor.code)} · ${escapeHtml(consignor.type)} · ${escapeHtml(consignor.pay_channel)}</div>
            </div>
            <div class="actions-row" style="margin-bottom:12px">
              <button type="button" class="tab-btn ${view === 'all' ? 'active' : ''}" data-view="all">ทุกรายการ</button>
              <button type="button" class="tab-btn ${view === 'daily' ? 'active' : ''}" data-view="daily">สรุปรายวัน</button>
              <label class="field" style="flex-direction:row;align-items:center;gap:6px">ดูวันที่
                <input type="date" id="f-day" value="${isDay ? escapeHtml(view) : ''}"></label>
              ${isDay ? '<button type="button" class="btn secondary sm" data-view="daily">← กลับไปสรุปรายวัน</button>' : ''}
            </div>
            ${tableHtml(entries, balance)}
          </section>
          <section class="card card-pad" style="text-align:center">
            <div class="muted" style="font-size:12.5px">ยอดคงเหลือที่เบิกได้</div>
            <div style="font-size:30px;font-weight:700;margin:8px 0">฿ ${money(balance)}</div>
            <div style="width:140px;height:140px;margin:16px auto;background:var(--surface2);border:1px dashed var(--border);border-radius:12px;display:grid;place-items:center;color:var(--muted);font-size:12px">
              QR<br>สแกนดูยอดเอง
            </div>
            <button type="button" class="btn secondary" id="btn-print">พิมพ์สมุดคู่ฝาก</button>
            ${checkHtml()}
          </section>
        </div>`;

        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            selectedId = Number(e.target.value);
            check = null;
            load();
        });
        const redraw = (v) => { view = v; draw({ consignor, entries, balance }); };
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => redraw(b.dataset.view)));
        container.querySelectorAll('[data-day]').forEach((b) => b.addEventListener('click', () => redraw(b.dataset.day)));
        container.querySelector('#f-day').addEventListener('change', (e) => redraw(e.target.value || 'all'));
        container.querySelector('#btn-print').addEventListener('click', () => printPassbook(consignor, entries, balance));
        container.querySelector('#btn-check')?.addEventListener('click', runCheck);
        container.querySelector('#btn-claim')?.addEventListener('click', claim);
    }

    await load();
}
