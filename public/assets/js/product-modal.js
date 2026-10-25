import { api } from './api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime } from './util.js';
import { alertDialog } from './dialog.js';
import { skBlock } from './skeleton.js';
import { openDetailModal } from './detail-modal.js';

// Product card: basic details and every document that moved its stock (purchase, consignment
// receipt, sale, return to the consignor, stock count) with the balance after each, up to now.

const KIND = {
    opening: ['ยอดยกมา', 'muted'],
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

export async function openProductModal(productId, navigate) {
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
    let newestFirst = false; // a stock card reads oldest first, in document-date order

    function tableHtml() {
        const fn = GROUPS.find(([k]) => k === group)[2];
        const rows = d.movements.filter(fn);
        if (newestFirst) rows.reverse();
        if (!rows.length) return '<div class="empty" style="padding:14px">ไม่มีรายการ</div>';
        return `<div class="table-wrap" style="max-height:420px;border:1px solid var(--border);border-radius:10px"><table>
          <thead><tr><th>วันที่เอกสาร</th><th>รายการ</th><th>เลขที่เอกสาร</th><th>คู่ค้า / หมายเหตุ</th>
            <th class="num">รับ</th><th class="num">จ่าย</th><th class="num">คงเหลือ</th><th class="num">ทุน/ชิ้น</th><th class="num">มูลค่าทุน</th></tr></thead>
          <tbody>${rows.map((r) => {
              const [label, tone] = KIND[r.kind] || [r.kind, 'muted'];
              const detail = [r.party, r.note].filter(Boolean).map(escapeHtml).join('<br>');
              return `<tr>
                <td style="white-space:nowrap">${r.date ? escapeHtml(fmtDate(r.date)) : '<span class="muted">-</span>'}
                  ${r.late ? `<div class="pm-late" title="บันทึกเข้าระบบหลังวันที่ในเอกสาร">บันทึกล่าช้า ${escapeHtml(fmtDateTime(r.at))}</div>`
                      : r.at ? `<div class="muted" style="font-size:11.5px">บันทึก ${escapeHtml(fmtDateTime(r.at))}</div>` : ''}</td>
                <td><span class="pill ${tone}">${label}</span></td>
                <td style="white-space:nowrap">${r.doc_no ? `<button type="button" class="prod-link" data-doc="${escapeHtml(r.kind)}|${escapeHtml(r.doc_no)}" title="เปิดดูเอกสาร">${escapeHtml(r.doc_no)}</button>` : '<span class="muted">-</span>'}
                  ${r.user_name ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.user_name)}</div>` : ''}</td>
                <td style="font-size:12.5px">${detail || '<span class="muted">-</span>'}</td>
                <td class="num pm-in">${r.qty_in ? count(r.qty_in) : ''}</td>
                <td class="num pm-out">${r.qty_out ? count(r.qty_out) : ''}</td>
                <td class="num" style="font-weight:600${r.balance < 0 ? ';color:var(--bad)' : ''}">${count(r.balance)}</td>
                <td class="num">${r.unit_cost !== null ? money(r.unit_cost) : '<span class="muted">-</span>'}</td>
                <td class="num">${money(Math.abs(r.value))}</td>
              </tr>`;
          }).join('')}</tbody></table></div>`;
    }

    /** Opening + received − sold − returned ± adjustments = closing, in pieces and at cost. */
    function summaryHtml() {
        const line = (label, v, sign, cls = '') => `<tr class="${cls}"><td>${label}</td>
            <td class="num">${sign}${count(Math.abs(v.qty))}</td><td class="num">${sign}${money(Math.abs(v.value))}</td></tr>`;
        const adj = t.adjusted;
        const adjSign = adj.qty < 0 || (adj.qty === 0 && adj.value < 0) ? '−' : '+';
        return `<table class="pm-sum">
          <thead><tr><th>สรุปบัญชีคุมสินค้า</th><th class="num">จำนวน (ชิ้น)</th><th class="num">มูลค่าทุน (บาท)</th></tr></thead>
          <tbody>
            ${line('ยอดยกมา (ไม่มีเอกสารรับเข้า)', t.opening, '')}
            ${line('บวก รับเข้า (ซื้อ / รับฝาก)', t.received, '+')}
            ${line('หัก ขายออก (ต้นทุนขาย)', t.sold, '−')}
            ${t.returned.qty ? line('หัก คืนผู้ฝาก', t.returned, '−') : ''}
            ${line('ปรับปรุงจากการตรวจนับ / ส่วนต่าง', adj, adjSign)}
            ${line('คงเหลือยกไป', t.closing, '', 'pm-sum-total')}
          </tbody></table>
          <div class="muted" style="font-size:11.5px;margin-top:4px">มูลค่าคงเหลือคิดจากต้นทุนเฉลี่ยปัจจุบัน ฿${money(p.cost_price)}/ชิ้น · ต้นทุนขายตามที่บันทึกไว้ในแต่ละบิล</div>`;
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
            ${summaryHtml()}
            <div class="actions-row" style="justify-content:space-between;margin:14px 0 8px">
              <div class="tabs" role="group" aria-label="กรองความเคลื่อนไหว">
                ${GROUPS.map(([k, l, fn]) => `<button type="button" class="tab-btn ${k === group ? 'active' : ''}" data-group="${k}">${l} (${count(d.movements.filter(fn).length)})</button>`).join('')}
              </div>
              <button type="button" class="btn secondary sm" data-order>${newestFirst ? 'ล่าสุดก่อน ▼' : 'เก่าสุดก่อน ▲'}</button>
            </div>
            ${tableHtml()}
            <div class="muted" style="font-size:12px;margin-top:8px">เรียงตามวันที่ในเอกสาร (วันเดียวกัน: รับก่อนจ่าย แล้วปรับปรุงตรวจนับท้ายวัน) · รายการที่บันทึกล่าช้ายังอยู่ในวันที่ของเอกสาร · ตรวจนับลงผลต่างระหว่างที่นับได้กับยอดในระบบตอนนับ · ใบซื้อ/การนำเข้าที่ถูกยกเลิกไม่แสดง</div>
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-group]').forEach((b) => b.addEventListener('click', () => { group = b.dataset.group; draw(); }));
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => {
            const [kind, docNo] = b.dataset.doc.split('|');
            openDetailModal(
                async () => (await api.get(`/inventory/document?kind=${encodeURIComponent(kind)}&doc_no=${encodeURIComponent(docNo)}`)).data,
                navigate && ((page) => { close(); navigate(page); }),
            );
        }));
        box.querySelector('[data-order]').addEventListener('click', () => { newestFirst = !newestFirst; draw(); });
    }
    draw();
}

const ISSUE = {
    duplicate: ['รับเข้าซ้ำ', 'bad'],
    negative: ['ขายเกินรับเข้า', 'bad'],
    reordered: ['บันทึกสลับลำดับ', 'warn'],
    gap: ['ยอดไม่ตรงเอกสาร', 'warn'],
};

/** Admin: products whose stock may be off from documents keyed in late or out of order. */
export async function openOrderCheckModal(navigate) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:960px"><div class="modal-body">' + skBlock(4, 8) + '</div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let rows;
    try {
        rows = (await api.get('/inventory/order-check')).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    let only = 'all';

    function draw() {
        const shown = only === 'all' ? rows : rows.filter((r) => r.issues.some((i) => i.code === only));
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>ตรวจสอบข้อมูลที่บันทึกล่าช้า / สลับลำดับ</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div class="hint"><div class="dot">i</div><div>ไล่ยอดคงเหลือของสินค้าของร้านทุกตัวตามวันที่ในเอกสาร เทียบกับลำดับที่บันทึกเข้าระบบ
              กดชื่อสินค้าเพื่อดูบัญชีคุมสินค้าและเปิดเอกสารที่เกี่ยวข้อง · แก้ยอดให้ตรงของจริงด้วยการตรวจนับสต๊อก (สินค้าที่ขายหมดแล้ว กดปุ่ม หมด (0) ในหน้าตรวจนับ)</div></div>
            <div class="tabs" role="group" aria-label="กรองปัญหา" style="margin:12px 0 8px">
              <button type="button" class="tab-btn ${only === 'all' ? 'active' : ''}" data-only="all">ทั้งหมด (${count(rows.length)})</button>
              ${Object.entries(ISSUE).map(([k, [l]]) => `<button type="button" class="tab-btn ${only === k ? 'active' : ''}" data-only="${k}">${l} (${count(rows.filter((r) => r.issues.some((i) => i.code === k)).length)})</button>`).join('')}
            </div>
            ${shown.length ? `<div class="table-wrap" style="max-height:480px;border:1px solid var(--border);border-radius:10px"><table>
              <thead><tr><th>สินค้า</th><th class="num">คงเหลือในระบบ</th><th class="num">ตามเอกสาร</th><th class="num">บันทึกล่าช้า</th><th>สิ่งที่พบ</th></tr></thead>
              <tbody>${shown.map((r) => `<tr>
                <td><button type="button" class="prod-link" data-product="${r.id}">${escapeHtml(r.name)}</button></td>
                <td class="num">${count(r.qty_stock)}</td>
                <td class="num">${count(r.doc_balance)}</td>
                <td class="num">${r.late_count ? count(r.late_count) : '<span class="muted">-</span>'}</td>
                <td style="font-size:12.5px">${r.issues.map((i) => `<div style="margin:2px 0"><span class="pill ${ISSUE[i.code][1]}">${ISSUE[i.code][0]}</span> ${escapeHtml(i.text)}</div>`).join('')}</td>
              </tr>`).join('')}</tbody></table></div>`
            : `<div class="empty" style="padding:14px">${rows.length ? 'ไม่มีสินค้าในกลุ่มนี้' : 'ไม่พบสินค้าที่ข้อมูลสลับลำดับหรือไม่ตรงเอกสาร'}</div>`}
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-only]').forEach((b) => b.addEventListener('click', () => { only = b.dataset.only; draw(); }));
        box.querySelectorAll('[data-product]').forEach((b) => b.addEventListener('click', () => openProductModal(b.dataset.product, navigate && ((page) => { close(); navigate(page); }))));
    }
    draw();
}
