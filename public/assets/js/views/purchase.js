import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { uploadImage, uploadTextFile, isTextFile, attachmentUrl, imagesFromClipboard } from '../images.js';
import { markdown } from '../markdown.js';
import { printPurchaseDoc } from '../print-slip.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';

/**
 * ซื้อสินค้าเข้าร้าน: store-owned purchases. Receipt photos (one receipt may
 * span several overlapping photos) can be read by a vision model into a
 * draft that the user reviews here before saving. A receipt's evidence may also
 * be a .md file summarising the purchase (kept when a purchase is imported from one).
 */
export const DRAFT_KEY = 'purchase.draft';

const MAX_PHOTOS = 10;
const ACCEPT_EVIDENCE = 'image/*,.md,.markdown,.txt,text/markdown,text/plain';

const isImage = (f) => !f.mime || f.mime.startsWith('image/');
/** A saved attachment (publicInfo from the server) as a form/view entry. */
const evidence = (a) => ({ token: a.token, mime: a.mime, name: a.name, preview: attachmentUrl(a.token) });

function emptyHeader() {
    // funding: bought with invested money ('capital', counts toward งบลงทุน) or the business's profit ('profit')
    return { supplier_name: '', supplier_tax_id: '', receipt_no: '', receipt_date: '', discount: 0, vat: 0, total: 0, note: '', funding: 'capital' };
}
function emptyItem() {
    // name = per-piece product name; qty/unit_cost/line_total = as printed (packs); sale_price = per piece
    // item_type: 'stock' = bought to sell, 'supply' = used in the business (cost only, no stock/price)
    // product_id/origName: set when editing a saved purchase, so an unrenamed line stays on the same product
    // pickedId: an existing product the user paired the line with by hand (it restocks that product)
    // forceNew: the user said the line is not the matched product, so saving creates a new one (notMatch = the product it is not)
    // presetPrice: a sale price that came with an AI draft or an order, used only if the line turns out to be a new product
    return { name: '', receipt_name: '', item_type: 'stock', typeTouched: false, pack_size: 1, barcode: '', qty: 1, unit_cost: 0, line_total: 0, sale_price: 0, presetPrice: 0, priceTouched: false, match: null, suggested: 0, product_id: null, origName: '', pickedId: null, forceNew: false, notMatch: '' };
}
const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

