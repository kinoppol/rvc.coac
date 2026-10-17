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
