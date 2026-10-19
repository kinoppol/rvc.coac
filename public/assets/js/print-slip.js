import { money, escapeHtml, fmtDate, fmtDateTime } from './util.js';

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
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtDateTime(new Date().toISOString()))}</div>
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
  <div class="foot">พิมพ์เมื่อ ${escapeHtml(fmtDateTime(new Date().toISOString()))}</div>
</body></html>`;

    printHtml(html);
}

/** Prints a standalone HTML document through a hidden iframe (no pop-up window to block). */
function printHtml(html) {
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
