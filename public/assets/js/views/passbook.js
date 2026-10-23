import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

export async function render(container, { params, user }) {
    const options = (await api.get('/consignors/options')).data;
    let selectedId = params.id ? Number(params.id) : (options[0]?.id ?? null);
    // Admin check: sales of products changed to consignment later that are still the store's.
    const isAdmin = user?.role === 'admin';
    let check = null; // { rows, busy } for the selected consignor once checked

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

    function draw({ consignor, entries, balance }) {
        const totalIn = entries.reduce((s, e) => s + Number(e.amount_in), 0);
        const totalOut = entries.reduce((s, e) => s + Number(e.amount_out), 0);

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
            <div class="table-wrap"><table>
              <thead><tr><th>วันที่</th><th>รายการ</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th><th class="num">คงเหลือ</th></tr></thead>
              <tbody>
                ${entries.map((e) => `
                  <tr>
                    <td class="muted">${fmtDateTime(e.created_at)}</td>
                    <td><div>${escapeHtml(e.description)}</div><div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(e.ref_no)}</div></td>
                    <td class="num">${Number(e.amount_in) ? money(e.amount_in) : ''}</td>
                    <td class="num">${Number(e.amount_out) ? money(e.amount_out) : ''}</td>
                    <td class="num" style="font-weight:600">${money(e.balance_after)}</td>
                  </tr>
                `).join('')}
              </tbody>
              <tfoot>
                <tr style="border-top:2px solid var(--border);font-weight:700">
                  <td colspan="2">รวมทั้งสิ้น</td>
                  <td class="num">${money(totalIn)}</td>
                  <td class="num">${money(totalOut)}</td>
                  <td class="num">${money(balance)}</td>
                </tr>
              </tfoot>
            </table></div>
          </section>
          <section class="card card-pad" style="text-align:center">
            <div class="muted" style="font-size:12.5px">ยอดคงเหลือที่เบิกได้</div>
            <div style="font-size:30px;font-weight:700;margin:8px 0">฿ ${money(balance)}</div>
            <div style="width:140px;height:140px;margin:16px auto;background:var(--surface2);border:1px dashed var(--border);border-radius:12px;display:grid;place-items:center;color:var(--muted);font-size:12px">
              QR<br>สแกนดูยอดเอง
            </div>
            <button type="button" class="btn secondary" onclick="window.print()">พิมพ์สมุดคู่ฝาก</button>
            ${checkHtml()}
          </section>
        </div>`;

        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            selectedId = Number(e.target.value);
            check = null;
            load();
        });
        container.querySelector('#btn-check')?.addEventListener('click', runCheck);
        container.querySelector('#btn-claim')?.addEventListener('click', claim);
    }

    await load();
}
