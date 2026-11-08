import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime } from '../util.js';
import { printPassbook } from '../print-slip.js';

const VIEWS = [['day', 'รายวัน'], ['week', 'รายสัปดาห์'], ['month', 'รายเดือน'], ['all', 'ทั้งหมด']];

// Dates here are plain calendar days (YYYY-MM-DD): worked out in UTC so no time zone can shift them.
const asDate = (d) => new Date(`${String(d).slice(0, 10)}T00:00:00Z`);
const iso = (d) => d.toISOString().slice(0, 10);
const thai = (d, opts) => d.toLocaleDateString('th-TH', { timeZone: 'UTC', ...opts });

function mondayOf(day) {
    const d = asDate(day);
    d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7));
    return iso(d);
}

function periodKey(view, day) {
    if (view === 'day') return String(day).slice(0, 10);
    if (view === 'week') return mondayOf(day);
    if (view === 'month') return String(day).slice(0, 7);
    return 'all';
}

function periodLabel(view, key) {
    if (view === 'day') return thai(asDate(key), { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
    if (view === 'month') return thai(asDate(`${key}-01`), { month: 'long', year: 'numeric' });
    if (view === 'all') return 'ทั้งหมด';
    const end = asDate(key);
    end.setUTCDate(end.getUTCDate() + 6);
    const short = { day: 'numeric', month: 'short', year: 'numeric' };
    return `${thai(asDate(key), short)} – ${thai(end, short)}`;
}

/** Periods newest first: what each product sold for, and the other ledger movements of that period. */
function summarize(view, sales, entries) {
    const periods = new Map();
    const period = (key) => {
        if (!periods.has(key)) periods.set(key, { key, products: new Map(), others: [], qty: 0, value: 0, fee: 0 });
        return periods.get(key);
    };
    for (const s of sales) {
        const p = period(periodKey(view, s.sale_date));
        const row = p.products.get(s.product_id) || { name: s.name, barcode: s.barcode, qty: 0, value: 0, fee: 0 };
        row.qty += Number(s.qty);
        row.value += Number(s.value);
        row.fee += Number(s.fee);
        p.products.set(s.product_id, row);
        p.qty += Number(s.qty);
        p.value += Number(s.value);
        p.fee += Number(s.fee);
    }
    for (const e of entries) {
        if (e.type === 'sale' || e.type === 'fee' || !(Number(e.amount_in) || Number(e.amount_out))) continue;
        period(periodKey(view, e.entry_date)).others.push(e);
    }
    return [...periods.values()].sort((a, b) => b.key.localeCompare(a.key));
}

function periodHtml(view, p, open) {
    const products = [...p.products.values()].sort((a, b) => b.value - a.value || a.name.localeCompare(b.name, 'th'));
    return `
      <details class="card" data-period style="padding:0;margin-top:10px;box-shadow:none"${open ? ' open' : ''}>
        <summary style="padding:12px 14px;cursor:pointer">
          <span style="font-weight:700">${escapeHtml(periodLabel(view, p.key))}</span>
          <div class="muted" style="display:flex;flex-wrap:wrap;gap:2px 14px;font-size:12.5px;margin-top:4px;padding-left:16px">
            <span>ขาย ${count(p.qty)} ชิ้น</span>
            <span>ขายได้ ฿${money(p.value)}</span>
            <span>ค่าฝากขาย ฿${money(p.fee)}</span>
            <b style="color:var(--text)">เงินที่ได้ ฿${money(p.value - p.fee)}</b>
          </div>
        </summary>
        <div style="padding:0 14px 12px">
          ${products.length ? `
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th class="num">จำนวน (ชิ้น)</th><th class="num">ขายได้ (บาท)</th><th class="num">ค่าฝากขาย (บาท)</th><th class="num">เงินที่ได้ (บาท)</th></tr></thead>
            <tbody>
              ${products.map((r) => `
                <tr>
                  <td><div>${escapeHtml(r.name)}</div>${r.barcode ? `<div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(r.barcode)}</div>` : ''}</td>
                  <td class="num">${count(r.qty)}</td>
                  <td class="num">${money(r.value)}</td>
                  <td class="num">${money(r.fee)}</td>
                  <td class="num" style="font-weight:600">${money(r.value - r.fee)}</td>
                </tr>`).join('')}
            </tbody>
            <tfoot>
              <tr style="border-top:2px solid var(--border);font-weight:700">
                <td>รวม</td>
                <td class="num">${count(p.qty)}</td>
                <td class="num">${money(p.value)}</td>
                <td class="num">${money(p.fee)}</td>
                <td class="num">${money(p.value - p.fee)}</td>
              </tr>
            </tfoot>
          </table></div>` : '<div class="muted" style="font-size:13px">ไม่มีการขายในช่วงนี้</div>'}
          ${p.others.length ? `
          <div style="font-weight:600;font-size:13px;margin-top:12px">รายการอื่นในบัญชี</div>
          <div class="table-wrap"><table>
            <thead><tr><th>วันที่</th><th>รายการ</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th></tr></thead>
            <tbody>
              ${p.others.map((e) => `
                <tr>
                  <td class="muted">${fmtDateTime(e.created_at)}</td>
                  <td><div>${escapeHtml(e.description)}</div><div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(e.ref_no)}</div></td>
                  <td class="num">${Number(e.amount_in) ? money(e.amount_in) : ''}</td>
                  <td class="num">${Number(e.amount_out) ? money(e.amount_out) : ''}</td>
                </tr>`).join('')}
            </tbody>
          </table></div>` : ''}
        </div>
      </details>`;
}

/** Consignor view: the signed-in person's own passbook (read-only). */
export async function render(container) {
    const { consignor, entries, balance, sales = [] } = await api.get('/my/passbook');
    const totalIn = entries.reduce((s, e) => s + Number(e.amount_in), 0);
    const totalOut = entries.reduce((s, e) => s + Number(e.amount_out), 0);
    let view = 'month';

    container.innerHTML = `
    <div class="two-col">
      <div style="min-width:0">
        <section class="card card-pad">
          <div style="border-bottom:1px solid var(--border);padding-bottom:14px;margin-bottom:14px">
            <div style="font-weight:700;font-size:16px">${escapeHtml(consignor.name)}</div>
            <div class="muted" style="font-size:12.5px">${escapeHtml(consignor.code)} · ${escapeHtml(consignor.type)} · ${escapeHtml(consignor.pay_channel)}</div>
          </div>
          <div class="tabs" role="group" aria-label="สรุปตามช่วงเวลา" id="period-tabs">
            ${VIEWS.map(([k, l]) => `<button type="button" class="tab-btn" data-view="${k}">${l}</button>`).join('')}
          </div>
          <div id="period-list"></div>
        </section>
        <details class="card card-pad" style="margin-top:14px">
          <summary style="cursor:pointer;font-weight:600">รายการเดินบัญชี (${count(entries.length)} รายการ)</summary>
          <div class="table-wrap" style="margin-top:12px"><table>
            <thead><tr><th>วันที่</th><th>รายการ</th><th class="num">รับ (บาท)</th><th class="num">จ่าย (บาท)</th><th class="num">คงเหลือ</th></tr></thead>
            <tbody>
              ${entries.map((e) => `
                <tr>
                  <td class="muted">${fmtDateTime(e.created_at)}</td>
                  <td><div>${escapeHtml(e.description)}</div><div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(e.ref_no)}</div></td>
                  <td class="num">${Number(e.amount_in) ? money(e.amount_in) : ''}</td>
                  <td class="num">${Number(e.amount_out) ? money(e.amount_out) : ''}</td>
                  <td class="num" style="font-weight:600">${money(e.balance_after)}</td>
                </tr>`).join('')}
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
        </details>
      </div>
      <section class="card card-pad" style="text-align:center">
        <div class="muted" style="font-size:12.5px">ยอดคงเหลือที่เบิกได้</div>
        <div style="font-size:30px;font-weight:700;margin:8px 0">฿ ${money(balance)}</div>
        <div class="muted" style="font-size:12.5px;margin-bottom:14px">ติดต่อเบิกเงินได้ที่ร้านค้าสหการ</div>
        <button type="button" class="btn secondary" id="btn-print">พิมพ์สมุดคู่ฝาก</button>
      </section>
    </div>`;

    const tabs = container.querySelector('#period-tabs');
    const list = container.querySelector('#period-list');
    const draw = () => {
        tabs.querySelectorAll('[data-view]').forEach((b) => {
            b.classList.toggle('active', b.dataset.view === view);
            b.setAttribute('aria-pressed', String(b.dataset.view === view));
        });
        const periods = summarize(view, sales, entries);
        // The latest period starts open; older ones open on a tap.
        list.innerHTML = periods.length
            ? periods.map((p, i) => periodHtml(view, p, i === 0)).join('')
            : '<div class="empty" style="margin-top:12px">ยังไม่มีรายการ</div>';
    };
    tabs.addEventListener('click', (e) => {
        const b = e.target.closest('[data-view]');
        if (!b) return;
        view = b.dataset.view;
        draw();
    });
    draw();
    container.querySelector('#btn-print').addEventListener('click', () => printPassbook(consignor, entries, balance));
}
