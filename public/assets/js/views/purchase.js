import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { uploadImage, attachmentUrl, imagesFromClipboard } from '../images.js';
import { printPurchaseDoc } from '../print-slip.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

/**
 * ซื้อสินค้าเข้าร้าน: store-owned purchases. Receipt photos (one receipt may
 * span several overlapping photos) can be read by a vision model into a
 * draft that the user reviews here before saving.
 */
export const DRAFT_KEY = 'purchase.draft';

const MAX_PHOTOS = 10;

function emptyHeader() {
    return { supplier_name: '', supplier_tax_id: '', receipt_no: '', receipt_date: '', discount: 0, vat: 0, total: 0, note: '' };
}
function emptyItem() {
    // name = per-piece product name; qty/unit_cost/line_total = as printed (packs); sale_price = per piece
    // item_type: 'stock' = bought to sell, 'supply' = used in the business (cost only, no stock/price)
    // product_id/origName: set when editing a saved purchase, so an unrenamed line stays on the same product
    return { name: '', receipt_name: '', item_type: 'stock', typeTouched: false, pack_size: 1, barcode: '', qty: 1, unit_cost: 0, line_total: 0, sale_price: 0, priceTouched: false, match: null, suggested: 0, product_id: null, origName: '' };
}
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

export async function render(container) {
    let photos = []; // [{ token, preview }]
    let header = emptyHeader();
    let items = [emptyItem()];
    let warnings = [];
    let source = 'manual';
    let models = [];
    let modelId = null;
    let busy = null; // 'upload' | 'extract' | 'save'
    let error = null;
    let success = null;
    let docs = [];
    let markup = 20;
    let viewing = null; // purchase doc shown in the modal
    let editing = null; // { id, doc_no } while the form holds a saved purchase
    let formOpen = false; // the purchase form modal
    let previewTimer = null;

    /** Applies a server preview to an item; returns true if the row needs redrawing. */
    function applyPreview(it, p) {
        // Lines are for sale unless the user picks "ใช้ในกิจการ"; history only adds a hint.
        const hintBefore = it.prevSupply;
        it.prevSupply = p.previous_type === 'supply';
        it.match = p.match;
        // A known pack barcode fills in how many pieces the pack holds.
        const packFilled = p.pack_size > 1 && Number(it.pack_size) <= 1;
        if (packFilled) it.pack_size = p.pack_size;
        it.suggested = p.match ? p.match.sale_price : p.suggested_price;
        if (!it.priceTouched) it.sale_price = it.suggested || 0;
        return hintBefore !== it.prevSupply || packFilled;
    }

    try {
        // Cheapest vision model first (unpriced models last) so the default costs the least.
        const price = (m) => (m.price_in == null || m.price_out == null ? Infinity : m.price_in + m.price_out);
        const res = await api.get('/ai/models');
        models = res.data.filter((m) => m.supports_vision).sort((a, b) => price(a) - price(b));
        // The admin's default model goes first when it can read photos.
        const def = models.find((m) => m.id === res.default_id);
        if (def) models = [def, ...models.filter((m) => m !== def)];
    } catch (e) { models = []; }
    try {
        const saved = Number(localStorage.getItem('purchase.model'));
        modelId = models.some((m) => m.id === saved) ? saved : (models[0]?.id ?? null);
    } catch (e) { modelId = models[0]?.id ?? null; }

    // The assistant's "แก้ไขในฟอร์ม" stores a draft and (re)opens this route.
    loadDraftFromAssistant();
    await loadDocs();
    draw();
    if (items.some((i) => i.name)) refreshPreview();

    function loadDraftFromAssistant() {
        let draft = null;
        try {
            draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
            sessionStorage.removeItem(DRAFT_KEY);
        } catch (e) { draft = null; }
        if (draft) {
            applyDraft(draft, (draft.attachments || []).map((token) => ({ token, preview: attachmentUrl(token) })));
            formOpen = true;
        }
    }

    function applyDraft(d, newPhotos) {
        header = {
            ...emptyHeader(),
            supplier_name: d.supplier_name || '', supplier_tax_id: d.supplier_tax_id || '', receipt_no: d.receipt_no || '',
            receipt_date: d.receipt_date || '', discount: d.discount || 0, vat: d.vat || 0, total: d.total || 0, note: d.note || '',
        };
        items = (d.items || []).map((it) => ({
            ...emptyItem(), name: it.product_name || it.name, receipt_name: it.name, pack_size: it.pack_size || 1,
            item_type: it.item_type === 'supply' ? 'supply' : 'stock', typeTouched: !!it.item_type,
            barcode: it.barcode || '', qty: it.qty, unit_cost: it.unit_cost,
            line_total: it.line_total, sale_price: it.sale_price || 0, priceTouched: !!it.sale_price,
        }));
        if (!items.length) items = [emptyItem()];
        warnings = d.warnings || [];
        source = 'ai';
        if (newPhotos) photos = newPhotos;
    }

    /** Loads a saved purchase into the form; saving then updates it in place. */
    function startEdit(d) {
        editing = { id: Number(d.id), doc_no: d.doc_no };
        header = {
            ...emptyHeader(),
            supplier_name: d.supplier_name || '', supplier_tax_id: d.supplier_tax_id || '', receipt_no: d.receipt_no || '',
            receipt_date: d.receipt_date || '', discount: Number(d.discount) || 0, vat: Number(d.vat) || 0, total: Number(d.total) || 0, note: d.note || '',
        };
        items = d.items.map((it) => {
            const name = (it.item_type === 'stock' && it.product_name) || it.receipt_name;
            return {
                ...emptyItem(), name, origName: name, product_id: it.product_id ? Number(it.product_id) : null,
                receipt_name: it.receipt_name, item_type: it.item_type === 'supply' ? 'supply' : 'stock', typeTouched: true,
                pack_size: Number(it.pack_size) || 1, barcode: it.barcode || '', qty: Number(it.qty), unit_cost: Number(it.unit_cost),
                line_total: Number(it.line_total), sale_price: Number(it.sale_price) || 0, priceTouched: it.item_type === 'stock',
            };
        });
        if (!items.length) items = [emptyItem()];
        photos = d.images.map((im) => ({ token: im.token, preview: attachmentUrl(im.token) }));
        warnings = [];
        source = d.source;
        viewing = null;
        error = null;
        success = null;
        formOpen = true;
        draw();
        refreshPreview();
    }

    function clearForm() {
        photos = []; header = emptyHeader(); items = [emptyItem()]; warnings = []; source = 'manual'; editing = null;
    }

    async function loadDocs() {
        const res = await api.get('/purchases');
        docs = res.data;
        markup = res.markup_percent;
    }

    // ---------------- Rendering ----------------

    /** The purchase form, shown on a modal from «+ ซื้อสินค้าเข้าร้าน» or «แก้ไข». */
    function formHtml() {
        const subtotal = round2(items.reduce((s, i) => s + Number(i.line_total || 0), 0));
        return `
        <div class="modal-backdrop">
          <div class="modal pc-modal" role="dialog" aria-modal="true" aria-labelledby="pc-form-title">
            <div class="modal-head">
              <h2 id="pc-form-title">${editing ? `แก้ไขใบซื้อ ${escapeHtml(editing.doc_no)}` : 'ซื้อสินค้าเข้าร้าน'}</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body pc-modal-body">
        ${editing ? `<div class="pc-warn" style="margin:0">
          <div><b>กำลังแก้ไขใบซื้อ ${escapeHtml(editing.doc_no)}</b><br>
            <span style="font-size:12.5px">เมื่อบันทึก ระบบจะหักสต๊อกตามใบเดิมออกแล้วรับเข้าตามรายการใหม่ (เลขที่ใบซื้อเดิม) — สินค้าที่ขายไปแล้วจะลดจำนวนได้ไม่เกินที่ยังเหลือในสต๊อก</span></div>
        </div>` : ''}

        <section class="card card-pad">
          <div class="step-title"><span class="step-num">1</span><h2>รูปใบเสร็จ</h2>
            <span class="muted" style="font-size:12.5px;margin-left:auto">${photos.length}/${MAX_PHOTOS} รูป</span></div>
          <div class="pc-photos" id="pc-photos">
            ${photos.map((p, i) => `
              <div class="pc-photo">
                <img src="${escapeHtml(p.preview)}" alt="รูปที่ ${i + 1}">
                <span class="pc-photo-no">${i + 1}</span>
                <div class="pc-photo-tools">
                  <button type="button" data-move="${i}" data-dir="-1" title="เลื่อนขึ้นก่อน" ${i === 0 ? 'disabled' : ''}>◀</button>
                  <button type="button" data-move="${i}" data-dir="1" title="เลื่อนไปหลัง" ${i === photos.length - 1 ? 'disabled' : ''}>▶</button>
                  <button type="button" data-remove-photo="${i}" title="ลบรูป">✕</button>
                </div>
              </div>`).join('')}
            ${photos.length < MAX_PHOTOS ? `
              <label class="pc-add" title="เพิ่มรูป (หรือวางรูปด้วย Ctrl+V)">
                <input type="file" accept="image/*" multiple id="pc-file" hidden>
                <span style="font-size:26px;line-height:1">＋</span>
                <span>${busy === 'upload' ? 'กำลังอัปโหลด...' : 'เพิ่มรูป'}</span>
              </label>` : ''}
          </div>
          <div class="actions-row" style="margin-top:14px">
            ${models.length ? `
              <select id="pc-model" class="pc-select" title="โมเดลที่ใช้อ่านรูป">
                ${models.map((m) => `<option value="${m.id}" ${m.id === modelId ? 'selected' : ''}>${escapeHtml(m.label)} · ${escapeHtml(m.provider_name)}</option>`).join('')}
              </select>
              <button type="button" class="btn" id="pc-extract" ${photos.length && !busy ? '' : 'disabled'}>
                ${busy === 'extract' ? 'กำลังอ่านใบเสร็จ...' : '✨ อ่านใบเสร็จด้วย AI'}
              </button>`
            : '<span class="muted" style="font-size:13px">ยังไม่มีโมเดล AI ที่อ่านรูปได้ — เปิดใช้ได้ที่หน้า "ตั้งค่าผู้ช่วย AI" (ติ๊ก "อ่านรูป") หรือกรอกรายการเองด้านล่าง</span>'}
          </div>
        </section>

        <section class="card card-pad">
          <div class="step-title"><span class="step-num">2</span><h2>ข้อมูลใบเสร็จ</h2></div>
          <div class="form-grid">
            <label class="field" style="grid-column:span 2">ร้าน/ผู้ขาย <input data-h="supplier_name" value="${escapeHtml(header.supplier_name)}" placeholder="เช่น แม็คโคร สาขา..."></label>
            <label class="field">เลขประจำตัวผู้เสียภาษี <input data-h="supplier_tax_id" value="${escapeHtml(header.supplier_tax_id)}"></label>
            <label class="field">เลขที่ใบเสร็จ <input data-h="receipt_no" value="${escapeHtml(header.receipt_no)}"></label>
            <label class="field">วันที่ในใบเสร็จ <input data-h="receipt_date" type="date" value="${escapeHtml(header.receipt_date)}"></label>
            <label class="field">หมายเหตุ <input data-h="note" value="${escapeHtml(header.note)}"></label>
          </div>
        </section>

        <section class="card">
          <div class="card-head">
            <div class="step-title" style="margin:0"><span class="step-num">3</span><h2>รายการสินค้า (${items.length})</h2></div>
            <button type="button" class="btn secondary sm" id="pc-add-row">+ เพิ่มรายการ</button>
          </div>
          <div class="table-wrap"><table class="pc-items">
            <thead><tr><th>#</th><th style="min-width:200px">ชื่อสินค้า (ขายแยกชิ้น)</th><th title="ซื้อมาขาย หรือซื้อมาใช้ในกิจการ (บันทึกเป็นต้นทุน ไม่เข้าสต๊อก)">ประเภท</th><th title="บาร์โค้ดที่พิมพ์ในใบเสร็จ (สินค้าแพ็คคือบาร์โค้ดของแพ็ค)">บาร์โค้ด (ใบเสร็จ)</th><th class="num">จำนวนซื้อ</th><th class="num" title="จำนวนชิ้นใน 1 แพ็ค/หน่วยที่ซื้อ (ไม่ใช่แพ็ค = 1)">ชิ้น/แพ็ค</th><th class="num">ทุน/หน่วยซื้อ</th><th class="num">รวม</th><th class="num">เข้าสต๊อก</th><th class="num">ทุน/ชิ้น</th><th class="num">ราคาขาย/ชิ้น</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
              ${items.map((it, i) => `
                <tr data-row="${i}">
                  <td class="muted">${i + 1}</td>
                  <td><input data-f="name" value="${escapeHtml(it.name)}">${it.receipt_name && it.receipt_name !== it.name ? `<div class="muted" style="font-size:11.5px;margin-top:2px" title="ชื่อตามใบเสร็จ">ใบเสร็จ: ${escapeHtml(it.receipt_name)}</div>` : ''}</td>
                  <td><select data-type class="pc-type ${it.item_type === 'supply' ? 'supply' : ''}">
                    <option value="stock" ${it.item_type === 'stock' ? 'selected' : ''}>เพื่อขาย</option>
                    <option value="supply" ${it.item_type === 'supply' ? 'selected' : ''}>ใช้ในกิจการ</option>
                  </select>${it.prevSupply && it.item_type === 'stock' ? '<div class="muted" style="font-size:11px;margin-top:2px" title="ใบซื้อก่อนหน้าบันทึกรายการนี้เป็นใช้ในกิจการ">เคยบันทึกเป็นใช้ในกิจการ</div>' : ''}</td>
                  <td><input data-f="barcode" value="${escapeHtml(it.barcode)}" style="width:130px"></td>
                  <td><input data-f="qty" type="number" min="1" step="1" value="${it.qty}" class="num" style="width:70px"></td>
                  <td><input data-f="pack_size" type="number" min="1" step="1" value="${it.pack_size}" class="num" style="width:62px"></td>
                  <td><input data-f="unit_cost" type="number" min="0" step="0.01" value="${it.unit_cost}" class="num" style="width:90px"></td>
                  <td><input data-f="line_total" type="number" min="0" step="0.01" value="${it.line_total}" class="num" style="width:95px"></td>
                  <td class="num" data-units style="white-space:nowrap">${unitsHtml(it)}</td>
                  <td class="num" data-piece>${money(pieceCost(it))}</td>
                  <td>${it.item_type === 'supply'
                    ? '<span class="muted" title="ใช้ในกิจการ ไม่มีราคาขาย">—</span>'
                    : `<input data-f="sale_price" type="number" min="0" step="0.25" value="${it.sale_price || ''}" placeholder="${it.suggested || ''}" class="num ${priceClass(it)}" style="width:85px">
                       <div data-price-tag>${priceTagHtml(it)}</div>`}</td>
                  <td data-status>${statusHtml(it)}</td>
                  <td><button type="button" class="btn secondary sm" data-remove-row="${i}" title="ลบรายการ">✕</button></td>
                </tr>`).join('')}
            </tbody>
          </table></div>
          <div class="pc-summary" id="pc-summary">${summaryHtml(subtotal)}</div>
          <div style="padding:0 18px 18px">
            ${warnings.length ? `<div class="pc-warn"><b>ข้อสังเกตจาก AI</b><ul>${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul></div>` : ''}
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
          </div>
        </section>

            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" id="pc-reset">${editing ? 'ยกเลิกการแก้ไข' : 'ล้างฟอร์ม'}</button>
              <button type="button" class="btn" id="pc-save" ${busy ? 'disabled' : ''}>${busy === 'save' ? 'กำลังบันทึก...' : editing ? `บันทึกการแก้ไข ${escapeHtml(editing.doc_no)}` : 'บันทึกใบซื้อ'}</button>
            </div>
          </div>
        </div>`;
    }

    function draw() {
        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="hint"><div class="dot">i</div><div>
          บันทึกการซื้อสินค้าเข้าร้าน (สินค้าของร้านเอง ไม่ใช่ฝากขาย) — ถ่ายรูปใบเสร็จอย่างย่อแล้วให้ AI อ่านรายการให้ ตรวจแก้แล้วกดบันทึก
          ใบเสร็จยาวถ่ายต่อกันได้หลายรูป (เรียงจากบนลงล่าง ให้ขอบภาพซ้อนกันเล็กน้อย) ราคาในใบเสร็จคือ<b>ราคาทุน</b>
          ระบบตั้งราคาขายเริ่มต้นเป็นทุน + ${escapeHtml(markup)}% แก้ได้ทุกรายการ
        </div></div>
        <div class="actions-row" style="justify-content:space-between">
          <h2 style="font-size:15px;margin:0">ใบซื้อล่าสุด</h2>
          <button type="button" class="btn" id="pc-open">+ ซื้อสินค้าเข้าร้าน</button>
        </div>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>วันที่ใบเสร็จ</th><th>ร้าน/ผู้ขาย</th><th class="num">รายการ</th><th class="num">ยอดสุทธิ</th><th class="num" title="ส่วนที่ซื้อมาใช้ในกิจการ (ต้นทุน)">ใช้ในกิจการ</th><th>รูป</th><th>บันทึกโดย</th><th></th></tr></thead>
            <tbody>
              ${docs.map((d) => `
                <tr class="${isCancelled(d) ? 'pc-cancelled' : ''}">
                  <td style="font-weight:600;white-space:nowrap">${escapeHtml(d.doc_no)} ${d.source === 'ai' ? '<span class="pill muted" title="อ่านจากใบเสร็จด้วย AI">AI</span>' : ''}
                    ${isCancelled(d) ? `<span class="pill bad" title="${escapeHtml(d.cancel_reason || '')}">ยกเลิกแล้ว</span>` : ''}</td>
                  <td>${d.receipt_date ? fmtDate(d.receipt_date) : '-'}</td>
                  <td>${escapeHtml(d.supplier_name)}${d.receipt_no ? `<div class="muted" style="font-size:12px">${escapeHtml(d.receipt_no)}</div>` : ''}</td>
                  <td class="num">${d.item_count}</td>
                  <td class="num"><span class="pc-total">${money(d.total)}</span></td>
                  <td class="num muted">${Number(d.supply_total) ? money(d.supply_total) : '-'}</td>
                  <td>${Number(d.image_count) ? `🖼 ${d.image_count}` : ''}</td>
                  <td class="muted" style="font-size:12.5px">${escapeHtml(d.user_name || '')}<div>${fmtDateTime(d.created_at)}</div></td>
                  <td style="white-space:nowrap"><button type="button" class="btn secondary sm" data-view="${d.id}">ดู</button>
                    ${isCancelled(d) ? '' : `<button type="button" class="btn secondary sm" data-edit="${d.id}" ${editing?.id === Number(d.id) ? 'disabled title="กำลังแก้ไขอยู่"' : ''}>แก้ไข</button>
                    <button type="button" class="btn secondary sm pc-cancel-btn" data-cancel="${d.id}" data-doc="${escapeHtml(d.doc_no)}">ยกเลิก</button>`}</td>
                </tr>`).join('') || '<tr><td colspan="9" class="empty">ยังไม่มีใบซื้อ</td></tr>'}
            </tbody>
          </table></div>
        </section>
        ${formOpen ? formHtml() : ''}
        ${viewing ? viewHtml(viewing) : ''}`;
        bind();
    }

    function isCancelled(d) {
        return d.status === 'cancelled';
    }

    function unitsOf(it) {
        return Math.max(0, Number(it.qty) || 0) * Math.max(1, Number(it.pack_size) || 1);
    }
    function pieceCost(it) {
        const units = unitsOf(it);
        return units ? round2(Number(it.line_total) / units) : 0;
    }
    function unitsHtml(it) {
        const pack = Math.max(1, Number(it.pack_size) || 1);
        if (it.item_type === 'supply') return '<span class="muted" style="font-size:12px">ไม่เข้าสต๊อก</span>';
        return pack > 1 ? `<b>${unitsOf(it)}</b> <span class="muted" style="font-size:11.5px">ชิ้น</span>` : `${unitsOf(it)}`;
    }

    /** Restock lines compare the new selling price with the product's current one. */
    function priceDir(it) {
        if (it.item_type !== 'stock' || !it.match) return null;
        const old = Number(it.match.sale_price) || 0;
        const cur = Number(it.sale_price) || old;
        return cur > old + 0.004 ? 'up' : cur < old - 0.004 ? 'down' : 'same';
    }
    function priceClass(it) {
        const dir = priceDir(it);
        return dir ? `pc-price ${dir}` : '';
    }
    function priceTagHtml(it) {
        const dir = priceDir(it);
        if (!dir) return '';
        const old = Number(it.match.sale_price) || 0;
        const diff = round2(Math.abs((Number(it.sale_price) || old) - old));
        if (dir === 'same') return `<div class="pc-price-tag same" title="ราคาขายปัจจุบันของสินค้านี้">ราคาเดิม ${money(old)}</div>`;
        return dir === 'up'
            ? `<div class="pc-price-tag up" title="ปรับราคาขึ้นจากราคาเดิม ${money(old)}">↑ ${money(diff)} (เดิม ${money(old)})</div>`
            : `<div class="pc-price-tag down" title="ปรับราคาลงจากราคาเดิม ${money(old)}">↓ ${money(diff)} (เดิม ${money(old)})</div>`;
    }
    function updatePriceCell(it, tr) {
        const sp = tr?.querySelector('[data-f="sale_price"]');
        if (!sp) return;
        sp.classList.remove('pc-price', 'same', 'up', 'down');
        const cls = priceClass(it);
        if (cls) sp.classList.add(...cls.split(' '));
        tr.querySelector('[data-price-tag]').innerHTML = priceTagHtml(it);
    }

    function statusHtml(it) {
        if (!it.name) return '';
        if (it.item_type === 'supply') return '<span class="pill muted" title="บันทึกเป็นต้นทุนของร้าน">ต้นทุนกิจการ</span>';
        return it.match
            ? `<span class="pill ok" title="มีในสต๊อก ${it.match.qty_stock} ชิ้น">เติมสต๊อก</span>`
            : '<span class="pill warn">สินค้าใหม่</span>';
    }

    function supplyTotal() {
        return round2(items.filter((i) => i.item_type === 'supply').reduce((s, i) => s + Number(i.line_total || 0), 0));
    }

    function summaryHtml(subtotal) {
        const discount = Number(header.discount) || 0;
        const net = round2(subtotal - discount);
        const printed = Number(header.total) || 0;
        const mismatch = printed > 0 && Math.abs(net - printed) > 0.009;
        return `
          ${supplyTotal() > 0 ? `<span class="muted">เพื่อขาย ฿${money(subtotal - supplyTotal())} · ใช้ในกิจการ ฿${money(supplyTotal())}</span>` : ''}
          <label>รวมตามรายการ <b>฿ ${money(subtotal)}</b></label>
          <label>ส่วนลดท้ายบิล <input data-h="discount" type="number" min="0" step="0.01" value="${header.discount}"></label>
          <label>VAT (ถ้ามี) <input data-h="vat" type="number" min="0" step="0.01" value="${header.vat}"></label>
          <label>ยอดสุทธิตามใบเสร็จ <input data-h="total" type="number" min="0" step="0.01" value="${header.total}"></label>
          <div class="${mismatch ? 'pc-diff bad' : 'pc-diff'}">${printed > 0
            ? (mismatch ? `⚠ ยอดรายการหลังหักส่วนลด ฿${money(net)} ไม่ตรงกับใบเสร็จ (ต่าง ฿${money(net - printed)})` : '✓ ยอดตรงกับใบเสร็จ')
            : `ยอดสุทธิ ฿${money(net)}`}</div>
          ${vatGap(subtotal) ? `<button type="button" class="btn sm" data-add-vat>บวก VAT ฿${money(vatGap(subtotal))} เข้าราคาทุกรายการ</button>` : ''}`;
    }

    /** Receipt prices are before VAT when lines − discount + VAT = printed total; returns that VAT, else 0. */
    function vatGap(subtotal) {
        const vat = Number(header.vat) || 0;
        const net = round2(subtotal - (Number(header.discount) || 0));
        const printed = Number(header.total) || 0;
        const gap = round2(printed - net);
        return vat > 0 && net > 0 && gap > 0.009 && Math.abs(gap - vat) <= 0.05 ? gap : 0;
    }

    /** Spreads the VAT over every line (and the bill discount) so costs include it and match the printed total. */
    function addVatToLines() {
        const subtotal = round2(items.reduce((s, i) => s + Number(i.line_total || 0), 0));
        const net = round2(subtotal - (Number(header.discount) || 0));
        const printed = Number(header.total) || 0;
        if (!vatGap(subtotal)) return;
        const factor = printed / net;
        let biggest = null;
        items.forEach((it) => {
            it.line_total = round2(Number(it.line_total || 0) * factor);
            if (Number(it.qty) > 0) it.unit_cost = round2(it.line_total / Number(it.qty));
            if (!biggest || it.line_total > biggest.line_total) biggest = it;
        });
        header.discount = round2((Number(header.discount) || 0) * factor);
        // Rounding leftovers go to the largest line so the lines match the printed total exactly.
        const diff = round2(printed - (items.reduce((s, i) => s + i.line_total, 0) - header.discount));
        if (biggest && diff !== 0) {
            biggest.line_total = round2(biggest.line_total + diff);
            if (Number(biggest.qty) > 0) biggest.unit_cost = round2(biggest.line_total / Number(biggest.qty));
        }
        draw();
        refreshPreview();
    }

    function viewHtml(d) {
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:900px">
            <div class="modal-head">
              <h2>ใบซื้อ ${escapeHtml(d.doc_no)}</h2>
              <button type="button" class="modal-close" data-close-view title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              ${isCancelled(d) ? `<div class="error-box" style="margin-bottom:12px">
                <b>ใบซื้อนี้ถูกยกเลิกแล้ว</b> เมื่อ ${fmtDateTime(d.cancelled_at)}${d.cancelled_by_name ? ` โดย ${escapeHtml(d.cancelled_by_name)}` : ''}
                <div>เหตุผล: ${escapeHtml(d.cancel_reason || '-')}</div>
                <div style="font-size:12.5px">สินค้าตามรายการด้านล่างถูกหักออกจากสต๊อกแล้ว และไม่นับรวมในรายงาน</div></div>` : ''}
              <div class="stat-row" style="margin-bottom:14px;font-size:13.5px">
                <div><div class="muted">ร้าน/ผู้ขาย</div><b>${escapeHtml(d.supplier_name)}</b>${d.supplier_tax_id ? `<div class="muted">${escapeHtml(d.supplier_tax_id)}</div>` : ''}</div>
                <div><div class="muted">เลขที่ / วันที่ใบเสร็จ</div><b>${escapeHtml(d.receipt_no || '-')}</b><div>${d.receipt_date ? fmtDate(d.receipt_date) : '-'}</div></div>
                <div><div class="muted">ยอดสุทธิ</div><b>฿ ${money(d.total)}</b><div class="muted">ส่วนลด ฿${money(d.discount)} · VAT ฿${money(d.vat)}</div></div>
              </div>
              <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
                <thead><tr><th>สินค้า</th><th class="num">จำนวนซื้อ</th><th class="num">ทุน/หน่วยซื้อ</th><th class="num">รวม</th><th class="num">เข้าสต๊อก (ชิ้น)</th><th class="num">ทุน/ชิ้น</th><th class="num">ขาย/ชิ้น</th><th></th></tr></thead>
                <tbody>${d.items.map((it) => `
                  <tr><td>${escapeHtml(it.receipt_name)}${it.product_name && it.product_name !== it.receipt_name ? `<div class="muted" style="font-size:12px">→ ${escapeHtml(it.product_name)}</div>` : ''}${it.barcode ? `<div class="muted" style="font-size:11.5px">บาร์โค้ด${Number(it.pack_size) > 1 ? 'แพ็ค' : ''}: ${escapeHtml(it.barcode)}</div>` : ''}</td>
                      <td class="num">${it.qty}${Number(it.pack_size) > 1 ? ` × ${it.pack_size}` : ''}</td><td class="num">${money(it.unit_cost)}</td><td class="num">${money(it.line_total)}</td>
                      <td class="num">${it.item_type === 'supply' ? '—' : it.units}</td><td class="num">${money(it.piece_cost)}</td>
                      <td class="num">${it.item_type === 'supply' ? '—' : money(it.sale_price)}</td>
                      <td>${it.item_type === 'supply' ? '<span class="pill muted">ใช้ในกิจการ</span>' : Number(it.is_new_product) ? '<span class="pill warn">สินค้าใหม่</span>' : '<span class="pill ok">เติมสต๊อก</span>'}</td></tr>`).join('')}
                </tbody></table></div>
              ${d.images.length ? `<h2 style="font-size:14px;margin:16px 0 8px">รูปใบเสร็จ (${d.images.length})</h2>
                <div class="pc-photos">${d.images.map((im, i) => `<a class="pc-photo" href="${escapeHtml(attachmentUrl(im.token))}" target="_blank" rel="noopener"><img src="${escapeHtml(attachmentUrl(im.token))}" alt="รูปที่ ${i + 1}"><span class="pc-photo-no">${i + 1}</span></a>`).join('')}</div>` : ''}
              <p class="muted" style="font-size:12.5px;margin:12px 0 0">บันทึกโดย ${escapeHtml(d.user_name || '-')} · ${fmtDateTime(d.created_at)}${d.note ? ` · ${escapeHtml(d.note)}` : ''}</p>
            </div>
            <div class="modal-foot">${isCancelled(d) ? '<span></span>' : `<button type="button" class="btn secondary pc-cancel-btn" data-cancel="${d.id}" data-doc="${escapeHtml(d.doc_no)}">ยกเลิกใบซื้อนี้</button>`}<div class="actions-row">
              <button type="button" class="btn secondary" data-print-view>🖨 พิมพ์</button>
              ${isCancelled(d) ? '' : `<button type="button" class="btn" data-edit="${d.id}">แก้ไขใบซื้อนี้</button>`}
              <button type="button" class="btn secondary" data-close-view>ปิด</button></div></div>
          </div>
        </div>`;
    }

    function redrawSummary() {
        const el = container.querySelector('#pc-summary');
        if (!el) return;
        const subtotal = round2(items.reduce((s, i) => s + Number(i.line_total || 0), 0));
        const focused = document.activeElement?.dataset?.h;
        const pos = document.activeElement?.selectionStart;
        el.innerHTML = summaryHtml(subtotal);
        bindHeaderInputs(el);
        if (focused) {
            const inp = el.querySelector(`[data-h="${focused}"]`);
            if (inp) { inp.focus(); try { inp.setSelectionRange(pos, pos); } catch (e) { /* number inputs */ } }
        }
    }

    // ---------------- Events ----------------

    function bindHeaderInputs(root) {
        root.querySelectorAll('[data-h]').forEach((inp) => inp.addEventListener('input', () => {
            header[inp.dataset.h] = inp.value;
            if (['discount', 'total', 'vat'].includes(inp.dataset.h)) redrawSummary();
        }));
        root.querySelector('[data-add-vat]')?.addEventListener('click', addVatToLines);
    }

    function bind() {
        const fileInput = container.querySelector('#pc-file');
        fileInput?.addEventListener('change', () => addPhotos([...fileInput.files]));
        container.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', () => {
            const i = Number(b.dataset.move);
            const j = i + Number(b.dataset.dir);
            [photos[i], photos[j]] = [photos[j], photos[i]];
            draw();
        }));
        container.querySelectorAll('[data-remove-photo]').forEach((b) => b.addEventListener('click', () => {
            photos.splice(Number(b.dataset.removePhoto), 1);
            draw();
        }));
        container.querySelector('#pc-model')?.addEventListener('change', (e) => {
            modelId = Number(e.target.value);
            try { localStorage.setItem('purchase.model', String(modelId)); } catch (err) { /* ignore */ }
        });
        container.querySelector('#pc-extract')?.addEventListener('click', extract);

        bindHeaderInputs(container);

        container.querySelectorAll('tr[data-row]').forEach((tr) => {
            const i = Number(tr.dataset.row);
            tr.querySelectorAll('[data-f]').forEach((inp) => {
                inp.addEventListener('input', () => onItemInput(i, inp.dataset.f, inp.value, tr));
                inp.addEventListener('change', () => {
                    if (['name', 'barcode', 'unit_cost', 'qty', 'pack_size', 'line_total'].includes(inp.dataset.f)) refreshPreview();
                });
            });
        });
        container.querySelectorAll('tr[data-row] [data-type]').forEach((sel) => sel.addEventListener('change', () => {
            const it = items[Number(sel.closest('tr').dataset.row)];
            it.item_type = sel.value;
            it.typeTouched = true;
            draw();
            refreshPreview();
        }));
        container.querySelectorAll('[data-remove-row]').forEach((b) => b.addEventListener('click', () => {
            items.splice(Number(b.dataset.removeRow), 1);
            if (!items.length) items = [emptyItem()];
            draw();
        }));
        container.querySelector('#pc-add-row')?.addEventListener('click', () => {
            items.push(emptyItem());
            draw();
            const rows = container.querySelectorAll('tr[data-row] [data-f="name"]');
            rows[rows.length - 1]?.focus();
        });
        const resetForm = async () => {
            if (!(await confirmDialog(editing ? 'ใบซื้อเดิมไม่เปลี่ยนแปลง' : 'ข้อมูลที่กรอกในฟอร์มจะถูกล้างทั้งหมด',
                { title: editing ? `ยกเลิกการแก้ไข ${editing.doc_no}?` : 'ล้างข้อมูลในฟอร์ม?', okText: editing ? 'ยกเลิกการแก้ไข' : 'ล้างข้อมูล', cancelText: 'กลับไปแก้ไขต่อ', danger: true }))) return;
            clearForm();
            error = null; success = null;
            draw();
        };
        container.querySelector('#pc-reset')?.addEventListener('click', resetForm);
        container.querySelector('#pc-open').addEventListener('click', () => {
            formOpen = true;
            error = null;
            success = null;
            draw();
            container.querySelector('[data-h="supplier_name"]')?.focus();
        });
        // Closing keeps a new purchase's data for later; closing an edit discards it.
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', async () => {
            if (editing) {
                if (!(await confirmDialog('ใบซื้อเดิมไม่เปลี่ยนแปลง', { title: `ปิดและยกเลิกการแก้ไข ${editing.doc_no}?`, okText: 'ปิดและยกเลิก', cancelText: 'กลับไปแก้ไขต่อ', danger: true }))) return;
                clearForm();
            }
            formOpen = false;
            error = null;
            draw();
        }));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', async () => {
            const formUsed = !editing && items.some((i) => i.name.trim());
            if ((formUsed || editing) && !(await confirmDialog('ข้อมูลที่กรอกค้างไว้ในฟอร์มจะถูกแทนที่ด้วยใบซื้อที่เลือก', { title: 'แทนที่ข้อมูลในฟอร์ม?', okText: 'ดำเนินการต่อ' }))) return;
            try {
                startEdit((await api.get(`/purchases/${b.dataset.edit}`)).data);
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        }));
        container.querySelector('#pc-save')?.addEventListener('click', save);
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancelDoc(Number(b.dataset.cancel), b.dataset.doc)));
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', async () => {
            try {
                viewing = (await api.get(`/purchases/${b.dataset.view}`)).data;
                draw();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        }));
        // The detail modal closes only via its close buttons.
        container.querySelector('[data-print-view]')?.addEventListener('click', () => printPurchaseDoc(viewing));
        container.querySelectorAll('[data-close-view]').forEach((b) => b.addEventListener('click', () => { viewing = null; draw(); }));

        container.onpaste = (e) => {
            const files = imagesFromClipboard(e);
            if (files.length) {
                e.preventDefault();
                addPhotos(files);
            }
        };
    }

    function onItemInput(i, field, value, tr) {
        const it = items[i];
        if (field === 'name' || field === 'barcode') {
            it[field] = value;
        } else {
            it[field] = value === '' ? 0 : Number(value);
        }
        if (field === 'qty' || field === 'unit_cost') {
            it.line_total = round2(Number(it.qty) * Number(it.unit_cost));
            tr.querySelector('[data-f="line_total"]').value = it.line_total;
        } else if (field === 'line_total' && Number(it.qty) > 0) {
            it.unit_cost = round2(Number(it.line_total) / Number(it.qty));
            tr.querySelector('[data-f="unit_cost"]').value = it.unit_cost;
        } else if (field === 'sale_price') {
            it.priceTouched = value !== '';
        } else if (field === 'pack_size') {
            it.pack_size = Math.max(1, Math.round(Number(value) || 1));
        }
        tr.querySelector('[data-units]').innerHTML = unitsHtml(it);
        tr.querySelector('[data-piece]').textContent = money(pieceCost(it));
        updatePriceCell(it, tr);
        redrawSummary();
    }

    function refreshPreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            const payload = items.map((it) => ({
                name: it.receipt_name || it.name, product_name: it.name, barcode: it.barcode, qty: it.qty,
                pack_size: it.pack_size, unit_cost: it.unit_cost, line_total: it.line_total,
                item_type: it.typeTouched ? it.item_type : null, product_id: keptProductId(it),
            }));
            try {
                const res = await api.post('/purchases/preview', { items: payload });
                let typeChanged = false;
                res.data.forEach((p, i) => {
                    const it = items[i];
                    if (!it) return;
                    typeChanged = applyPreview(it, p) || typeChanged;
                    const tr = container.querySelector(`tr[data-row="${i}"]`);
                    if (!tr || it.item_type === 'supply') return;
                    tr.querySelector('[data-status]').innerHTML = statusHtml(it);
                    const sp = tr.querySelector('[data-f="sale_price"]');
                    sp.placeholder = it.suggested || '';
                    if (!it.priceTouched && document.activeElement !== sp) sp.value = it.sale_price || '';
                    updatePriceCell(it, tr);
                });
                // A history hint or a pack size filled in from a known barcode changes the row.
                if (typeChanged) draw();
            } catch (e) { /* preview is best-effort */ }
        }, 300);
    }

    // ---------------- Actions ----------------

    async function addPhotos(files) {
        const room = MAX_PHOTOS - photos.length;
        if (!files.length || room <= 0) return;
        busy = 'upload';
        error = null;
        draw();
        try {
            for (const f of files.slice(0, room)) {
                photos.push(await uploadImage(f));
                draw();
            }
            if (files.length > room) error = `แนบได้สูงสุด ${MAX_PHOTOS} รูปต่อใบเสร็จ`;
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

    async function extract() {
        if (!photos.length || !modelId) return;
        const hasData = items.some((i) => i.name);
        if (hasData && !(await confirmDialog('ข้อมูลรายการที่กรอกไว้จะถูกแทนที่ด้วยผลการอ่านจาก AI', { title: 'อ่านใบเสร็จด้วย AI?', okText: 'ดำเนินการต่อ' }))) return;
        busy = 'extract';
        error = null;
        success = null;
        draw();
        try {
            const res = await api.post('/purchases/extract', { model: modelId, attachments: photos.map((p) => p.token) });
            applyDraft(res.data, null);
            res.preview.forEach((p, i) => {
                if (items[i]) applyPreview(items[i], p);
            });
            if (!res.data.items.length) error = 'AI ไม่พบรายการสินค้าในรูป ลองถ่ายให้ชัดขึ้นหรือกรอกเอง';
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

    /** While editing, a line keeps its product unless it was renamed or switched to a business supply. */
    function keptProductId(it) {
        return it.product_id && it.item_type === 'stock' && it.name.trim() === it.origName ? it.product_id : null;
    }

    async function cancelDoc(id, docNo) {
        const reason = await promptDialog('สินค้าตามใบนี้จะถูกหักออกจากสต๊อก และไม่นับรวมในรายงาน (ยกเลิกแล้วกู้คืนไม่ได้)\nกรุณาระบุเหตุผลที่ยกเลิก:',
            { title: `ยกเลิกใบซื้อ ${docNo}?`, okText: 'ยกเลิกใบซื้อ', cancelText: 'ไม่ยกเลิก', placeholder: 'เหตุผลที่ยกเลิก', required: 'กรุณาระบุเหตุผลที่ยกเลิก', multiline: true, danger: true });
        if (reason === null) return;
        try {
            const res = await api.post(`/purchases/${id}/cancel`, { reason: reason.trim() });
            success = `ยกเลิกใบซื้อ ${res.data.doc_no} แล้ว — หักสินค้าออกจากสต๊อก ${res.data.units} ชิ้น`;
            error = null;
            if (editing?.id === id) {
                clearForm();
                formOpen = false;
            }
            viewing = null;
            await loadDocs();
            draw();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    async function save() {
        error = null;
        success = null;
        const rows = items.filter((it) => it.name.trim() || Number(it.unit_cost));
        if (!header.supplier_name.trim()) {
            error = 'กรุณากรอกชื่อร้าน/ผู้ขาย';
        } else if (!rows.length) {
            error = 'กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ';
        } else {
            const bad = rows.findIndex((it) => !it.name.trim() || Number(it.qty) <= 0 || Number(it.unit_cost) <= 0);
            if (bad >= 0) error = `รายการที่ ${items.indexOf(rows[bad]) + 1} ต้องมีชื่อ จำนวน และราคาทุน`;
        }
        if (error) { draw(); return; }

        busy = 'save';
        draw();
        try {
            const payload = {
                header: { ...header },
                items: rows.map((it) => ({
                    name: (it.receipt_name || it.name).trim(), product_name: it.name.trim(), pack_size: Math.max(1, Number(it.pack_size) || 1),
                    item_type: it.item_type, product_id: keptProductId(it),
                    barcode: it.barcode.trim(), qty: Number(it.qty), unit_cost: Number(it.unit_cost),
                    line_total: Number(it.line_total), sale_price: Number(it.sale_price) || null,
                })),
                attachments: photos.map((p) => p.token),
                source,
            };
            const res = editing ? await api.put(`/purchases/${editing.id}`, payload) : await api.post('/purchases', payload);
            const d = res.data;
            const newCount = d.items.filter((i) => i.new).length;
            const supplyCount = d.items.filter((i) => i.type === 'supply').length;
            const unitsIn = d.items.filter((i) => i.type !== 'supply').reduce((n, i) => n + Number(i.units || 0), 0);
            success = `${editing ? 'บันทึกการแก้ไขใบซื้อ' : 'บันทึกใบซื้อ'} ${d.doc_no} แล้ว: สินค้าใหม่ ${newCount} รายการ เติมสต๊อก ${d.items.length - newCount - supplyCount} รายการ (เข้าสต๊อกรวม ${unitsIn} ชิ้น)${supplyCount ? ` ใช้ในกิจการ ${supplyCount} รายการ ฿${money(d.supply_total)}` : ''} ยอด ฿${money(d.total)}`;
            clearForm();
            formOpen = false;
            await loadDocs();
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

}
