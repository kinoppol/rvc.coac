import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';

export async function render(container, { params }) {
    const options = (await api.get('/consignors/options')).data;
    let selectedId = params.id ? Number(params.id) : (options[0]?.id ?? null);

    async function load() {
        if (!selectedId) {
            container.innerHTML = `<div class="empty">ยังไม่มีผู้ฝากขายในระบบ</div>`;
            return;
        }
        const res = await api.get(`/consignors/${selectedId}/passbook`);
        draw(res);
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
          </section>
        </div>`;

        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            selectedId = Number(e.target.value);
            load();
        });
    }

    await load();
}
