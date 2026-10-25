import { api } from './api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime } from './util.js';
import { alertDialog } from './dialog.js';
import { skBlock } from './skeleton.js';

// Product card: basic details and every document that moved its stock (purchase, consignment
// receipt, sale, return to the consignor, stock count) with the balance after each, up to now.

const KIND = {
    opening: ['ยอดตั้งต้น', 'muted'],
    purchase: ['ซื้อเข้า', 'ok'],
    receive: ['รับฝาก', 'ok'],
    sale: ['ขาย', 'bad'],
    pos: ['ขาย (นำเข้า POS)', 'bad'],
    return: ['คืนผู้ฝาก', 'warn'],
    count: ['ตรวจนับ', 'warn'],
    adjust: ['ปรับยอด', 'muted'],
};
const GROUPS = [
    ['all', 'ทั้งหมด', () => true],
    ['in', 'รับเข้า', (r) => ['purchase', 'receive', 'opening'].includes(r.kind)],
    ['out', 'ขาย / คืน', (r) => ['sale', 'pos', 'return'].includes(r.kind)],
    ['count', 'ตรวจนับ / ปรับยอด', (r) => ['count', 'adjust'].includes(r.kind)],
];

function info(label, value) {
    return `<div class="pm-info"><div class="muted">${escapeHtml(label)}</div><div>${value}</div></div>`;
}

