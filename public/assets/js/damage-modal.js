import { api } from './api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from './util.js';
import { alertDialog, confirmDialog, promptDialog } from './dialog.js';
import { skBlock } from './skeleton.js';
import { openDetailModal } from './detail-modal.js';

// Damaged goods: pieces of a store product that cannot be sold (broken in a big pack, spoiled,
// expired...) written off stock at the cost per piece of the day, as a DG-... record, with the
// reason and the purchase they came with, for loss statistics per reason / product / supplier.

function backdrop(width) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:${width}px"><div class="modal-body">${skBlock(5, 6)}</div></div>`;
    document.body.appendChild(box);
    return box;
}

function bindEsc(box, close) {
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
}

async function askCancel(rec) {
    const reason = await promptDialog(
        `${count(rec.qty)} ชิ้นของ «${rec.product_name}» จะถูกคืนกลับเข้าสต๊อก`,
        { title: `ยกเลิกรายการสินค้าเสียหาย ${rec.doc_no}?`, okText: 'ยกเลิกรายการ', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
    );
    if (reason === null || reason === undefined || !String(reason).trim()) return false;
    await api.post(`/inventory/damages/${rec.id}/cancel`, { reason: String(reason).trim() });
    return true;
}

function openDoc(docNo) {
    openDetailModal(async () => (await api.get(`/inventory/document?kind=damage&doc_no=${encodeURIComponent(docNo)}`)).data);
}

/** Record damaged pieces of one store product. */
export async function openDamageModal(productId, { onDone } = {}) {
    const box = backdrop(760);
    let changed = false;
    let unbind = () => {};
    const close = () => {
        box.remove();
        unbind();
        if (changed) onDone?.();
    };
    unbind = bindEsc(box, close);

    let info;
    try {
        info = (await api.get(`/inventory/${productId}/damage`)).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    const blank = () => ({
        qty: '', reason: 'received', damage_date: todayISO(),
        purchase_document_id: info.purchases[0] ? String(info.purchases[0].id) : '', note: '',
    });
    let f = blank();
    let busy = false;
    let error = null;
    const p = () => info.product;

    function totalHtml() {
        const q = Math.max(0, Math.floor(Number(f.qty))) || 0;
        const left = p().qty_stock - q;
        return `ตัดสต๊อก <b>${count(q)}</b> ชิ้น · มูลค่าทุนที่เสียหาย <b>฿${money(q * p().cost_price)}</b> ·
          ${left < 0 ? `<b style="color:var(--bad)">เกินคงเหลือ ${count(-left)} ชิ้น</b>` : `เหลือ ${count(left)} ชิ้น`}`;
    }

    function historyHtml() {
        if (!info.history.length) return '';
        return `<details style="margin-top:14px" ${info.history.length ? 'open' : ''}>
          <summary style="cursor:pointer;font-weight:600;font-size:14px">ประวัติสินค้าเสียหายของสินค้านี้ (${count(info.history.length)})</summary>
          <div class="table-wrap" style="margin-top:8px;max-height:260px"><table>
            <thead><tr><th>วันที่พบ</th><th>เลขที่</th><th>สาเหตุ</th><th class="num">จำนวน</th><th class="num">มูลค่าทุน</th><th></th></tr></thead>
            <tbody>${info.history.map((h) => `<tr style="${h.cancelled_at ? 'opacity:.6' : ''}">
              <td style="white-space:nowrap">${escapeHtml(fmtDate(h.damage_date))}</td>
              <td style="white-space:nowrap"><button type="button" class="prod-link" data-doc="${escapeHtml(h.doc_no)}">${escapeHtml(h.doc_no)}</button>
                <div class="muted" style="font-size:11.5px">${escapeHtml(h.user_name || '-')}</div></td>
              <td style="font-size:12.5px">${escapeHtml(h.reason_label)}${h.supplier_name ? `<div class="muted">${escapeHtml(h.supplier_name)}${h.purchase_doc_no ? ` · ${escapeHtml(h.purchase_doc_no)}` : ''}</div>` : ''}
                ${h.note ? `<div class="muted">${escapeHtml(h.note)}</div>` : ''}
                ${h.cancelled_at ? `<div><span class="pill muted">ยกเลิกแล้ว: ${escapeHtml(h.cancel_reason || '')}</span></div>` : ''}</td>
              <td class="num">${count(h.qty)}</td>
              <td class="num">${money(h.value)}</td>
              <td>${h.cancelled_at ? '' : `<button type="button" class="btn secondary sm" data-cancel="${h.id}" ${busy ? 'disabled' : ''} title="คืนจำนวนนี้กลับเข้าสต๊อก (บันทึกผิด)">ยกเลิก</button>`}</td>
            </tr>`).join('')}</tbody></table></div>
        </details>`;
    }

    function draw() {
        const src = p();
        const can = src.consignor_id == null && src.qty_stock > 0;
        box.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="width:760px">
            <div class="modal-head">
              <h2>บันทึกสินค้าเสียหาย: ${escapeHtml(src.name)}</h2>
              <button type="button" class="modal-close" data-x title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              <p class="muted" style="margin:0 0 10px;font-size:13px">ตัดสินค้าที่ชำรุด/ขายไม่ได้ออกจากสต๊อก (เช่น ซื้อแพ็คใหญ่แล้วมีบางชิ้นแตก รั่ว บุบ)
                คิดมูลค่าตามต้นทุน/ชิ้นปัจจุบัน และเก็บสาเหตุไว้ทำสถิติ · ยกเลิกภายหลังได้ (คืนเข้าสต๊อก)</p>
              <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:12px;font-size:13.5px">
                <span>คงเหลือ <b>${count(src.qty_stock)}</b> ชิ้น</span>
                <span>ต้นทุน/ชิ้น <b>฿${money(src.cost_price)}</b></span>
                <span>ราคาขาย/ชิ้น <b>฿${money(src.sale_price)}</b></span>
                ${src.barcode ? `<span class="muted">บาร์โค้ด ${escapeHtml(src.barcode)}</span>` : ''}
              </div>
              ${src.consignor_id != null ? '<div class="error-box">บันทึกได้เฉพาะสินค้าของร้าน (สินค้าฝากขายที่เสียหายให้คืนผู้ฝาก)</div>'
                : src.qty_stock < 1 ? '<div class="error-box">สินค้านี้ไม่มีคงเหลือให้ตัด — ถ้ามีของจริงอยู่ ให้ตรวจนับสต๊อกก่อน</div>' : `
              <div style="display:flex;flex-wrap:wrap;gap:10px 14px">
                <label class="field" style="width:130px">จำนวนที่เสียหาย (ชิ้น)
                  <input id="dg-qty" type="number" min="1" max="${src.qty_stock}" step="1" inputmode="numeric" value="${escapeHtml(f.qty)}"></label>
                <label class="field" style="width:160px">วันที่พบ
                  <input id="dg-date" type="date" max="${todayISO()}" value="${escapeHtml(f.damage_date)}"></label>
                <label class="field" style="flex:1;min-width:220px">สาเหตุ
                  <select id="dg-reason">${Object.entries(info.reasons).map(([k, l]) => `<option value="${k}" ${f.reason === k ? 'selected' : ''}>${escapeHtml(l)}</option>`).join('')}</select></label>
              </div>
              <label class="field" style="display:block;margin-top:10px">มาจากใบซื้อ (ใช้ทำสถิติแยกตามร้านที่ซื้อ)
                <select id="dg-purchase" style="width:100%">
                  <option value="">ไม่ระบุ</option>
                  ${info.purchases.map((x) => `<option value="${x.id}" ${String(x.id) === f.purchase_document_id ? 'selected' : ''}>${escapeHtml(fmtDate(x.date))} · ${escapeHtml(x.doc_no)} · ${escapeHtml(x.supplier_name || '-')} · ${count(x.units)} ชิ้น</option>`).join('')}
                </select></label>
              <label class="field" style="display:block;margin-top:10px">หมายเหตุ${f.reason === 'other' ? ' (จำเป็น)' : ''}
                <input id="dg-note" maxlength="255" value="${escapeHtml(f.note)}" placeholder="เช่น กล่องบุบ ขวดรั่ว 2 ขวด" style="width:100%"></label>
              <div id="dg-total" style="margin-top:10px;font-size:13.5px">${totalHtml()}</div>
              ${error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(error)}</div>` : ''}`}
              ${historyHtml()}
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-x>ปิด</button>
              ${can ? `<button type="button" class="btn danger" id="dg-save" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึกสินค้าเสียหาย'}</button>` : ''}
            </div>
          </div>`;
        bind();
    }

    function bind() {
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        const qty = box.querySelector('#dg-qty');
        qty?.addEventListener('input', () => { f.qty = qty.value; box.querySelector('#dg-total').innerHTML = totalHtml(); });
        qty?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
        box.querySelector('#dg-date')?.addEventListener('change', (e) => { f.damage_date = e.target.value; });
        box.querySelector('#dg-reason')?.addEventListener('change', (e) => { f.reason = e.target.value; draw(); });
        box.querySelector('#dg-purchase')?.addEventListener('change', (e) => { f.purchase_document_id = e.target.value; });
        box.querySelector('#dg-note')?.addEventListener('input', (e) => { f.note = e.target.value; });
        box.querySelector('#dg-save')?.addEventListener('click', save);
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => openDoc(b.dataset.doc)));
        box.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', async () => {
            const h = info.history.find((x) => String(x.id) === b.dataset.cancel);
            try {
                if (!(await askCancel(h))) return;
                changed = true;
                info = (await api.get(`/inventory/${productId}/damage`)).data;
                draw();
            } catch (e) {
                alertDialog(e.message, { tone: 'error' });
            }
        }));
    }

    async function save() {
        error = null;
        const q = Number(f.qty);
        if (!Number.isInteger(q) || q < 1) error = 'กรุณาระบุจำนวนชิ้นที่เสียหาย (จำนวนเต็มตั้งแต่ 1)';
        else if (q > p().qty_stock) error = `ตัดได้ไม่เกินยอดคงเหลือ ${count(p().qty_stock)} ชิ้น`;
        else if (!f.damage_date) error = 'กรุณาระบุวันที่พบ';
        else if (f.reason === 'other' && !f.note.trim()) error = 'สาเหตุ "อื่น ๆ" กรุณาระบุรายละเอียดในหมายเหตุ';
        if (error) { draw(); return; }
        const ok = await confirmDialog(
            `ตัด «${p().name}» ออกจากสต๊อก ${count(q)} ชิ้น มูลค่าทุน ฿${money(q * p().cost_price)} (${info.reasons[f.reason]})`,
            { title: 'บันทึกสินค้าเสียหาย?', okText: 'บันทึก', danger: true }
        );
        if (!ok) return;
        busy = true;
        draw();
        try {
            const out = (await api.post(`/inventory/${productId}/damage`, {
                qty: q, reason: f.reason, damage_date: f.damage_date, note: f.note.trim(),
                purchase_document_id: f.purchase_document_id ? Number(f.purchase_document_id) : null,
            })).data;
            changed = true;
            info = out;
            f = blank();
            busy = false;
            draw();
            alertDialog(`บันทึกสินค้าเสียหาย ${out.record?.doc_no || ''} แล้ว ตัดสต๊อก ${count(out.record?.qty || q)} ชิ้น`, { title: 'สำเร็จ' });
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    draw();
    box.querySelector('#dg-qty')?.focus();
}

const RANGES = [
    ['month', 'เดือนนี้'], ['last_month', 'เดือนก่อน'], ['term', '4 เดือนล่าสุด'], ['year', 'ปีนี้'], ['7d', '7 วันล่าสุด'], ['custom', 'กำหนดเอง'],
];
const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const monthLabel = (m) => `${MONTHS[Number(m.slice(5, 7)) - 1]} ${Number(m.slice(0, 4)) + 543}`;
const pct = (v) => (v === null || v === undefined ? '<span class="muted">-</span>' : `${v}%`);

/** Statistics of damaged goods in a period, with the records (cancel / open each one). */
export async function openDamageReport({ canEdit = false, onChange } = {}) {
    const box = backdrop(1000);
    let changed = false;
    let unbind = () => {};
    const close = () => {
        box.remove();
        unbind();
        if (changed) onChange?.();
    };
    unbind = bindEsc(box, close);

    let range = 'month';
    let from = todayISO();
    let to = todayISO();
    let d = null;
    let tab = 'product';

    async function load() {
        const params = new URLSearchParams({ range });
        if (range === 'custom') { params.set('from', from); params.set('to', to); }
        try {
            d = (await api.get(`/inventory/damages/report?${params}`)).data;
            from = d.from;
            to = d.to;
            draw();
        } catch (e) {
            close();
            alertDialog(e.message, { tone: 'error' });
        }
    }

    function table(head, rows) {
        if (!rows.length) return '<div class="empty" style="padding:14px">ไม่มีสินค้าเสียหายในช่วงนี้</div>';
        return `<div class="table-wrap" style="max-height:380px;border:1px solid var(--border);border-radius:10px"><table>
          <thead><tr>${head}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
    }

    function bodyHtml() {
        const share = (v) => (d.totals.value > 0 ? `${Math.round((v / d.totals.value) * 1000) / 10}%` : '-');
        if (tab === 'reason') {
            return table('<th>สาเหตุ</th><th class="num">ครั้ง</th><th class="num">จำนวน (ชิ้น)</th><th class="num">มูลค่าทุน</th><th class="num">สัดส่วนมูลค่า</th>',
                d.by_reason.map((r) => `<tr><td>${escapeHtml(r.label)}</td><td class="num">${count(r.records)}</td><td class="num">${count(r.qty)}</td>
                  <td class="num">${money(r.value)}</td><td class="num">${share(r.value)}</td></tr>`));
        }
        if (tab === 'supplier') {
            return table('<th>ร้าน/ผู้ขาย</th><th class="num">ครั้ง</th><th class="num">เสียหาย (ชิ้น)</th><th class="num">มูลค่าทุน</th><th class="num" title="จำนวนชิ้นที่ซื้อจากร้านนี้ในช่วงเดียวกัน">ซื้อในช่วงนี้ (ชิ้น)</th><th class="num" title="เสียหาย ÷ ซื้อในช่วงนี้">อัตราเสียหาย</th>',
                d.by_supplier.map((r) => `<tr><td>${r.supplier_name ? escapeHtml(r.supplier_name) : '<span class="muted">ไม่ระบุใบซื้อ</span>'}</td>
                  <td class="num">${count(r.records)}</td><td class="num">${count(r.qty)}</td><td class="num">${money(r.value)}</td>
                  <td class="num">${r.bought_qty === null ? '<span class="muted">-</span>' : count(r.bought_qty)}</td><td class="num">${pct(r.rate)}</td></tr>`));
        }
        if (tab === 'month') {
            const max = Math.max(...d.by_month.map((m) => m.value), 0);
            return table('<th>เดือน</th><th class="num">ครั้ง</th><th class="num">จำนวน (ชิ้น)</th><th class="num">มูลค่าทุน</th><th style="width:40%"></th>',
                d.by_month.map((m) => `<tr><td>${escapeHtml(monthLabel(m.month))}</td><td class="num">${count(m.records)}</td><td class="num">${count(m.qty)}</td>
                  <td class="num">${money(m.value)}</td>
                  <td><div style="height:10px;border-radius:5px;background:var(--bad);opacity:.75;width:${max > 0 ? Math.max(2, (m.value / max) * 100) : 0}%"></div></td></tr>`));
        }
        if (tab === 'records') {
            return table('<th>วันที่พบ</th><th>เลขที่</th><th>สินค้า</th><th>สาเหตุ / ที่มา</th><th class="num">จำนวน</th><th class="num">ทุน/ชิ้น</th><th class="num">มูลค่าทุน</th><th></th>',
                d.records.map((r) => `<tr style="${r.cancelled_at ? 'opacity:.6' : ''}">
                  <td style="white-space:nowrap">${escapeHtml(fmtDate(r.damage_date))}<div class="muted" style="font-size:11.5px">บันทึก ${escapeHtml(fmtDateTime(r.created_at))}</div></td>
                  <td style="white-space:nowrap"><button type="button" class="prod-link" data-doc="${escapeHtml(r.doc_no)}">${escapeHtml(r.doc_no)}</button>
                    <div class="muted" style="font-size:11.5px">${escapeHtml(r.user_name || '-')}</div></td>
                  <td>${escapeHtml(r.product_name)}${r.barcode ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.barcode)}</div>` : ''}</td>
                  <td style="font-size:12.5px">${escapeHtml(r.reason_label)}${r.supplier_name ? `<div class="muted">${escapeHtml(r.supplier_name)}${r.purchase_doc_no ? ` · ${escapeHtml(r.purchase_doc_no)}` : ''}</div>` : ''}
                    ${r.note ? `<div class="muted">${escapeHtml(r.note)}</div>` : ''}
                    ${r.cancelled_at ? `<div><span class="pill muted">ยกเลิกแล้ว: ${escapeHtml(r.cancel_reason || '')}</span></div>` : ''}</td>
                  <td class="num">${count(r.qty)}</td><td class="num">${money(r.unit_cost)}</td><td class="num">${money(r.value)}</td>
                  <td>${canEdit && !r.cancelled_at ? `<button type="button" class="btn secondary sm" data-cancel="${r.id}" title="คืนจำนวนนี้กลับเข้าสต๊อก (บันทึกผิด)">ยกเลิก</button>` : ''}</td>
                </tr>`));
        }
        return table('<th>สินค้า</th><th class="num">ครั้ง</th><th class="num">เสียหาย (ชิ้น)</th><th class="num">มูลค่าทุน</th><th class="num" title="จำนวนชิ้นที่ซื้อเข้าในช่วงเดียวกัน">ซื้อในช่วงนี้ (ชิ้น)</th><th class="num" title="เสียหาย ÷ ซื้อในช่วงนี้">อัตราเสียหาย</th><th>สาเหตุหลัก</th>',
            d.by_product.map((r) => `<tr><td>${escapeHtml(r.product_name)}${r.barcode ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.barcode)}</div>` : ''}</td>
              <td class="num">${count(r.records)}</td><td class="num">${count(r.qty)}</td><td class="num">${money(r.value)}</td>
              <td class="num">${count(r.bought_qty)}</td><td class="num">${pct(r.rate)}</td><td style="font-size:12.5px">${escapeHtml(r.main_reason)}</td></tr>`));
    }

    function draw() {
        const t = d.totals;
        const top = d.by_reason[0];
        const tabs = [['product', 'ตามสินค้า'], ['reason', 'ตามสาเหตุ'], ['supplier', 'ตามร้านที่ซื้อ'], ['month', 'รายเดือน'], ['records', `รายการทั้งหมด (${count(d.records.length)})`]];
        box.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="width:1000px">
            <div class="modal-head">
              <h2>รายงานสินค้าเสียหาย</h2>
              <button type="button" class="modal-close" data-x title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              <div class="actions-row" style="flex-wrap:wrap;gap:8px 12px;margin-bottom:12px">
                <label class="field">ช่วงเวลา (วันที่พบ)
                  <select id="dr-range">${RANGES.map(([k, l]) => `<option value="${k}" ${range === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
                <label class="field">ตั้งแต่ <input type="date" id="dr-from" value="${from}"></label>
                <label class="field">ถึง <input type="date" id="dr-to" value="${to}"></label>
              </div>
              <div class="kpi-grid" style="margin-bottom:14px">
                <div class="kpi"><div class="lbl">มูลค่าทุนที่เสียหาย</div><div class="val" style="color:var(--bad)">฿ ${money(t.value)}</div>
                  <div class="sub">${escapeHtml(fmtDate(d.from))}${d.from !== d.to ? ` – ${escapeHtml(fmtDate(d.to))}` : ''}</div></div>
                <div class="kpi"><div class="lbl">จำนวนที่เสียหาย</div><div class="val">${count(t.qty)} ชิ้น</div>
                  <div class="sub">${count(t.products)} สินค้า · ${count(t.records)} ครั้ง${t.cancelled ? ` · ยกเลิก ${count(t.cancelled)}` : ''}</div></div>
                <div class="kpi"><div class="lbl">สาเหตุหลัก</div><div class="val" style="font-size:18px">${top ? escapeHtml(top.label) : '-'}</div>
                  <div class="sub">${top ? `฿ ${money(top.value)} · ${count(top.qty)} ชิ้น` : 'ไม่มีข้อมูล'}</div></div>
              </div>
              <div class="tabs" role="group" aria-label="มุมมองรายงาน" style="margin-bottom:10px">
                ${tabs.map(([k, l]) => `<button type="button" class="tab-btn ${k === tab ? 'active' : ''}" data-tab="${k}">${l}</button>`).join('')}
              </div>
              ${bodyHtml()}
              <div class="muted" style="font-size:12px;margin-top:8px">มูลค่าคิดตามต้นทุน/ชิ้น ณ วันที่บันทึก · รายการที่ยกเลิกไม่นับในตัวเลข · อัตราเสียหาย = ชิ้นที่เสียหาย ÷ ชิ้นที่ซื้อเข้าในช่วงเวลาเดียวกัน
                · ส่งออก Excel ได้ที่เมนูรายงาน → "สินค้าเสียหาย (ตัดสต๊อก)"</div>
            </div>
            <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>
          </div>`;
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { tab = b.dataset.tab; draw(); }));
        box.querySelector('#dr-range').addEventListener('change', (e) => { range = e.target.value; load(); });
        box.querySelector('#dr-from').addEventListener('change', (e) => { range = 'custom'; from = e.target.value; load(); });
        box.querySelector('#dr-to').addEventListener('change', (e) => { range = 'custom'; to = e.target.value; load(); });
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => openDoc(b.dataset.doc)));
        box.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', async () => {
            const r = d.records.find((x) => String(x.id) === b.dataset.cancel);
            try {
                if (!(await askCancel(r))) return;
                changed = true;
                await load();
            } catch (e) {
                alertDialog(e.message, { tone: 'error' });
            }
        }));
    }

    await load();
}
