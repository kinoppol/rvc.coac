import { money, escapeHtml, fmtDate, fmtDateTime, fmtNow } from './util.js';

const STATUS = { pending: 'รอร้านตรวจรับ', received: 'รับฝากแล้ว', rejected: 'ไม่รับฝาก', cancelled: 'ยกเลิกแล้ว' };

/**
 * Prints a consignment request as an A4 slip (ใบฝากขาย) for the consignor and
 * the checking staff to sign. quote(cost) must resolve to the shop's sale price.
 */
export async function printConsignSlip(req, quote) {
    const costs = [...new Set(req.items.map((i) => Number(i.cost_price)))];
    const prices = new Map(await Promise.all(costs.map(async (c) => [c, await quote(c)])));
    const rows = req.items.map((i) => {
        const cost = Number(i.cost_price);
        const qty = Number(i.qty);
        return { ...i, cost, qty, price: prices.get(cost) };
    });
    const totalQty = rows.reduce((s, r) => s + r.qty, 0);
    const totalCost = rows.reduce((s, r) => s + r.cost * r.qty, 0);
    const totalSale = rows.reduce((s, r) => s + r.price * r.qty, 0);

    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>ใบฝากขาย ${escapeHtml(req.request_no)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page { size: A4; margin: 14mm 12mm; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { font-family: 'Sarabun', 'Tahoma', sans-serif; color: #111; background: #fff; font-size: 13.5px; margin: 0; padding: 4mm; }
  @media print { body { padding: 0; } }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
  h1 { font-size: 22px; margin: 0; }
  .sub { color: #444; font-size: 13px; }
  .meta { text-align: right; font-size: 13px; line-height: 1.6; }
  .meta b { font-size: 15px; }
  .box { border: 1px solid #999; border-radius: 6px; padding: 8px 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 2px 24px; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; }
  th { background: #eee; font-weight: 600; font-size: 12.5px; }
  .num { text-align: right; white-space: nowrap; }
  .c { text-align: center; }
  .bc { font-family: ui-monospace, monospace; font-size: 11px; color: #555; }
  tfoot td { font-weight: 700; background: #f6f6f6; }
  .note { margin-top: 10px; font-size: 13px; }
  .check { margin-top: 10px; font-size: 12.5px; color: #333; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 46px; text-align: center; }
  .sign .line { border-top: 1px dotted #111; margin: 0 20px 4px; padding-top: 4px; }
  .foot { margin-top: 24px; font-size: 11px; color: #777; text-align: right; }
</style></head><body>
  <div class="head">
    <div><h1>ใบฝากขายสินค้า</h1><div class="sub">ร้านค้าสหการ · ระบบฝากขาย</div></div>
    <div class="meta">เลขที่ <b>${escapeHtml(req.request_no)}</b><br>
      วันที่แจ้ง ${escapeHtml(fmtDateTime(req.created_at))}<br>
      สถานะ: ${escapeHtml(STATUS[req.status] || req.status)}${req.receive_doc_no ? `<br>ใบรับฝากเลขที่ ${escapeHtml(req.receive_doc_no)}` : ''}</div>
  </div>
  <div class="box">
    <div>ผู้ฝากขาย: <b>${escapeHtml(req.consignor_name)}</b></div>
    <div>รหัสผู้ฝาก: <b>${escapeHtml(req.consignor_code)}</b></div>
    <div>แผนก / ชั้นปี: ${escapeHtml(req.consignor_department || '-')}</div>
    <div>โทรศัพท์: ${escapeHtml(req.consignor_phone || '-')}</div>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:34px">ที่</th><th>สินค้า</th><th class="c">วันที่ผลิต</th><th class="c">วันหมดอายุ</th>
      <th class="num">ผู้ฝากได้/ชิ้น</th><th class="num">ราคาขาย/ชิ้น</th><th class="num">จำนวน</th><th class="num">ผู้ฝากได้รวม</th>
      <th class="c" style="width:70px">รับจริง</th>
    </tr></thead>
    <tbody>${rows.map((r, i) => `
      <tr>
        <td class="c">${i + 1}</td>
        <td>${escapeHtml(r.name)}${r.barcode ? `<div class="bc">${escapeHtml(r.barcode)}</div>` : ''}</td>
        <td class="c">${r.mfg_date ? escapeHtml(fmtDate(r.mfg_date)) : '-'}</td>
        <td class="c">${r.expiry_date ? escapeHtml(fmtDate(r.expiry_date)) : '-'}</td>
        <td class="num">${money(r.cost)}</td>
        <td class="num">${money(r.price)}</td>
        <td class="num">${r.qty}</td>
        <td class="num">${money(r.cost * r.qty)}</td>
        <td></td>
      </tr>`).join('')}
    </tbody>
    <tfoot><tr>
      <td colspan="6">รวม ${rows.length} รายการ (มูลค่าตามราคาขาย ฿${money(totalSale)})</td>
      <td class="num">${totalQty}</td><td class="num">${money(totalCost)}</td><td></td>
    </tr></tfoot>
  </table>
  ${req.note ? `<div class="note">หมายเหตุจากผู้ฝาก: ${escapeHtml(req.note)}</div>` : ''}
  <div class="check">ราคาขายเป็นราคาโดยประมาณตามอัตราค่าฝากขายปัจจุบัน · พนักงานจะตรวจนับและบันทึกจำนวนที่รับจริงในช่อง «รับจริง»
    ยอดที่ผู้ฝากได้จะคำนวณจากสินค้าที่ขายได้จริงเท่านั้น</div>
  <div class="sign">
    <div><div class="line">(${escapeHtml(req.consignor_name)})</div>ผู้ฝากขาย<br>วันที่ ........../........../..........</div>
    <div><div class="line">(.................................................)</div>ผู้ตรวจรับสินค้า<br>วันที่ ........../........../..........</div>
  </div>
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtNow())}</div>
</body></html>`;

    printHtml(html);
}

const PURCHASE_TYPE = { stock: 'เติมสต๊อก', new: 'สินค้าใหม่', supply: 'ใช้ในกิจการ' };

/**
 * Prints a store purchase (ใบซื้อสินค้าเข้าร้าน) on A4 in TH Sarabun New —
 * the font installed on the computer when present, else the Sarabun web font.
 */
export function printPurchaseDoc(d) {
    const cancelled = d.status === 'cancelled';
    const typeOf = (it) => (it.item_type === 'supply' ? 'supply' : Number(it.is_new_product) ? 'new' : 'stock');
    const subtotal = d.items.reduce((s, it) => s + Number(it.line_total || 0), 0);
    const supply = d.items.filter((it) => it.item_type === 'supply').reduce((s, it) => s + Number(it.line_total || 0), 0);
    const units = d.items.filter((it) => it.item_type !== 'supply').reduce((s, it) => s + Number(it.units || 0), 0);

    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>ใบซื้อสินค้าเข้าร้าน ${escapeHtml(d.doc_no)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @font-face { font-family: 'THSarabun'; font-weight: 400; src: local('TH Sarabun New'), local('THSarabunNew'), local('TH SarabunPSK'), local('THSarabunPSK'); }
  @font-face { font-family: 'THSarabun'; font-weight: 700; src: local('TH Sarabun New Bold'), local('THSarabunNew-Bold'), local('TH SarabunPSK Bold'), local('THSarabunPSK-Bold'); }
  @page { size: A4; margin: 12mm; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { font-family: 'THSarabun', 'TH Sarabun New', 'TH SarabunPSK', 'Sarabun', sans-serif; color: #111; background: #fff; font-size: 16pt; line-height: 1.15; margin: 0; padding: 4mm; }
  @media print { body { padding: 0; } }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
  h1 { font-size: 24pt; margin: 0; line-height: 1.1; }
  .sub { color: #444; font-size: 15pt; }
  .meta { text-align: right; font-size: 15pt; }
  .meta b { font-size: 18pt; }
  .void { border: 2px solid #b00; color: #b00; padding: 4px 10px; margin-bottom: 10px; }
  .box { border: 1px solid #999; border-radius: 6px; padding: 6px 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 15pt; }
  th, td { border: 1px solid #999; padding: 2px 6px; vertical-align: top; }
  th { background: #eee; font-weight: 700; }
  tr { page-break-inside: avoid; }
  .num { text-align: right; white-space: nowrap; }
  .c { text-align: center; white-space: nowrap; }
  .small { font-size: 13pt; color: #444; }
  .sum { margin-left: auto; margin-top: 8px; width: 60%; font-size: 16pt; page-break-inside: avoid; }
  .sum td { border: 0; padding: 1px 6px; }
  .sum tr.net td { border-top: 1.5px solid #111; font-weight: 700; font-size: 18pt; }
  .note { margin-top: 8px; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; text-align: center; page-break-inside: avoid; }
  .sign .line { border-top: 1px dotted #111; margin: 0 20px 2px; padding-top: 2px; }
  .foot { margin-top: 18px; font-size: 12pt; color: #777; text-align: right; }
</style></head><body>
  <div class="head">
    <div><h1>ใบซื้อสินค้าเข้าร้าน</h1><div class="sub">ร้านค้าสหการ</div></div>
    <div class="meta">เลขที่ <b>${escapeHtml(d.doc_no)}</b><br>
      บันทึกเมื่อ ${escapeHtml(fmtDateTime(d.created_at))}<br>
      บันทึกโดย ${escapeHtml(d.user_name || '-')}</div>
  </div>
  ${cancelled ? `<div class="void"><b>ยกเลิกแล้ว</b> เมื่อ ${escapeHtml(fmtDateTime(d.cancelled_at))}${d.cancelled_by_name ? ` โดย ${escapeHtml(d.cancelled_by_name)}` : ''} · เหตุผล: ${escapeHtml(d.cancel_reason || '-')}</div>` : ''}
  <div class="box">
    <div>ร้าน/ผู้ขาย: <b>${escapeHtml(d.supplier_name || '-')}</b></div>
    <div>เลขประจำตัวผู้เสียภาษี: ${escapeHtml(d.supplier_tax_id || '-')}</div>
    <div>เลขที่ใบเสร็จ: <b>${escapeHtml(d.receipt_no || '-')}</b></div>
    <div>วันที่ใบเสร็จ: ${d.receipt_date ? escapeHtml(fmtDate(d.receipt_date)) : '-'}</div>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:30px">ที่</th><th>สินค้า</th><th class="num">จำนวนซื้อ</th><th class="num">ทุน/หน่วยซื้อ</th><th class="num">รวม</th>
      <th class="num">เข้าสต๊อก (ชิ้น)</th><th class="num">ทุน/ชิ้น</th><th class="num">ขาย/ชิ้น</th><th class="c">ประเภท</th>
    </tr></thead>
    <tbody>${d.items.map((it, i) => `
      <tr>
        <td class="c">${i + 1}</td>
        <td>${escapeHtml(it.receipt_name)}${it.product_name && it.product_name !== it.receipt_name ? `<div class="small">→ ${escapeHtml(it.product_name)}</div>` : ''}${it.barcode ? `<div class="small">บาร์โค้ด${Number(it.pack_size) > 1 ? 'แพ็ค' : ''}: ${escapeHtml(it.barcode)}</div>` : ''}</td>
        <td class="num">${escapeHtml(String(it.qty))}${Number(it.pack_size) > 1 ? ` × ${escapeHtml(String(it.pack_size))}` : ''}</td>
        <td class="num">${money(it.unit_cost)}</td>
        <td class="num">${money(it.line_total)}</td>
        <td class="num">${it.item_type === 'supply' ? '—' : escapeHtml(String(it.units))}</td>
        <td class="num">${money(it.piece_cost)}</td>
        <td class="num">${it.item_type === 'supply' ? '—' : money(it.sale_price)}</td>
        <td class="c">${PURCHASE_TYPE[typeOf(it)]}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <table class="sum">
    <tr><td colspan="2">รวม ${d.items.length} รายการ · เข้าสต๊อก ${units} ชิ้น</td></tr>
    ${supply > 0 ? `<tr><td>เพื่อขาย / ใช้ในกิจการ</td><td class="num">${money(subtotal - supply)} / ${money(supply)}</td></tr>` : ''}
    <tr><td>รวมตามรายการ</td><td class="num">${money(subtotal)}</td></tr>
    <tr><td>ส่วนลดท้ายบิล</td><td class="num">${money(d.discount)}</td></tr>
    <tr><td>VAT</td><td class="num">${money(d.vat)}</td></tr>
    <tr class="net"><td>ยอดสุทธิ (บาท)</td><td class="num">${money(d.total)}</td></tr>
  </table>
  ${d.note ? `<div class="note">หมายเหตุ: ${escapeHtml(d.note)}</div>` : ''}
  <div class="sign">
    <div><div class="line">(${escapeHtml(d.user_name || '.................................................')})</div>ผู้บันทึก<br>วันที่ ........../........../..........</div>
    <div><div class="line">(.................................................)</div>ผู้ตรวจสอบ<br>วันที่ ........../........../..........</div>
  </div>
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtNow())}</div>
</body></html>`;

    printHtml(html);
}

const LEDGER_CODE = { open: 'ยกมา', sale: 'ขาย', fee: 'ค่าฝาก', listing_fee: 'ค่าทำข้อมูล', listing_fee_refund: 'คืนค่าข้อมูล', withdrawal: 'เบิกเงิน' };

/** dd/mm/yy in the Buddhist year, like a bank passbook line. */
function shortDate(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}/${m[2]}/${String(Number(m[1]) + 543).slice(-2)}` : '';
}

/**
 * Prints a consignor's ledger as a bank-style passbook (สมุดคู่ฝาก): account box on top,
 * then one compact numbered line per entry — date, code, withdrawal, deposit, balance.
 */
export function printPassbook(consignor, entries, balance) {
    const totalIn = entries.reduce((s, e) => s + Number(e.amount_in), 0);
    const totalOut = entries.reduce((s, e) => s + Number(e.amount_out), 0);
    const amt = (v) => (Number(v) ? money(v) : '');

    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>สมุดคู่ฝาก ${escapeHtml(consignor.code)} ${escapeHtml(consignor.name)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page { size: A4; margin: 12mm 12mm; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { font-family: 'Sarabun', 'Tahoma', sans-serif; color: #111; background: #fff; font-size: 13px; margin: 0; padding: 4mm; }
  @media print { body { padding: 0; } }
  .book { border: 2px solid #1d3f8f; border-radius: 10px; overflow: hidden; margin-bottom: 12px; }
  .book-head { background: #1d3f8f; color: #fff; padding: 8px 14px; display: flex; justify-content: space-between; align-items: center;
    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .book-head h1 { font-size: 19px; margin: 0; letter-spacing: .5px; }
  .book-head span { font-size: 12px; opacity: .9; }
  .acct { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 6px 18px; padding: 10px 14px; }
  .acct small { display: block; color: #666; font-size: 11px; }
  .acct b { font-size: 15px; }
  .acct .no { font-family: ui-monospace, 'Consolas', monospace; font-size: 17px; letter-spacing: 1px; }
  table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
  thead th { border-top: 2px solid #1d3f8f; border-bottom: 2px solid #1d3f8f; padding: 6px 6px; font-size: 12px; font-weight: 700; color: #1d3f8f; }
  td { padding: 5px 6px; border-bottom: 1px dotted #9aa6c4; white-space: nowrap; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f3f6fc; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  tr { page-break-inside: avoid; }
  .n { text-align: right; color: #666; width: 30px; }
  .d { width: 72px; font-family: ui-monospace, 'Consolas', monospace; }
  .code { width: 82px; font-weight: 600; }
  .desc { white-space: normal; color: #444; font-size: 11.5px; }
  .ref { font-family: ui-monospace, 'Consolas', monospace; font-size: 10.5px; color: #777; }
  .num { text-align: right; width: 92px; font-family: ui-monospace, 'Consolas', monospace; font-size: 13px; }
  .out { color: #b42318; }
  .bal { font-weight: 700; }
  tfoot td { border-top: 2px solid #1d3f8f; border-bottom: 0; font-weight: 700; padding-top: 7px; background: none; }
  .legend { margin-top: 10px; font-size: 11px; color: #666; }
  .foot { margin-top: 18px; display: flex; justify-content: space-between; font-size: 11px; color: #777; }
  .sign { margin-top: 36px; width: 260px; margin-left: auto; text-align: center; font-size: 12.5px; }
  .sign .line { border-top: 1px dotted #111; padding-top: 4px; }
</style></head><body>
  <div class="book">
    <div class="book-head"><h1>สมุดคู่ฝาก · ร้านค้าสหการ</h1><span>ระบบฝากขายสินค้า</span></div>
    <div class="acct">
      <div><small>ชื่อบัญชี</small><b>${escapeHtml(consignor.name)}</b></div>
      <div><small>เลขที่บัญชี (รหัสผู้ฝาก)</small><span class="no">${escapeHtml(consignor.code)}</span></div>
      <div><small>ยอดคงเหลือที่เบิกได้</small><b>฿ ${money(balance)}</b></div>
      <div><small>ประเภท</small>${escapeHtml(consignor.type || '-')}</div>
      <div><small>ช่องทางรับเงิน</small>${escapeHtml(consignor.pay_channel || '-')}</div>
      <div><small>ข้อมูล ณ วันที่</small>${escapeHtml(fmtNow({ dateStyle: 'medium' }))}</div>
    </div>
  </div>
  <table>
    <thead><tr>
      <th class="n">ที่</th><th class="d" style="text-align:left">วันที่</th><th class="code" style="text-align:left">รายการ</th>
      <th style="text-align:left">รายละเอียด</th>
      <th class="num">ถอน / จ่าย</th><th class="num">ฝาก / รับ</th><th class="num">คงเหลือ</th>
    </tr></thead>
    <tbody>${entries.map((e, i) => `
      <tr>
        <td class="n">${i + 1}</td>
        <td class="d">${shortDate(e.entry_date || e.created_at)}</td>
        <td class="code">${escapeHtml(LEDGER_CODE[e.type] || e.type)}</td>
        <td class="desc">${escapeHtml(e.description)}<div class="ref">${escapeHtml(e.ref_no)}</div></td>
        <td class="num out">${amt(e.amount_out)}</td>
        <td class="num">${amt(e.amount_in)}</td>
        <td class="num bal">${money(e.balance_after)}</td>
      </tr>`).join('')}
    </tbody>
    <tfoot><tr>
      <td colspan="4">รวม ${entries.length} รายการ</td>
      <td class="num out">${money(totalOut)}</td><td class="num">${money(totalIn)}</td><td class="num">${money(balance)}</td>
    </tr></tfoot>
  </table>
  <div class="legend">ขาย = รายรับจากสินค้าที่ขายได้ · ค่าฝาก = ค่าฝากขายที่ร้านหัก · ค่าทำข้อมูล / คืนค่าข้อมูล = ค่าทำข้อมูลสินค้าใหม่และการคืนเมื่อขายได้ตามเกณฑ์ · เบิกเงิน = รับเงินออกจากบัญชี</div>
  <div class="sign"><div class="line">(.................................................)</div>เจ้าหน้าที่ร้าน</div>
  <div class="foot"><span>${escapeHtml(consignor.code)} · ${escapeHtml(consignor.name)}</span><span>พิมพ์เมื่อ ${escapeHtml(fmtNow())}</span></div>
</body></html>`;

    printHtml(html);
}

/**
 * Prints a report on its own A4 page: title, period and print time on top, then the
 * report body (tables already rendered by the reports page). Wide tables go landscape.
 */
export function printReport({ title, period, body, landscape = false }) {
    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>${escapeHtml(title)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page { size: A4 ${landscape ? 'landscape' : 'portrait'}; margin: 10mm; }
  :root { color-scheme: light; --text: #111; --muted: #555; --border: #bbb; --soft: #eef2fa; --ok: #0f7a44; --bad: #b42318; --surface2: #f1f3f7; }
  * { box-sizing: border-box; }
  body { font-family: 'Sarabun', 'Tahoma', sans-serif; color: #111; background: #fff; font-size: 11.5px; margin: 0; padding: 4mm; }
  @media print { body { padding: 0; } }
  .head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
  h1 { font-size: 18px; margin: 0; }
  .sub { color: #444; font-size: 12px; }
  .meta { text-align: right; font-size: 11px; color: #444; }
  .muted { color: var(--muted); }
  .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 10px; }
  .kpi { border: 1px solid var(--border); border-radius: 6px; padding: 6px 8px; }
  .kpi .lbl { font-size: 10.5px; color: var(--muted); }
  .kpi .val { font-size: 15px; font-weight: 700; }
  .kpi .sub { font-size: 10px; }
  table { width: 100%; max-width: none !important; border-collapse: collapse; }
  th, td { border: 1px solid var(--border); padding: 3px 5px; vertical-align: top; text-align: left; }
  thead th { background: #eee; font-weight: 600; font-size: 10.5px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  tfoot td { font-weight: 700; background: #f6f6f6; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .note { margin-top: 8px; font-size: 10.5px; color: #555; }
  .link-btn[data-src] { display: none; }
  .link-btn[data-drill] { border: 0; background: none; padding: 0; font: inherit; color: inherit; }
  .st-note { font-size: 10px; color: #555; }
  .foot { margin-top: 12px; font-size: 10px; color: #777; text-align: right; }
</style></head><body>
  <div class="head">
    <div><h1>${escapeHtml(title)}</h1><div class="sub">ร้านค้าสหการ · ${escapeHtml(period)}</div></div>
    <div class="meta">พิมพ์เมื่อ ${escapeHtml(fmtNow())}</div>
  </div>
  ${body}
</body></html>`;
    printHtml(html);
}

/** Prints a wage voucher (ใบสำคัญจ่ายค่าแรง) for the employee to sign for the money. */
export function printWageSlip(w, types = {}, methods = {}) {
    const unit = { day: 'วัน', hour: 'ชั่วโมง' }[w.wage_type] || '';
    const period = w.period_from ? `${fmtDate(w.period_from)}${w.period_to && w.period_to !== w.period_from ? ` – ${fmtDate(w.period_to)}` : ''}` : '-';
    const detail = w.units !== null && w.rate !== null ? `${Number(w.units)} ${unit} × ${money(w.rate)} บาท` : (types[w.wage_type] || '');
    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>ใบสำคัญจ่ายค่าแรง ${escapeHtml(w.doc_no)}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page { size: A5 landscape; margin: 10mm; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { font-family: 'Sarabun', 'Tahoma', sans-serif; color: #111; background: #fff; font-size: 14px; margin: 0; padding: 4mm; }
  @media print { body { padding: 0; } }
  .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 12px; }
  h1 { font-size: 20px; margin: 0; }
  .sub { color: #444; font-size: 13px; }
  .meta { text-align: right; font-size: 13px; line-height: 1.6; }
  .void { border: 2px solid #b00; color: #b00; padding: 4px 10px; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #999; padding: 6px 8px; vertical-align: top; text-align: left; }
  th { background: #eee; width: 30%; font-weight: 600; }
  .amount { font-size: 20px; font-weight: 700; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; text-align: center; }
  .sign .line { border-top: 1px dotted #111; margin: 0 20px 4px; padding-top: 4px; }
  .foot { margin-top: 14px; font-size: 11px; color: #777; text-align: right; }
</style></head><body>
  <div class="head">
    <div><h1>ใบสำคัญจ่ายค่าแรง</h1><div class="sub">ร้านค้าสหการ</div></div>
    <div class="meta">เลขที่ <b>${escapeHtml(w.doc_no)}</b><br>วันที่จ่าย ${escapeHtml(fmtDate(w.pay_date))}</div>
  </div>
  ${w.cancelled_at ? `<div class="void"><b>ยกเลิกแล้ว</b> · เหตุผล: ${escapeHtml(w.cancel_reason || '-')}</div>` : ''}
  <table>
    <tr><th>ผู้รับเงิน</th><td>${escapeHtml(w.employee_name || '-')}</td></tr>
    <tr><th>ช่วงวันที่ทำงาน</th><td>${escapeHtml(period)}</td></tr>
    <tr><th>รายละเอียด</th><td>ค่าแรง${escapeHtml(types[w.wage_type] || '')} · ${escapeHtml(detail)}${w.note ? `<br>${escapeHtml(w.note)}` : ''}</td></tr>
    <tr><th>วิธีจ่าย</th><td>${escapeHtml(methods[w.method] || w.method)}${w.drawer_date ? ' (จากเงินในลิ้นชัก)' : ''}</td></tr>
    <tr><th>จำนวนเงิน</th><td class="amount">฿ ${money(w.amount)}</td></tr>
  </table>
  <div class="sign">
    <div><div class="line">(${escapeHtml(w.employee_name || '.................................................')})</div>ผู้รับเงิน</div>
    <div><div class="line">(${escapeHtml(w.created_by_name || '.................................................')})</div>ผู้จ่ายเงิน</div>
  </div>
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtNow())}</div>
</body></html>`;

    printHtml(html);
}

/** Prints a standalone HTML document through a hidden iframe (no pop-up window to block). */
const CLOSING_PAY = { cash: 'เงินสด', promptpay: 'พร้อมเพย์', bank_transfer: 'โอนธนาคาร' };

/**
 * Prints the end-of-day account summary (รายงานสรุปบัญชีประจำวัน) as a plain accounting
 * sheet on one A4 page in TH Sarabun New (else the Sarabun web font), shrunk to fit.
 * r: the day's report (figures, accounts, wages, payouts, purchases, closing, on_duty);
 * send: what was / is being handed over {cash_sent, expected_send, float_kept, sent_to, receipt_no, note, actual}.
 */
export function printClosingReport(r, { weekday, status, send }) {
    const f = r.figures;
    const dr = f.drawer;
    const c = r.closing;
    const n = (v) => money(v);
    const row = (label, v, cls = '') => `<tr class="${cls}"><td>${label}</td><td class="num">${v}</td></tr>`;
    // A difference said in words: "เงินขาด 143.00" / "เงินเกิน 20.00" / "ตรงกัน".
    const shortOver = (what, diff) => (Math.abs(diff) < 0.005 ? row(`${what}ตรงกัน`, '0.00')
        : row(`${what}${diff < 0 ? 'ขาด' : 'เกิน'}`, money(Math.abs(diff))));
    const none = (text) => `<tr><td colspan="2" class="muted">${text}</td></tr>`;
    const section = (title, rows) => `<table class="led"><thead><tr><th colspan="2">${title}</th></tr></thead><tbody>${rows}</tbody></table>`;

    const sales = section('1. ยอดขาย', `
        ${f.methods.map((x) => row(`&nbsp;&nbsp;${escapeHtml(x.label)}`, n(x.amount))).join('')}
        ${f.unknown_sales ? row('&nbsp;&nbsp;นำเข้าจาก POS (ไม่ทราบวิธีชำระ)', n(f.unknown_sales)) : ''}
        ${row(`รวมยอดขาย (${f.sales_count} บิล)`, n(f.total_sales), 'total')}`);

    const drawer = section('2. เงินสดในลิ้นชัก', dr ? `
        ${row('เงินในลิ้นชักตอนเปิดร้าน', n(dr.opening_cash))}
        ${row('บวก ขายได้เป็นเงินสด', n(f.cash_sales))}
        ${row('เงินสดที่ควรมีตอนปิดยอด', n(dr.should_have), 'sub')}
        ${dr.counted_cash !== null ? `${row('เงินที่นับได้จริงตอนปิดยอด', n(dr.counted_cash))}
          ${shortOver('เงิน', dr.counted_cash - dr.should_have)}` : row('เงินที่นับได้จริงตอนปิดยอด', 'ยังไม่ได้นับ')}
        ${row('หัก จ่ายค่าแรงจากลิ้นชัก (ข้อ 4)', n(f.cash_out))}
        ${row('หัก จ่ายเงินผู้ฝากขายจากลิ้นชัก (ข้อ 5)', n(f.payout_out || 0))}
        ${row('หัก ซื้อสินค้าด้วยเงินในลิ้นชัก (ข้อ 6)', n(f.purchase_out))}
        ${row('เงินสดคงเหลือในลิ้นชักหลังจ่าย', n(dr.after_pay), 'sub')}
        ${row('หัก คงไว้เป็นเงินทอนวันถัดไป', n(send.float_kept ?? f.float_kept))}
        ${row('เงินสดที่ต้องส่งวิทยาลัย', n(send.expected_send), 'total')}` : none('ไม่ได้ระบุเงินในลิ้นชัก'));

    const accounts = section('3. เงินเข้าบัญชีที่รับโอน / QR-Code / บัตรเครดิต', r.accounts.length ? r.accounts.map((a) => {
        const got = send.actual?.[a.method];
        const has = got !== undefined && got !== null && got !== '';
        return row(`${escapeHtml(a.label)} <span class="muted">ควรได้ ${n(a.expected)}${has && Math.abs(Number(got) - a.expected) >= 0.005 ? ` · ${Number(got) < a.expected ? 'ขาด' : 'เกิน'} ${n(Math.abs(Number(got) - a.expected))}` : ''}</span>`,
            has ? n(got) : 'ยังไม่ได้ตรวจ');
    }).join('') : none('ไม่ได้เปิดรับชำระด้วยการโอน / QR-Code / บัตร'));

    const wages = section('4. การจ่ายค่าแรง', r.wages.rows.length ? `
        ${r.wages.rows.map((w) => row(`${escapeHtml(w.employee_name || '-')} <span class="muted">${escapeHtml(w.doc_no)} · ${w.drawer_date === f.date ? 'จากลิ้นชัก' : w.method === 'cash' ? 'เงินสด' : 'โอน'}</span>`, n(w.amount))).join('')}
        ${row('รวม', n(r.wages.total), 'total')}` : none('ไม่มีการจ่ายค่าแรง'));

    const payouts = section('5. การจ่ายเงินค่าสินค้าฝากขาย', r.payouts.rows.length ? `
        ${r.payouts.rows.map((w) => row(`${escapeHtml(w.consignor_code)} ${escapeHtml(w.consignor_name)} <span class="muted">${escapeHtml(w.doc_no)} · ${CLOSING_PAY[w.method] || w.method}${w.method === 'cash' ? (w.from_drawer ? ' จากลิ้นชัก' : ' (ไม่ใช้เงินลิ้นชัก)') : ''}</span>`, n(w.amount))).join('')}
        ${row('รวม', n(r.payouts.total), 'total')}` : none('ไม่มีการจ่ายเงินผู้ฝากขาย'));

    const purchases = section('6. การเบิกเงินซื้อสินค้าเข้าร้าน', r.purchases.rows.length ? `
        ${r.purchases.rows.map((p) => row(`${escapeHtml(p.supplier_name || '-')} <span class="muted">${escapeHtml(p.doc_no)}${p.receipt_no ? ` · ใบเสร็จ ${escapeHtml(p.receipt_no)}` : ''} · ${p.from_drawer ? 'จากลิ้นชัก' : 'ไม่ใช้เงินลิ้นชัก'}</span>`, n(p.total))).join('')}
        ${row('รวม', n(r.purchases.total), 'total')}` : none('ไม่มีการซื้อสินค้าเข้าร้าน'));

    const sent = send.cash_sent !== undefined && send.cash_sent !== null && send.cash_sent !== '';
    const handover = section('7. ส่งเงินสดให้วิทยาลัย', `
        ${row('ยอดที่ควรส่ง', n(send.expected_send))}
        ${row('เงินสดที่ส่ง', sent ? n(send.cash_sent) : '..........................', 'total')}
        ${sent && Math.abs(Number(send.cash_sent) - send.expected_send) >= 0.005 ? shortOver('ส่ง', Number(send.cash_sent) - send.expected_send) : ''}
        ${row('ส่งให้', escapeHtml(send.sent_to || '-'))}
        ${row('เลขที่ใบเสร็จ / ใบรับเงิน', escapeHtml(send.receipt_no || '..........................'))}
        ${send.note ? `<tr><td colspan="2">หมายเหตุ: ${escapeHtml(send.note)}</td></tr>` : ''}`);

    const html = `<!doctype html>
<html lang="th"><head><meta charset="utf-8">
<title>รายงานสรุปบัญชีประจำวัน ${escapeHtml(fmtDate(f.date))}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">
<style>
  @font-face { font-family: 'THSarabun'; font-weight: 400; src: local('TH Sarabun New'), local('THSarabunNew'), local('TH SarabunPSK'), local('THSarabunPSK'); }
  @font-face { font-family: 'THSarabun'; font-weight: 700; src: local('TH Sarabun New Bold'), local('THSarabunNew-Bold'), local('TH SarabunPSK Bold'), local('THSarabunPSK-Bold'); }
  @page { size: A4 portrait; margin: 10mm 12mm; }
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { font-family: 'THSarabun', 'TH Sarabun New', 'TH SarabunPSK', 'Sarabun', sans-serif; color: #000; background: #fff;
         font-size: 14pt; line-height: 1.1; margin: 0; width: 186mm; }
  .head { text-align: center; margin-bottom: 4px; }
  .head h1 { font-size: 18pt; margin: 0; }
  .head div { font-size: 14pt; }
  .info { display: flex; justify-content: space-between; border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; padding: 1px 0; margin-bottom: 6px; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0 6mm; align-items: start; }
  .led { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .led th { text-align: left; font-weight: 700; border-bottom: 1px solid #000; padding: 1px 0; }
  .led td { padding: 0 0; vertical-align: top; }
  .led td.num { text-align: right; white-space: nowrap; padding-left: 8px; font-variant-numeric: tabular-nums; width: 1%; }
  .led tr.sub td { font-weight: 700; }
  .led tr.sub td.num { border-top: 1px solid #000; }
  .led tr.total td { font-weight: 700; }
  .led tr.total td.num { border-top: 1px solid #000; border-bottom: 3px double #000; }
  .muted { color: #444; font-size: 12pt; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; margin-top: 14mm; text-align: center; }
  .sign .line { border-top: 1px dotted #000; margin: 0 4mm 1px; padding-top: 1px; }
  .foot { margin-top: 4mm; font-size: 11pt; color: #444; text-align: right; }
</style></head><body>
  <div class="head"><h1>รายงานสรุปบัญชีประจำวัน</h1><div>ร้านค้าสหการ</div></div>
  <div class="info">
    <span>วัน${escapeHtml(weekday)}ที่ ${escapeHtml(fmtDate(f.date))}</span>
    <span>ผู้มีเวรสรุปบัญชี: ${escapeHtml(r.on_duty.map((p) => p.full_name).join(', ') || '-')}</span>
    <span>สถานะ: ${escapeHtml(status)}</span>
  </div>
  <div class="cols">
    <div>${sales}${accounts}${wages}</div>
    <div>${drawer}${handover}</div>
  </div>
  <div class="cols">
    <div>${payouts}</div>
    <div>${purchases}</div>
  </div>
  ${dr && dr.counted_cash !== null ? `<div class="muted">ปิดยอดลิ้นชักโดย ${escapeHtml(dr.closed_by_name || '-')} ${dr.closed_at ? escapeHtml(fmtDateTime(dr.closed_at)) : ''}</div>` : ''}
  <div class="sign">
    <div><div class="line">(${escapeHtml(c?.closer_name || '.......................................')})</div>ผู้สรุปบัญชี</div>
    <div><div class="line">(.......................................)</div>ผู้รับเงิน (วิทยาลัย)</div>
    <div><div class="line">(${escapeHtml(c?.verified_by_name || '.......................................')})</div>ผู้ตรวจสอบ</div>
  </div>
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtNow())}</div>
</body></html>`;

    printHtml(html, { fitPage: true });
}

function printHtml(html, { fitPage = false } = {}) {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(frame);
    const win = frame.contentWindow;
    let done = false;
    const go = async () => {
        if (done) return;
        done = true;
        // Wait for the Thai web font (if it loads) so the print isn't in a fallback font.
        try { await Promise.race([win.document.fonts.ready, new Promise((r) => setTimeout(r, 1500))]); } catch (e) { /* ignore */ }
        if (fitPage) {
            // Shrink to one A4 page: 297mm less the 10mm top and bottom margins, in CSS px.
            const page = (277 * 96) / 25.4;
            const body = win.document.body;
            const h = body.scrollHeight;
            if (h > page) body.style.zoom = String(Math.floor((page / h) * 0.98 * 1000) / 1000);
        }
        win.addEventListener('afterprint', () => frame.remove());
        setTimeout(() => frame.remove(), 120000);
        win.focus();
        win.print();
    };
    frame.onload = go;
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(go, 2500); // onload may not fire for document.write in some browsers
}