export async function openProductModal(productId) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:960px"><div class="modal-body">' + skBlock(6, 8) + '</div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let d;
    try {
        d = (await api.get(`/inventory/${productId}/movements`)).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    const p = d.product;
    const t = d.totals;
    let group = 'all';
    let newestFirst = true;

    function tableHtml() {
        const fn = GROUPS.find(([k]) => k === group)[2];
        const rows = d.movements.filter(fn);
        if (newestFirst) rows.reverse();
        if (!rows.length) return '<div class="empty" style="padding:14px">ไม่มีรายการ</div>';
        return `<div class="table-wrap" style="max-height:420px;border:1px solid var(--border);border-radius:10px"><table>
          <thead><tr><th>วันที่</th><th>รายการ</th><th>เอกสาร</th><th>คู่ค้า / หมายเหตุ</th>
            <th class="num">ราคา/ชิ้น</th><th class="num">เข้า</th><th class="num">ออก</th><th class="num">คงเหลือ</th></tr></thead>
          <tbody>${rows.map((r) => {
              const [label, tone] = KIND[r.kind] || [r.kind, 'muted'];
              const detail = [r.party, r.note].filter(Boolean).map(escapeHtml).join('<br>');
              return `<tr>
                <td style="white-space:nowrap">${r.date ? escapeHtml(fmtDate(r.date)) : '<span class="muted">ปัจจุบัน</span>'}
                  ${r.at ? `<div class="muted" style="font-size:11.5px">บันทึก ${escapeHtml(fmtDateTime(r.at))}</div>` : ''}</td>
                <td><span class="pill ${tone}">${label}</span></td>
                <td style="white-space:nowrap">${r.doc_no ? escapeHtml(r.doc_no) : '<span class="muted">-</span>'}
                  ${r.user_name ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.user_name)}</div>` : ''}</td>
                <td style="font-size:12.5px">${detail || '<span class="muted">-</span>'}</td>
                <td class="num">${r.unit_price !== null ? money(r.unit_price) : '<span class="muted">-</span>'}</td>
                <td class="num pm-in">${r.qty_in ? `+${count(r.qty_in)}` : ''}</td>
                <td class="num pm-out">${r.qty_out ? `−${count(r.qty_out)}` : ''}</td>
                <td class="num" style="font-weight:600${r.balance < 0 ? ';color:var(--bad)' : ''}">${count(r.balance)}</td>
              </tr>`;
          }).join('')}</tbody></table></div>`;
    }

    function draw() {
        const photo = p.image_token
            ? `<img class="pm-photo" src="${api.downloadUrl(`/products/${p.id}/photo?size=thumb&v=${encodeURIComponent(p.image_token)}`)}" alt="">`
            : '<div class="pm-photo pm-nophoto" aria-hidden="true">📷</div>';
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>${escapeHtml(p.name)}</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div class="pm-top">
              ${photo}
              <div class="pm-grid">
                ${info('เจ้าของสินค้า', p.consignor_id != null ? `ฝากขาย: ${escapeHtml(p.consignor_code ? `${p.consignor_code} - ` : '')}${escapeHtml(p.consignor_name || '')}` : 'สินค้าของร้าน')}
                ${info('บาร์โค้ดชิ้น', p.barcode ? escapeHtml(p.barcode) : '<span class="muted">-</span>')}
                ${info('บาร์โค้ดแพ็ค', p.pack_barcodes.length ? p.pack_barcodes.map((b) => `${escapeHtml(b.barcode)} <span class="pill muted">×${b.pack_size}</span>`).join('<br>') : '<span class="muted">-</span>')}
                ${info(p.consignor_id != null ? 'ผู้ฝากได้/ชิ้น' : 'ต้นทุน/ชิ้น (เฉลี่ย)', `฿ ${money(p.cost_price)}`)}
                ${info('ราคาขาย', `฿ ${money(p.sale_price)} <span class="muted">(กำไร ${p.margin_percent}%)</span>`)}
                ${info('มูลค่าทุนคงเหลือ', `฿ ${money(p.qty_stock * p.cost_price)}`)}
                ${info('เพิ่มเข้าระบบ', escapeHtml(fmtDate(p.created_at)))}
                ${info('ขายล่าสุด', p.last_sale ? escapeHtml(fmtDate(p.last_sale)) : '<span class="muted">-</span>')}
                ${p.expiry_date ? info('วันหมดอายุ', escapeHtml(fmtDate(p.expiry_date))) : ''}
              </div>
            </div>
            <div class="pm-stats">
              <div><div class="muted">รับเข้า</div><b class="pm-in">+${count(t.in)}</b></div>
              <div><div class="muted">ขายออก</div><b class="pm-out">−${count(t.sold)}</b></div>
              ${t.returned ? `<div><div class="muted">คืนผู้ฝาก</div><b class="pm-out">−${count(t.returned)}</b></div>` : ''}
              <div><div class="muted">ตรวจนับ / ปรับยอด</div><b>${t.count_adjust > 0 ? '+' : t.count_adjust < 0 ? '−' : ''}${count(Math.abs(t.count_adjust))}</b></div>
              <div><div class="muted">คงเหลือปัจจุบัน</div><b style="${p.qty_stock < 0 ? 'color:var(--bad)' : ''}">${count(p.qty_stock)}</b></div>
            </div>
            <div class="actions-row" style="justify-content:space-between;margin:14px 0 8px">
              <div class="tabs" role="group" aria-label="กรองความเคลื่อนไหว">
                ${GROUPS.map(([k, l, fn]) => `<button type="button" class="tab-btn ${k === group ? 'active' : ''}" data-group="${k}">${l} (${count(d.movements.filter(fn).length)})</button>`).join('')}
              </div>
              <button type="button" class="btn secondary sm" data-order>${newestFirst ? 'ล่าสุดก่อน ▼' : 'เก่าสุดก่อน ▲'}</button>
            </div>
            ${tableHtml()}
            <div class="muted" style="font-size:12px;margin-top:8px">คงเหลือคำนวณต่อเนื่องจากเอกสารทุกรายการ · การตรวจนับที่อนุมัติแล้วจะตั้งยอดคงเหลือเท่ากับจำนวนที่นับได้ · ใบซื้อ/การนำเข้าที่ถูกยกเลิกไม่แสดง</div>
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-group]').forEach((b) => b.addEventListener('click', () => { group = b.dataset.group; draw(); }));
        box.querySelector('[data-order]').addEventListener('click', () => { newestFirst = !newestFirst; draw(); });
    }
    draw();
}