export async function render(container) {
    let photos = []; // receipt evidence: [{ token, preview, mime?, name? }] — photos and .md files
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
    let mdOpen = null; // { token, name, html } — a .md file opened in the detail modal
    let evidenceBusy = false;
    let editing = null; // { id, doc_no, ordered?, receive? } while the form holds a saved purchase (ordered: an order not delivered yet)
    let showCancelled = false; // cancelled purchases are hidden from the list unless asked for
    try { showCancelled = localStorage.getItem('purchase.showCancelled') === '1'; } catch (e) { /* private mode */ }
    let orderDraft = false; // a new form filled from an order document (the assistant's "ใบสั่งของ"): save as an order by default
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
        // Restocking an existing product keeps its current sale price; only a new product gets a preset or suggested one.
        if (!it.priceTouched) it.sale_price = (p.match ? it.suggested : it.presetPrice || it.suggested) || 0;
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
            applyDraft(draft, draft.files ? draft.files.map(evidence) : (draft.attachments || []).map((token) => ({ token, preview: attachmentUrl(token) })));
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
            line_total: it.line_total, sale_price: it.sale_price || 0, presetPrice: Number(it.sale_price) || 0,
        }));
        if (!items.length) items = [emptyItem()];
        warnings = d.warnings || [];
        source = 'ai';
        orderDraft = !!d.order;
        if (newPhotos) photos = newPhotos;
    }

    /** Loads a saved purchase into the form; saving then updates it in place. */
    function startEdit(d, { receive = false } = {}) {
        const ordered = d.status === 'ordered' || d.status === 'draft';
        editing = { id: Number(d.id), doc_no: d.doc_no, ordered, draft: d.status === 'draft', receive: d.status === 'ordered' && receive };
        header = {
            ...emptyHeader(),
            supplier_name: d.supplier_name || '', supplier_tax_id: d.supplier_tax_id || '', receipt_no: d.receipt_no || '',
            receipt_date: d.receipt_date || '', discount: Number(d.discount) || 0, vat: Number(d.vat) || 0, total: Number(d.total) || 0, note: d.note || '',
            funding: d.funding === 'profit' ? 'profit' : 'capital',
        };
        // An order keeps its lines as entered: a line paired with a product stays paired.
        if (ordered) {
            items = (d.order_lines || []).map((it) => ({
                ...emptyItem(), name: it.product_name || it.name, receipt_name: it.name || '',
                item_type: it.item_type === 'supply' ? 'supply' : 'stock', typeTouched: true,
                pack_size: Number(it.pack_size) || 1, barcode: it.barcode || '', qty: Number(it.qty), unit_cost: Number(it.unit_cost),
                line_total: Number(it.line_total), sale_price: Number(it.sale_price) || 0, presetPrice: Number(it.sale_price) || 0,
                pickedId: it.product_id ? Number(it.product_id) : null, forceNew: !!it.new_product,
            }));
            // Receiving: the delivery date, unless the order already carries the receipt's own date.
            if (editing.receive && !header.receipt_date) header.receipt_date = todayISO();
        } else items = d.items.map((it) => {
            const name = (it.item_type === 'stock' && it.product_name) || it.receipt_name;
            return {
                ...emptyItem(), name, origName: name, product_id: it.product_id ? Number(it.product_id) : null,
                receipt_name: it.receipt_name, item_type: it.item_type === 'supply' ? 'supply' : 'stock', typeTouched: true,
                pack_size: Number(it.pack_size) || 1, barcode: it.barcode || '', qty: Number(it.qty), unit_cost: Number(it.unit_cost),
                line_total: Number(it.line_total), sale_price: Number(it.sale_price) || 0, priceTouched: it.item_type === 'stock',
            };
        });
        if (!items.length) items = [emptyItem()];
        photos = d.images.map(evidence);
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
        photos = []; header = emptyHeader(); items = [emptyItem()]; warnings = []; source = 'manual'; editing = null; orderDraft = false;
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
              <h2 id="pc-form-title">${editing?.receive ? `รับสินค้าตามใบสั่งซื้อ ${escapeHtml(editing.doc_no)}`
                  : editing?.draft ? `ร่างใบสั่งซื้อ ${escapeHtml(editing.doc_no)}`
                  : editing?.ordered ? `แก้ไขใบสั่งซื้อ ${escapeHtml(editing.doc_no)}`
                  : editing ? `แก้ไขใบซื้อ ${escapeHtml(editing.doc_no)}` : orderDraft ? 'บันทึกใบสั่งซื้อ (รอร้านมาส่ง)' : 'ซื้อสินค้าเข้าร้าน'}</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body pc-modal-body">
        ${editing?.ordered ? `<div class="pc-warn" style="margin:0">
          <div><b>${editing.receive ? 'ตรวจสินค้าที่มาส่งกับใบสั่งซื้อ' : editing.draft ? 'ร่างใบสั่งซื้อ ยังไม่ได้สั่ง' : 'ใบสั่งซื้อ รอร้านมาส่ง'} ${escapeHtml(editing.doc_no)}</b><br>
            <span style="font-size:12.5px">${editing.receive
                ? 'แก้จำนวน/ราคาให้ตรงกับของที่ได้รับจริงและใบเสร็จ/ใบส่งของ แล้วกด <b>ยืนยันรับสินค้าเข้าสต๊อก</b> — สินค้าจะเข้าสต๊อกและบันทึกเป็นใบซื้อ (เลขที่เดิม)'
                : editing.draft
                    ? 'เพิ่ม/แก้รายการได้ (หรือเลือกสินค้าเพิ่มจากหน้าสต๊อกสินค้า) เมื่อสั่งซื้อกับร้านเรียบร้อยแล้ว กด <b>ยืนยันสั่งซื้อแล้ว</b> สถานะจะเป็นรอรับของ'
                    : 'ใบสั่งซื้อยังไม่เข้าสต๊อก แก้ไขรายการได้จนกว่าจะยืนยันรับสินค้า'}</span></div>
        </div>` : editing ? `<div class="pc-warn" style="margin:0">
          <div><b>กำลังแก้ไขใบซื้อ ${escapeHtml(editing.doc_no)}</b><br>
            <span style="font-size:12.5px">เมื่อบันทึก ระบบจะหักสต๊อกตามใบเดิมออกแล้วรับเข้าตามรายการใหม่ (เลขที่ใบซื้อเดิม) — สินค้าที่ขายไปแล้วอาจทำให้สต๊อกติดลบ · สินค้าที่ตรวจนับหลังวันที่ในใบเสร็จคงยอดตามที่นับ</span></div>
        </div>` : orderDraft ? `<div class="pc-warn" style="margin:0">
          <div><b>ใบสั่งของ</b><br><span style="font-size:12.5px">บันทึกเป็นใบสั่งซื้อ (สั่งแล้ว รอรับของ) สินค้ายังไม่เข้าสต๊อก เมื่อร้านมาส่งของ กด <b>รับสินค้า</b> ที่ใบสั่งซื้อในรายการเพื่อยืนยันเข้าสต๊อก</span></div>
        </div>` : ''}

        <section class="card card-pad">
          <div class="step-title"><span class="step-num">1</span><h2>หลักฐานใบเสร็จ</h2>
            <span class="muted" style="font-size:12.5px;margin-left:auto">รูปภาพ หรือไฟล์ .md · ${photos.length}/${MAX_PHOTOS} ไฟล์</span></div>
          <div class="pc-photos" id="pc-photos">
            ${photos.map((p, i) => `
              <div class="pc-photo">
                ${isImage(p) ? `<img src="${escapeHtml(p.preview)}" alt="รูปที่ ${i + 1}">` : fileTileHtml(p)}
                <span class="pc-photo-no">${i + 1}</span>
                <div class="pc-photo-tools">
                  <button type="button" data-move="${i}" data-dir="-1" title="เลื่อนขึ้นก่อน" ${i === 0 ? 'disabled' : ''}>◀</button>
                  <button type="button" data-move="${i}" data-dir="1" title="เลื่อนไปหลัง" ${i === photos.length - 1 ? 'disabled' : ''}>▶</button>
                  <button type="button" data-remove-photo="${i}" title="ลบรูป">✕</button>
                </div>
              </div>`).join('')}
            ${photos.length < MAX_PHOTOS ? `
              <label class="pc-add" title="เพิ่มรูปใบเสร็จ หรือไฟล์ .md สรุปการซื้อ (วางรูปด้วย Ctrl+V ได้)">
                <input type="file" accept="${ACCEPT_EVIDENCE}" multiple id="pc-file" hidden>
                <span style="font-size:26px;line-height:1">＋</span>
                <span>${busy === 'upload' ? 'กำลังอัปโหลด...' : 'เพิ่มรูป / .md'}</span>
              </label>` : ''}
          </div>
          <div class="actions-row" style="margin-top:14px">
            ${models.length ? `
              <select id="pc-model" class="pc-select" title="โมเดลที่ใช้อ่านรูป">
                ${models.map((m) => `<option value="${m.id}" ${m.id === modelId ? 'selected' : ''}>${escapeHtml(m.label)} · ${escapeHtml(m.provider_name)}</option>`).join('')}
              </select>
              <button type="button" class="btn" id="pc-extract" ${photos.some(isImage) && !busy ? '' : 'disabled'}>
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
            <label class="field">${editing?.receive ? 'วันที่รับสินค้า / ในใบเสร็จ' : editing?.ordered || orderDraft ? 'วันที่สั่ง / ในใบสั่งของ' : 'วันที่ในใบเสร็จ'} <input data-h="receipt_date" type="date" value="${escapeHtml(header.receipt_date)}"></label>
            <label class="field">หมายเหตุ <input data-h="note" value="${escapeHtml(header.note)}"></label>
            <label class="field">ซื้อด้วยเงิน
              <select data-h="funding">
                <option value="capital" ${header.funding !== 'profit' ? 'selected' : ''}>เงินลงทุน (นับเป็นงบลงทุน)</option>
                <option value="profit" ${header.funding === 'profit' ? 'selected' : ''}>เงินกำไรของกิจการ</option>
              </select>
              <span class="muted" style="font-size:11.5px;font-weight:400">ซื้อด้วยเงินกำไรของร้าน ไม่นับเพิ่มในงบลงทุน</span></label>
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
          <div style="padding:0 18px 12px">${pricingHelpHtml()}</div>
          <div style="padding:0 18px 18px">
            ${warnings.length ? `<div class="pc-warn"><b>ข้อสังเกตจาก AI</b><ul>${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul></div>` : ''}
            ${error ? `<div class="error-box" style="margin-bottom:12px">${escapeHtml(error)}</div>` : ''}
          </div>
        </section>

            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" id="pc-reset">${editing ? 'ยกเลิกการแก้ไข' : 'ล้างฟอร์ม'}</button>
              ${saveButtonsHtml()}
            </div>
          </div>
        </div>`;
    }

    /** New form: save as received (stock in now) or as an order; an order: save it, or confirm the delivery. */
    function saveButtonsHtml() {
        const wait = busy ? 'disabled' : '';
        const label = (mode, text) => (busy === mode ? 'กำลังบันทึก...' : text);
        if (editing?.draft) {
            return `<span class="actions-row">
              <button type="button" class="btn secondary" data-save="order" ${wait}>${label('order', 'บันทึกร่าง')}</button>
              <button type="button" class="btn" data-save="place" ${wait} title="สั่งซื้อกับร้านแล้ว: สถานะเป็นรอรับของ">${label('place', '✓ บันทึกและยืนยันสั่งซื้อแล้ว')}</button></span>`;
        }
        if (editing?.ordered) {
            const order = `<button type="button" class="btn ${editing.receive ? 'secondary' : ''}" data-save="order" ${wait}>${label('order', `บันทึกใบสั่งซื้อ ${escapeHtml(editing.doc_no)}`)}</button>`;
            const receive = `<button type="button" class="btn ${editing.receive ? '' : 'secondary'}" data-save="receive" ${wait} title="ของมาส่งแล้ว: เข้าสต๊อกตามรายการนี้">${label('receive', '✓ ยืนยันรับสินค้าเข้าสต๊อก')}</button>`;
            return `<span class="actions-row">${order}${receive}</span>`;
        }
        if (editing) return `<button type="button" class="btn" data-save="purchase" ${wait}>${label('purchase', `บันทึกการแก้ไข ${escapeHtml(editing.doc_no)}`)}</button>`;
        // A supplier's order document is an order already placed; one typed here starts as a draft.
        const order = `<button type="button" class="btn ${orderDraft ? '' : 'secondary'}" data-save="order" ${wait} title="ยังไม่เข้าสต๊อก${orderDraft ? '' : ' — เพิ่มรายการได้อีก แล้วกด ยืนยันสั่งซื้อแล้ว เมื่อสั่งกับร้านเรียบร้อย'}">${label('order', orderDraft ? 'บันทึกใบสั่งซื้อ (สั่งแล้ว รอรับของ)' : 'บันทึกเป็นร่างใบสั่งซื้อ')}</button>`;
        const purchase = `<button type="button" class="btn ${orderDraft ? 'secondary' : ''}" data-save="purchase" ${wait}>${label('purchase', 'บันทึกใบซื้อ (ได้รับของแล้ว)')}</button>`;
        return `<span class="actions-row">${orderDraft ? purchase + order : order + purchase}</span>`;
    }

    function draw() {
        const waiting = docs.filter(isOrdered);
        const drafts = docs.filter(isDraft);
        const cancelledCount = docs.filter(isCancelled).length;
        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="hint"><div class="dot">i</div><div>
          บันทึกการซื้อสินค้าเข้าร้าน (สินค้าของร้านเอง ไม่ใช่ฝากขาย) — ถ่ายรูปใบเสร็จอย่างย่อแล้วให้ AI อ่านรายการให้ ตรวจแก้แล้วกดบันทึก
          ใบเสร็จยาวถ่ายต่อกันได้หลายรูป (เรียงจากบนลงล่าง ให้ขอบภาพซ้อนกันเล็กน้อย) ราคาในใบเสร็จคือ<b>ราคาทุน</b>
          ระบบตั้งราคาขายเริ่มต้นเป็นทุน + ${escapeHtml(markup)}% แก้ได้ทุกรายการ
        </div></div>
        <div class="actions-row" style="justify-content:space-between">
          <h2 style="font-size:15px;margin:0">ใบซื้อล่าสุด${drafts.length ? ` <span class="pill muted" title="ร่างใบสั่งซื้อที่ยังไม่ได้สั่ง">ร่าง ${drafts.length} ใบ</span>` : ''}${waiting.length ? ` <span class="pill warn" title="ใบสั่งซื้อที่ร้านยังไม่มาส่ง">รอส่งของ ${waiting.length} ใบ</span>` : ''}</h2>
          <span class="actions-row">
            ${cancelledCount ? `<button type="button" class="btn secondary sm" id="pc-show-cancelled" aria-pressed="${showCancelled}">${showCancelled ? 'ซ่อน' : 'แสดง'}รายการที่ยกเลิกแล้ว (${cancelledCount})</button>` : ''}
            <button type="button" class="btn" id="pc-open">+ ซื้อสินค้าเข้าร้าน</button>
          </span>
        </div>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>วันที่ใบเสร็จ</th><th>ร้าน/ผู้ขาย</th><th class="num">รายการ</th><th class="num">ยอดสุทธิ</th><th class="num" title="ส่วนที่ซื้อมาใช้ในกิจการ (ต้นทุน)">ใช้ในกิจการ</th><th title="หลักฐานใบเสร็จ: รูปภาพ / ไฟล์ .md">หลักฐาน</th><th>บันทึกโดย</th><th></th></tr></thead>
            <tbody>
              ${docs.filter((d) => showCancelled || !isCancelled(d)).map((d) => `
                <tr class="${isCancelled(d) ? 'pc-cancelled' : ''}">
                  <td style="font-weight:600;white-space:nowrap">${escapeHtml(d.doc_no)} ${d.source === 'ai' ? '<span class="pill muted" title="อ่านจากใบเสร็จด้วย AI">AI</span>' : ''}${d.funding === 'profit' ? ' <span class="pill ok" title="ซื้อด้วยเงินกำไรของกิจการ ไม่นับเป็นงบลงทุน">เงินกำไร</span>' : ''}
                    ${isCancelled(d) ? `<span class="pill bad" title="${escapeHtml(d.cancel_reason || '')}">ยกเลิกแล้ว</span>` : ''}
                    ${isOrdered(d) ? '<span class="pill warn" title="ใบสั่งซื้อ ร้านยังไม่มาส่ง สินค้ายังไม่เข้าสต๊อก">รอส่งของ</span>' : ''}
                    ${isDraft(d) ? '<span class="pill muted" title="ยังไม่ได้สั่งกับร้าน เพิ่มรายการได้อีก">ร่างใบสั่งซื้อ</span>' : ''}</td>
                  <td>${d.receipt_date ? fmtDate(d.receipt_date) : '-'}</td>
                  <td>${escapeHtml(d.supplier_name)}${d.receipt_no ? `<div class="muted" style="font-size:12px">${escapeHtml(d.receipt_no)}</div>` : ''}</td>
                  <td class="num">${d.item_count}</td>
                  <td class="num"><span class="pc-total">${money(d.total)}</span></td>
                  <td class="num muted">${Number(d.supply_total) ? money(d.supply_total) : '-'}</td>
                  <td class="pc-ev-cell">${Number(d.image_count) || Number(d.file_count) ? `<button type="button" data-view="${d.id}" title="ดูหลักฐานใบเสร็จ">${[Number(d.image_count) ? `🖼 ${d.image_count}` : '', Number(d.file_count) ? `📄 ${d.file_count}` : ''].filter(Boolean).join(' ')}</button>` : ''}</td>
                  <td class="muted" style="font-size:12.5px">${escapeHtml(d.user_name || '')}<div>${fmtDateTime(d.created_at)}</div></td>
                  <td style="white-space:nowrap"><button type="button" class="btn secondary sm" data-view="${d.id}">ดู</button>
                    ${isOrdered(d) ? `<button type="button" class="btn sm" data-receive="${d.id}" title="ของมาส่งแล้ว: ตรวจรายการแล้วยืนยันเข้าสต๊อก">รับสินค้า</button>` : ''}
                    ${isDraft(d) ? `<button type="button" class="btn sm" data-place="${d.id}" data-doc="${escapeHtml(d.doc_no)}" title="สั่งซื้อกับร้านเรียบร้อยแล้ว: เปลี่ยนเป็นรอรับของ">ยืนยันสั่งซื้อแล้ว</button>` : ''}
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
    function isOrdered(d) {
        return d.status === 'ordered';
    }
    function isDraft(d) {
        return d.status === 'draft';
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
        if (it.forceNew) {
            const same = it.notMatch && it.name.trim() === it.notMatch;
            return `<span class="pill warn" title="ไม่จับคู่กับสินค้าเดิม จะสร้างเป็นสินค้าใหม่">สินค้าใหม่</span>
              ${it.notMatch ? `<div class="muted" style="font-size:11.5px;max-width:170px">ไม่ใช่ ${escapeHtml(it.notMatch)}</div>` : ''}
              ${same ? '<div style="font-size:11.5px;max-width:170px;color:var(--warn)">ชื่อซ้ำกับสินค้าเดิม ควรแก้ชื่อให้ต่างกัน</div>' : ''}
              <button type="button" class="link-btn" data-pick>จับคู่สินค้าเดิม</button>
              <button type="button" class="link-btn" data-unnew style="margin-left:6px">ยกเลิก</button>`;
        }
        if (!it.match) {
            return `<span class="pill warn" title="ไม่พบในสต๊อก จะสร้างเป็นสินค้าใหม่">สินค้าใหม่</span>
              <button type="button" class="link-btn" data-pick title="เลือกสินค้าที่มีอยู่แล้ว เช่น ที่เคยขายหรือนำเข้ายอดขายไว้ก่อน">จับคู่สินค้าเดิม</button>`;
        }
        const stock = Number(it.match.qty_stock);
        return `<span class="pill ok" title="มีในสต๊อก ${stock} ชิ้น">เติมสต๊อก</span>
          <div class="muted" style="font-size:11.5px;max-width:170px">→ ${escapeHtml(it.match.name)} · คงเหลือ <span style="${stock < 0 ? 'color:var(--bad)' : ''}">${stock}</span></div>
          <button type="button" class="link-btn" data-pick>${it.pickedId ? 'เปลี่ยน' : 'ไม่ใช่ตัวนี้? เลือกเอง'}</button>
          ${it.pickedId ? '<button type="button" class="link-btn" data-unpick style="margin-left:6px">ยกเลิกจับคู่</button>' : ''}
          <div><button type="button" class="link-btn" data-new title="ไม่ใช่สินค้าเดิม บันทึกเป็นสินค้าใหม่แยกต่างหาก">เป็นสินค้าใหม่</button></div>`;
    }

    /** Pairing buttons in a row's status cell (the cell is also redrawn by refreshPreview). */
    function bindStatus(tr) {
        const it = items[Number(tr.dataset.row)];
        tr.querySelector('[data-status] [data-pick]')?.addEventListener('click', () => openProductPicker(it));
        tr.querySelector('[data-status] [data-unpick]')?.addEventListener('click', () => {
            it.pickedId = null;
            it.match = null;
            it.priceTouched = false;
            draw();
            refreshPreview();
        });
        tr.querySelector('[data-status] [data-new]')?.addEventListener('click', () => markNew(it));
        tr.querySelector('[data-status] [data-unnew]')?.addEventListener('click', () => {
            if (it.nameBefore != null && it.name === it.receipt_name) it.name = it.nameBefore;
            it.forceNew = false;
            it.notMatch = '';
            it.priceTouched = false;
            draw();
            refreshPreview();
        });
    }

    /** The line is not the product it was matched with: save it as a new product instead. */
    function markNew(it) {
        it.notMatch = it.match?.name || it.notMatch;
        // A line still named after the wrong product takes the receipt's wording (ยกเลิก brings the name back).
        it.nameBefore = it.name;
        if (it.receipt_name && (!it.name.trim() || it.name.trim() === it.notMatch)) it.name = it.receipt_name;
        it.forceNew = true;
        it.pickedId = null;
        it.match = null;
        it.priceTouched = false;
        draw();
        refreshPreview();
    }

    /** Picks an existing store product for a receipt line (e.g. goods sold or imported before this purchase). */
    function openProductPicker(it) {
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        box.style.zIndex = 70;
        // Start broad: the first word of the receipt name (store names are often shorter).
        const start = it.name.trim().split(/[\s\-]+/)[0] || '';
        box.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="width:640px">
            <div class="modal-head"><h2>จับคู่กับสินค้าเดิม</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div class="muted" style="font-size:12.5px;margin-bottom:8px">รายการในใบเสร็จ: <b>${escapeHtml(it.receipt_name || it.name)}</b>${it.barcode ? ` · บาร์โค้ด ${escapeHtml(it.barcode)}` : ''}<br>
                เลือกสินค้าที่มีอยู่แล้ว ระบบจะเติมสต๊อกให้สินค้านั้นแทนการสร้างสินค้าใหม่ และจำบาร์โค้ดใบเสร็จนี้ไว้จับคู่ครั้งต่อไป</div>
              <input class="search-input" data-q value="${escapeHtml(start)}" placeholder="ค้นหาชื่อหรือบาร์โค้ด" style="max-width:none;width:100%">
              <div data-list style="margin-top:10px;max-height:52vh;overflow:auto"></div>
              ${it.match && !it.forceNew ? `<div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="muted" style="font-size:12.5px">ไม่ใช่ ${escapeHtml(it.match.name)} และไม่มีในรายการ?</span>
                <button type="button" class="btn sm" data-asnew>บันทึกเป็นสินค้าใหม่</button></div>` : ''}
            </div>
          </div>`;
        document.body.appendChild(box);
        const q = box.querySelector('[data-q]');
        const list = box.querySelector('[data-list]');
        const close = () => box.remove();
        let timer;
        let rows = [];
        const search = async () => {
            const res = await api.get(`/purchases/products?q=${encodeURIComponent(q.value.trim())}`).catch(() => ({ data: [] }));
            rows = res.data;
            list.innerHTML = rows.length ? `${res.matched ? `<div class="muted" style="font-size:12.5px;margin-bottom:6px">ไม่พบคำเต็ม แสดงผลที่มีคำว่า "<b>${escapeHtml(res.matched)}</b>"</div>` : ''}<table><thead><tr><th>สินค้า</th><th class="num">คงเหลือ</th><th class="num">ทุน/ชิ้น</th><th class="num">ราคาขาย</th><th></th></tr></thead><tbody>
              ${rows.map((p, i) => `<tr><td>${escapeHtml(p.name)}<div class="muted" style="font-size:11.5px">${escapeHtml([p.barcode, p.packs].filter(Boolean).join(' · ') || 'ไม่มีบาร์โค้ด')}</div></td>
                <td class="num" style="${Number(p.qty_stock) < 0 ? 'color:var(--bad);font-weight:600' : ''}">${Number(p.qty_stock)}</td>
                <td class="num">${money(p.cost_price)}</td><td class="num">${money(p.sale_price)}</td>
                <td><button type="button" class="btn sm" data-choose="${i}">เลือก</button></td></tr>`).join('')}</tbody></table>`
                : '<div class="empty">ไม่พบสินค้า ลองพิมพ์คำสั้นลง</div>';
            list.querySelectorAll('[data-choose]').forEach((b) => b.addEventListener('click', () => {
                const p = rows[Number(b.dataset.choose)];
                it.pickedId = Number(p.id);
                it.forceNew = false;
                it.notMatch = '';
                it.match = { product_id: Number(p.id), name: p.name, sale_price: Number(p.sale_price), qty_stock: Number(p.qty_stock), cost_price: Number(p.cost_price) };
                it.suggested = Number(p.sale_price);
                if (!it.priceTouched) it.sale_price = Number(p.sale_price) || it.sale_price;
                close();
                draw();
                refreshPreview();
            }));
        };
        q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 250); });
        box.querySelector('[data-x]').addEventListener('click', close);
        box.querySelector('[data-asnew]')?.addEventListener('click', () => { close(); markNew(it); });
        // Closed with the ✕ button only, so a stray click outside never closes it.
        q.focus();
        q.select();
        search();
    }

    function supplyTotal() {
        return round2(items.filter((i) => i.item_type === 'supply').reduce((s, i) => s + Number(i.line_total || 0), 0));
    }

    function summaryHtml(subtotal) {
        const discount = Number(header.discount) || 0;
        const net = round2(subtotal - discount);
        const printed = Number(header.total) || 0;
        const vat = Number(header.vat) || 0;
        const gap = vatGap(subtotal);
        // [data-sum] parts are the figures; redrawSummary() swaps only those while an amount is being typed.
        return `
          <span data-sum="split" class="muted">${supplyTotal() > 0 ? `เพื่อขาย ฿${money(subtotal - supplyTotal())} · ใช้ในกิจการ ฿${money(supplyTotal())}` : ''}</span>
          <label>รวมตามรายการ <b data-sum="subtotal">฿ ${money(subtotal)}</b></label>
          <label>ส่วนลดท้ายบิล <input data-h="discount" type="number" min="0" step="0.01" value="${header.discount}"></label>
          <label>VAT (ถ้ามี) <input data-h="vat" type="number" min="0" step="0.01" value="${header.vat}"></label>
          <label>ยอดสุทธิตามใบเสร็จ <input data-h="total" type="number" min="0" step="0.01" value="${header.total}"></label>
          <div data-sum="diff" class="pc-diff${gap ? ' info' : printed > 0 && Math.abs(net - printed) > 0.009 ? ' bad' : ''}">${diffText(subtotal, discount, net, vat, printed, gap)}</div>
          <span data-sum="vat">${gap ? `<button type="button" class="btn sm" data-add-vat title="เกลี่ย VAT ตามสัดส่วนราคาของแต่ละรายการ">รวม VAT ฿${money(gap)} เข้าต้นทุนทุกรายการ</button>` : ''}</span>`;
    }

    /** How the lines add up to the receipt's net total, in words (and what to do when they don't). */
    function diffText(subtotal, discount, net, vat, printed, gap) {
        const parts = `รวมตามรายการ ฿${money(subtotal)}${discount ? ` − ส่วนลด ฿${money(discount)}` : ''}`;
        if (!printed) return `ยอดสุทธิ ฿${money(net)} (กรอกยอดสุทธิตามใบเสร็จเพื่อตรวจทาน)`;
        if (Math.abs(net - printed) <= 0.009) {
            return `✓ ${parts} = ฿${money(net)} ตรงกับยอดสุทธิตามใบเสร็จ${vat ? ' — ต้นทุนในรายการรวม VAT แล้ว' : ''}`;
        }
        if (gap) {
            const pct = net ? (gap / net) * 100 : 0;
            return `ℹ ราคาในรายการเป็น<b>ราคาก่อน VAT</b>: ${parts} + VAT ฿${money(vat)} = ฿${money(net + vat)} ตรงกับใบเสร็จ
              <div class="muted" style="font-weight:400;margin-top:2px">ร้านจ่าย VAT ไปจริงและขอคืนไม่ได้ VAT จึงเป็นต้นทุนสินค้า — กดปุ่มด้านล่างเพื่อรวม VAT เข้าต้นทุน (ทุน/ชิ้นทุกรายการเพิ่มขึ้นประมาณ ${pct.toFixed(2)}%)</div>`;
        }
        return `⚠ ${parts}${vat ? ` + VAT ฿${money(vat)}` : ''} = ฿${money(net + vat)} แต่ยอดสุทธิตามใบเสร็จคือ ฿${money(printed)} (ต่าง ฿${money(net + vat - printed)})
          <div style="font-weight:400;margin-top:2px">ตรวจจำนวน ราคา และส่วนลดของแต่ละรายการให้ตรงกับใบเสร็จ หรือใส่ VAT ถ้าใบเสร็จแยก VAT ไว้</div>`;
    }

    /** What each money column means and how the totals are worked out. */
    function pricingHelpHtml() {
        return `
          <details class="pc-help">
            <summary>วิธีคิดราคา ภาษี VAT และยอดสุทธิ</summary>
            <ul>
              <li><b>จำนวนซื้อ</b> = จำนวนหน่วยตามใบเสร็จ (แพ็ค/ลัง) · <b>ชิ้น/แพ็ค</b> = จำนวนชิ้นในหนึ่งหน่วยซื้อ</li>
              <li><b>ทุน/หน่วยซื้อ</b> = ราคาต่อหน่วยหลังหักส่วนลดของรายการ · <b>รวม</b> = จำนวนซื้อ × ทุน/หน่วยซื้อ (ควรตรงกับช่องจำนวนเงินในใบเสร็จ)</li>
              <li><b>เข้าสต๊อก</b> = จำนวนซื้อ × ชิ้น/แพ็ค · <b>ทุน/ชิ้น</b> = รวม ÷ จำนวนชิ้นที่เข้าสต๊อก (ใช้คิดต้นทุนและกำไร)</li>
              <li><b>ราคาขาย/ชิ้น</b> ระบบเสนอเป็นทุน/ชิ้น + ${escapeHtml(markup)}% (แก้ได้) สินค้าที่มีอยู่แล้วใช้ราคาขายเดิม</li>
              <li><b>VAT 7%</b>: ใบเสร็จบางร้านพิมพ์ราคาสินค้าเป็นราคาก่อน VAT แล้วบวก VAT ท้ายบิล
                ยอดสุทธิ = รวมตามรายการ − ส่วนลดท้ายบิล + VAT · ร้านค้าสหการไม่ได้ขอคืน VAT จึงควรรวม VAT เข้าต้นทุนสินค้า (กดปุ่ม "รวม VAT เข้าต้นทุน")</li>
              <li><b>ส่วนลดท้ายบิล</b> บันทึกไว้ในใบซื้อ แต่ไม่ได้หักออกจากทุน/ชิ้นของแต่ละรายการ (ยกเว้นตอนกดรวม VAT ซึ่งเกลี่ยทั้ง VAT และส่วนลดให้)</li>
              <li><b>สินค้าใหม่ / เติมสต๊อก</b>: ระบบจับคู่จากบาร์โค้ดหรือชื่อ ถ้าสินค้านี้เคยขายหรือนำเข้ายอดขายไว้ก่อนแล้วแต่ชื่อไม่ตรง กด <b>จับคู่สินค้าเดิม</b> เพื่อเติมสต๊อกให้สินค้านั้นแทนการสร้างใหม่</li>
            </ul>
          </details>`;
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
              ${isOrdered(d) ? `<div class="pc-warn" style="margin:0 0 12px"><div><b>ใบสั่งซื้อ รอร้านมาส่ง</b> — สั่งเมื่อ ${fmtDateTime(d.ordered_at)}${d.ordered_by_name ? ` โดย ${escapeHtml(d.ordered_by_name)}` : ''}
                <div style="font-size:12.5px">สินค้ายังไม่เข้าสต๊อก เมื่อของมาส่ง กด <b>รับสินค้า</b> เพื่อตรวจรายการและยืนยันเข้าสต๊อก</div></div></div>` : ''}
              ${isDraft(d) ? `<div class="pc-warn" style="margin:0 0 12px"><div><b>ร่างใบสั่งซื้อ ยังไม่ได้สั่ง</b>
                <div style="font-size:12.5px">เพิ่ม/แก้รายการได้ เมื่อสั่งซื้อกับร้านเรียบร้อยแล้ว กด <b>ยืนยันสั่งซื้อแล้ว</b></div></div></div>` : ''}
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
                      <td>${it.item_type === 'supply' ? '<span class="pill muted">ใช้ในกิจการ</span>' : isDraft(d) ? '<span class="pill muted">ร่าง</span>' : isOrdered(d) ? '<span class="pill warn">รอส่งของ</span>' : Number(it.is_new_product) ? '<span class="pill warn">สินค้าใหม่</span>' : '<span class="pill ok">เติมสต๊อก</span>'}</td></tr>`).join('')}
                </tbody></table></div>
              ${evidenceHtml(d)}
              <p class="muted" style="font-size:12.5px;margin:12px 0 0">บันทึกโดย ${escapeHtml(d.user_name || '-')} · ${fmtDateTime(d.created_at)}${d.note ? ` · ${escapeHtml(d.note)}` : ''}</p>
            </div>
            <div class="modal-foot">${isCancelled(d) ? '<span></span>' : `<button type="button" class="btn secondary pc-cancel-btn" data-cancel="${d.id}" data-doc="${escapeHtml(d.doc_no)}">ยกเลิกใบซื้อนี้</button>`}<div class="actions-row">
              <button type="button" class="btn secondary" data-print-view>🖨 พิมพ์</button>
              ${isCancelled(d) ? '' : `<button type="button" class="btn ${isOrdered(d) || isDraft(d) ? 'secondary' : ''}" data-edit="${d.id}">${isDraft(d) ? 'แก้ไขร่าง' : isOrdered(d) ? 'แก้ไขใบสั่งซื้อ' : 'แก้ไขใบซื้อนี้'}</button>`}
              ${isOrdered(d) ? `<button type="button" class="btn" data-receive="${d.id}">รับสินค้าเข้าสต๊อก</button>` : ''}
              ${isDraft(d) ? `<button type="button" class="btn" data-place="${d.id}" data-doc="${escapeHtml(d.doc_no)}">ยืนยันสั่งซื้อแล้ว</button>` : ''}
              <button type="button" class="btn secondary" data-close-view>ปิด</button></div></div>
          </div>
        </div>`;
    }

    /** Icon and name of a .md / .txt evidence file. */
    function fileTileHtml(f) {
        return `<div class="pc-file" style="height:100%"><span class="pc-file-icon">📄</span><span class="pc-file-name">${escapeHtml(f.name || 'ไฟล์ .md')}</span></div>`;
    }

    /** Receipt evidence on the detail modal: photos open full size, .md files open below; editable while active. */
    function evidenceHtml(d) {
        const editable = !isCancelled(d);
        if (!d.images.length && !editable) return '';
        const del = (im) => (editable ? `<button type="button" class="pc-photo-del" data-ev-remove="${escapeHtml(im.token)}" title="ลบหลักฐานนี้">✕</button>` : '');
        return `
          <div class="step-title" style="margin:16px 0 8px"><h2 style="font-size:14px;margin:0">หลักฐานใบเสร็จ (${d.images.length})</h2>
            <span class="muted" style="font-size:12.5px">รูปภาพ หรือไฟล์ .md สรุปการซื้อ</span></div>
          <div class="pc-photos">
            ${d.images.map((im, i) => (isImage(im)
                ? `<div class="pc-photo"><a href="${escapeHtml(attachmentUrl(im.token))}" target="_blank" rel="noopener" style="display:block;height:100%"><img src="${escapeHtml(attachmentUrl(im.token))}" alt="รูปที่ ${i + 1}"></a><span class="pc-photo-no">${i + 1}</span>${del(im)}</div>`
                : `<div class="pc-photo"><button type="button" class="pc-file ${mdOpen?.token === im.token ? 'open' : ''}" data-md-open="${escapeHtml(im.token)}" style="width:100%;height:100%;border:0;background:none" title="เปิดอ่าน">
                    <span class="pc-file-icon">📄</span><span class="pc-file-name">${escapeHtml(im.name || 'ไฟล์ .md')}</span></button><span class="pc-photo-no">${i + 1}</span>${del(im)}</div>`)).join('')}
            ${editable && d.images.length < MAX_PHOTOS ? `
              <label class="pc-add" title="แนบรูปใบเสร็จ หรือไฟล์ .md สรุปการซื้อ">
                <input type="file" accept="${ACCEPT_EVIDENCE}" multiple id="pc-ev-file" hidden ${evidenceBusy ? 'disabled' : ''}>
                <span style="font-size:26px;line-height:1">＋</span>
                <span>${evidenceBusy ? 'กำลังบันทึก...' : 'แนบหลักฐาน'}</span>
              </label>` : ''}
          </div>
          ${mdOpen ? `<div class="pc-md">
            <div class="pc-md-head">📄 <b style="flex:1;word-break:break-all">${escapeHtml(mdOpen.name || 'ไฟล์ .md')}</b>
              <a class="btn secondary sm" href="${escapeHtml(attachmentUrl(mdOpen.token))}?download" title="ดาวน์โหลดไฟล์">ดาวน์โหลด</a>
              <button type="button" class="btn secondary sm" data-md-close>ซ่อน</button></div>
            <div class="ai-bubble">${mdOpen.html}</div>
          </div>` : ''}`;
    }

    function redrawSummary() {
        const el = container.querySelector('#pc-summary');
        if (!el) return;
        const subtotal = round2(items.reduce((s, i) => s + Number(i.line_total || 0), 0));
        // Typing in a summary amount: leave its box alone (rebuilding a number box puts the
        // caret back at the start, so digits came out reversed) and refresh the figures only.
        if (el.contains(document.activeElement) && document.activeElement.dataset.h) {
            const fresh = document.createElement('div');
            fresh.innerHTML = summaryHtml(subtotal);
            fresh.querySelectorAll('[data-sum]').forEach((part) => el.querySelector(`[data-sum="${part.dataset.sum}"]`)?.replaceWith(part));
            el.querySelector('[data-add-vat]')?.addEventListener('click', addVatToLines);
            return;
        }
        el.innerHTML = summaryHtml(subtotal);
        bindHeaderInputs(el);
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
            bindStatus(tr);
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
        container.querySelector('#pc-show-cancelled')?.addEventListener('click', () => {
            showCancelled = !showCancelled;
            try { localStorage.setItem('purchase.showCancelled', showCancelled ? '1' : '0'); } catch (e) { /* private mode */ }
            draw();
        });
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
        container.querySelectorAll('[data-save]').forEach((b) => b.addEventListener('click', () => save(b.dataset.save)));
        container.querySelectorAll('[data-place]').forEach((b) => b.addEventListener('click', () => placeDoc(Number(b.dataset.place), b.dataset.doc)));
        container.querySelectorAll('[data-receive]').forEach((b) => b.addEventListener('click', async () => {
            const formUsed = !editing && items.some((i) => i.name.trim());
            if ((formUsed || editing) && !(await confirmDialog('ข้อมูลที่กรอกค้างไว้ในฟอร์มจะถูกแทนที่ด้วยใบสั่งซื้อที่เลือก', { title: 'แทนที่ข้อมูลในฟอร์ม?', okText: 'ดำเนินการต่อ' }))) return;
            try {
                startEdit((await api.get(`/purchases/${b.dataset.receive}`)).data, { receive: true });
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        }));
        container.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancelDoc(Number(b.dataset.cancel), b.dataset.doc)));
        container.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', async () => {
            try {
                viewing = (await api.get(`/purchases/${b.dataset.view}`)).data;
                mdOpen = null;
                draw();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
        }));
        // The detail modal closes only via its close buttons.
        container.querySelector('[data-print-view]')?.addEventListener('click', () => printPurchaseDoc(viewing));
        container.querySelectorAll('[data-close-view]').forEach((b) => b.addEventListener('click', () => { viewing = null; mdOpen = null; draw(); }));
        container.querySelectorAll('[data-md-open]').forEach((b) => b.addEventListener('click', () => openMd(b.dataset.mdOpen)));
        container.querySelector('[data-md-close]')?.addEventListener('click', () => { mdOpen = null; draw(); });
        const evFile = container.querySelector('#pc-ev-file');
        evFile?.addEventListener('change', () => addEvidence([...evFile.files]));
        container.querySelectorAll('[data-ev-remove]').forEach((b) => b.addEventListener('click', () => removeEvidence(b.dataset.evRemove)));

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
                item_type: it.typeTouched ? it.item_type : null, product_id: chosenProductId(it), new_product: it.forceNew,
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
                    bindStatus(tr);
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
                photos.push(await uploadEvidence(f));
                draw();
            }
            if (files.length > room) error = `แนบได้สูงสุด ${MAX_PHOTOS} ไฟล์ต่อใบเสร็จ`;
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

    /** Uploads a receipt photo or a .md / .txt file. */
    async function uploadEvidence(f) {
        if (isTextFile(f)) {
            const up = await uploadTextFile(f);
            return { token: up.token, mime: up.mime, name: up.name, preview: attachmentUrl(up.token) };
        }
        if (f.type.startsWith('image/')) return uploadImage(f);
        throw new Error(`ไม่รองรับไฟล์ ${f.name} (แนบได้เฉพาะรูปภาพ และไฟล์ .md / .txt)`);
    }

    /** Adds evidence files to the purchase shown in the detail modal (its lines stay as they are). */
    async function addEvidence(files) {
        const d = viewing;
        const room = MAX_PHOTOS - d.images.length;
        if (!files.length || room <= 0) return;
        evidenceBusy = true;
        draw();
        try {
            const added = [];
            for (const f of files.slice(0, room)) added.push((await uploadEvidence(f)).token);
            await saveEvidence(d, [...d.images.map((im) => im.token), ...added]);
            if (files.length > room) alertDialog(`แนบได้สูงสุด ${MAX_PHOTOS} ไฟล์ต่อใบซื้อ`, { tone: 'error' });
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        evidenceBusy = false;
        draw();
    }

    async function removeEvidence(token) {
        const d = viewing;
        const im = d.images.find((x) => x.token === token);
        if (!im || !(await confirmDialog(`${isImage(im) ? 'รูปนี้' : `ไฟล์ ${im.name || '.md'}`} จะถูกนำออกจากใบซื้อ ${d.doc_no}`, { title: 'ลบหลักฐาน?', okText: 'ลบ', danger: true }))) return;
        try {
            await saveEvidence(d, d.images.filter((x) => x.token !== token).map((x) => x.token));
            if (mdOpen?.token === token) mdOpen = null;
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        draw();
    }

    async function saveEvidence(d, tokens) {
        const res = await api.put(`/purchases/${d.id}/attachments`, { attachments: tokens });
        if (viewing === d) viewing = res.data;
        await loadDocs();
    }

    async function openMd(token) {
        if (mdOpen?.token === token) { mdOpen = null; draw(); return; }
        const im = viewing.images.find((x) => x.token === token);
        try {
            const r = await fetch(attachmentUrl(token), { credentials: 'same-origin' });
            if (!r.ok) throw new Error('เปิดไฟล์ไม่ได้');
            mdOpen = { token, name: im?.name, html: markdown(await r.text()) };
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        draw();
    }

    async function extract() {
        if (!photos.some(isImage) || !modelId) return;
        const hasData = items.some((i) => i.name);
        if (hasData && !(await confirmDialog('ข้อมูลรายการที่กรอกไว้จะถูกแทนที่ด้วยผลการอ่านจาก AI', { title: 'อ่านใบเสร็จด้วย AI?', okText: 'ดำเนินการต่อ' }))) return;
        busy = 'extract';
        error = null;
        success = null;
        draw();
        try {
            const res = await api.post('/purchases/extract', { model: modelId, attachments: photos.filter(isImage).map((p) => p.token) });
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

    /** The product a line restocks: one paired by hand, else (editing) the one it was saved with. */
    function chosenProductId(it) {
        if (it.item_type !== 'stock' || it.forceNew) return null;
        return it.pickedId || keptProductId(it);
    }

    /** While editing, a line keeps its product unless it was renamed or switched to a business supply. */
    function keptProductId(it) {
        return it.product_id && it.item_type === 'stock' && it.name.trim() === it.origName ? it.product_id : null;
    }

    /** A draft order was sent to the supplier: it waits for delivery from now on. */
    async function placeDoc(id, docNo, ask = true) {
        if (ask && !(await confirmDialog('สั่งซื้อกับร้านเรียบร้อยแล้ว ใบนี้จะเปลี่ยนเป็น รอรับของ (แก้ไขรายการได้จนกว่าจะรับสินค้า)',
            { title: `ยืนยันสั่งซื้อ ${docNo} แล้ว?`, okText: 'ยืนยันสั่งซื้อแล้ว' }))) return false;
        try {
            await api.post(`/purchases/${id}/place`);
            success = `ยืนยันสั่งซื้อ ${docNo} แล้ว — รอรับของ`;
            error = null;
            viewing = null;
            await loadDocs();
            draw();
            return true;
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
            return false;
        }
    }

    async function cancelDoc(id, docNo) {
        const reason = await promptDialog('สินค้าตามใบนี้จะถูกหักออกจากสต๊อก และไม่นับรวมในรายงาน (ยกเลิกแล้วกู้คืนไม่ได้)\nกรุณาระบุเหตุผลที่ยกเลิก:',
            { title: `ยกเลิกใบซื้อ ${docNo}?`, okText: 'ยกเลิกใบซื้อ', cancelText: 'ไม่ยกเลิก', placeholder: 'เหตุผลที่ยกเลิก', required: 'กรุณาระบุเหตุผลที่ยกเลิก', multiline: true, danger: true });
        if (reason === null) return;
        try {
            const res = await api.post(`/purchases/${id}/cancel`, { reason: reason.trim() });
            success = res.data.ordered
                ? `ยกเลิกใบสั่งซื้อ ${res.data.doc_no} แล้ว (ยังไม่ได้เข้าสต๊อก ไม่มีการหักสต๊อก)`
                : `ยกเลิกใบซื้อ ${res.data.doc_no} แล้ว — หักสินค้าออกจากสต๊อก ${res.data.units} ชิ้น`;
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

    /** mode: 'purchase' (goods in hand / an edit), 'order' (delivered later, no stock), 'receive' (an order's goods arrived). */
    async function save(mode = 'purchase') {
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

        if (mode === 'receive' && !(await confirmDialog('สินค้าตามรายการในฟอร์มจะเข้าสต๊อกทันที และใบสั่งซื้อจะกลายเป็นใบซื้อ (แก้ไขภายหลังได้เหมือนใบซื้อทั่วไป)',
            { title: `ยืนยันรับสินค้าตาม ${editing.doc_no}?`, okText: 'ยืนยันรับสินค้าเข้าสต๊อก' }))) return;
        busy = mode;
        draw();
        try {
            const payload = {
                header: { ...header },
                items: rows.map((it) => ({
                    name: (it.receipt_name || it.name).trim(), product_name: it.name.trim(), pack_size: Math.max(1, Number(it.pack_size) || 1),
                    item_type: it.item_type, product_id: chosenProductId(it), new_product: it.item_type === 'stock' && it.forceNew,
                    barcode: it.barcode.trim(), qty: Number(it.qty), unit_cost: Number(it.unit_cost),
                    line_total: Number(it.line_total), sale_price: Number(it.sale_price) || null,
                })),
                attachments: photos.map((p) => p.token),
                source,
                ...(mode === 'order' && !editing ? { order: true, draft: !orderDraft } : {}),
                ...(mode === 'receive' ? { receive: true } : {}),
            };
            const res = editing ? await api.put(`/purchases/${editing.id}`, payload) : await api.post('/purchases', payload);
            const d = res.data;
            if (d.ordered) {
                const placeAfter = mode === 'place' ? { id: d.id, doc_no: d.doc_no } : null;
                success = d.status === 'draft'
                    ? `บันทึกร่างใบสั่งซื้อ ${d.doc_no} แล้ว: ${d.items} รายการ ยอด ฿${money(d.total)} — ยังไม่ได้สั่ง`
                    : `บันทึกใบสั่งซื้อ ${d.doc_no} แล้ว: ${d.items} รายการ ยอด ฿${money(d.total)} — รอร้านมาส่ง (ยังไม่เข้าสต๊อก)`;
                clearForm();
                formOpen = false;
                busy = null;
                if (placeAfter) {
                    await placeDoc(placeAfter.id, placeAfter.doc_no, false);
                    return;
                }
                await loadDocs();
                draw();
                return;
            }
            const newCount = d.items.filter((i) => i.new).length;
            const supplyCount = d.items.filter((i) => i.type === 'supply').length;
            const unitsIn = d.items.filter((i) => i.type !== 'supply').reduce((n, i) => n + Number(i.units || 0), 0);
            success = `${d.received ? 'รับสินค้าตามใบสั่งซื้อ' : editing ? 'บันทึกการแก้ไขใบซื้อ' : 'บันทึกใบซื้อ'} ${d.doc_no} แล้ว: สินค้าใหม่ ${newCount} รายการ เติมสต๊อก ${d.items.length - newCount - supplyCount} รายการ (เข้าสต๊อกรวม ${unitsIn} ชิ้น)${supplyCount ? ` ใช้ในกิจการ ${supplyCount} รายการ ฿${money(d.supply_total)}` : ''} ยอด ฿${money(d.total)}`;
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
