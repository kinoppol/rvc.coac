import { api } from './api.js';
import { money, count, escapeHtml, fmtDateTime } from './util.js';
import { alertDialog, confirmDialog, promptDialog } from './dialog.js';
import { skBlock } from './skeleton.js';

// Split a store product into separate products: a pack bought as one item whose pieces carry
// barcodes of their own (mixed flavours, colours...). Part of the stock moves to each piece
// product (new, or one that already exists) at the pack's cost per piece, as an SP-... document.

const blankRow = (price) => ({ product_id: null, name: '', barcode: '', sale_price: String(price || ''), qty: '', stock: null, error: null });

export async function openSplitModal(productId, { onDone } = {}) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:900px"><div class="modal-body">' + skBlock(5, 6) + '</div></div>';
    document.body.appendChild(box);
    let changed = false;
    const close = () => {
        box.remove();
        document.removeEventListener('keydown', onKey);
        if (changed) onDone?.();
    };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let info;
    try {
        info = (await api.get(`/inventory/${productId}/split`)).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    let rows = [blankRow(info.product.sale_price)];
    let note = '';
    let busy = false;
    let error = null;
    let found = []; // search results for adding an existing product
    let q = '';

    const p = () => info.product;
    const total = () => rows.reduce((s, r) => s + (Math.max(0, Math.floor(Number(r.qty))) || 0), 0);

    function totalHtml() {
        const t = total();
        const left = p().qty_stock - t;
        return `แยกรวม <b>${count(t)}</b> ชิ้น จากคงเหลือ ${count(p().qty_stock)} ชิ้น ·
          ${left < 0 ? `<b style="color:var(--bad)">เกินคงเหลือ ${count(-left)} ชิ้น</b>` : `เหลือที่สินค้าเดิม ${count(left)} ชิ้น`}
          · มูลค่าทุนที่ย้าย ฿${money(t * p().cost_price)}`;
    }

    function rowHtml(r, i) {
        const qty = `<label class="field" style="width:110px">จำนวน (ชิ้น) <input data-f="qty" data-i="${i}" type="number" min="1" step="1" inputmode="numeric" value="${escapeHtml(r.qty)}"></label>`;
        const del = `<button type="button" class="btn secondary sm" data-del="${i}" title="ลบแถวนี้" aria-label="ลบแถวนี้" style="align-self:flex-end;margin-bottom:6px">✕</button>`;
        const err = r.error ? `<div class="error-box" style="flex-basis:100%;margin:0">${escapeHtml(r.error)}</div>` : '';
        if (r.product_id) {
            return `<div class="sp-row" style="display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-start;padding:10px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px">
              <div style="flex:1;min-width:220px"><div class="muted" style="font-size:12px">สินค้าที่มีอยู่แล้ว</div>
                <b>${escapeHtml(r.name)}</b>
                <div class="muted" style="font-size:12.5px">บาร์โค้ด ${r.barcode ? escapeHtml(r.barcode) : '-'} · คงเหลือ ${count(r.stock ?? 0)} · ราคาขาย ฿${money(r.sale_price)}</div>
                <button type="button" class="link-btn" data-new="${i}" style="font-size:12px">เปลี่ยนเป็นสร้างสินค้าใหม่</button></div>
              ${qty}${del}${err}</div>`;
        }
        return `<div class="sp-row" style="display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-start;padding:10px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px">
          <label class="field" style="width:170px">บาร์โค้ดชิ้น <input data-f="barcode" data-i="${i}" data-barcode autocomplete="off" value="${escapeHtml(r.barcode)}" placeholder="สแกน / พิมพ์"></label>
          <label class="field" style="flex:1;min-width:200px">ชื่อสินค้าใหม่ <input data-f="name" data-i="${i}" maxlength="191" value="${escapeHtml(r.name)}" placeholder="เช่น ${escapeHtml(p().name)} รสส้ม"></label>
          <label class="field" style="width:120px">ราคาขาย/ชิ้น <input data-f="sale_price" data-i="${i}" type="number" min="0" step="0.25" value="${escapeHtml(r.sale_price)}"></label>
          ${qty}${del}${err}</div>`;
    }

    function historyHtml() {
        if (!info.history.length) return '';
        return `<details style="margin-top:14px">
          <summary style="cursor:pointer;font-weight:600;font-size:14px">ประวัติการแยกสินค้านี้ (${count(info.history.length)})</summary>
          ${info.history.map((h) => `
            <div style="border:1px solid var(--border);border-radius:10px;padding:10px;margin-top:8px;${h.cancelled_at ? 'opacity:.65' : ''}">
              <div style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center">
                <b>${escapeHtml(h.doc_no)}</b>
                <span class="muted" style="font-size:12.5px">${escapeHtml(fmtDateTime(h.created_at))} · ${escapeHtml(h.user_name || '-')} · ${count(h.qty)} ชิ้น ทุน ฿${money(h.unit_cost)}/ชิ้น</span>
                ${h.cancelled_at ? `<span class="pill muted">ยกเลิกแล้ว: ${escapeHtml(h.cancel_reason || '')}</span>` : ''}
                <span style="flex:1"></span>
                ${!h.cancelled_at ? `<button type="button" class="btn secondary sm" data-cancel="${h.id}" ${h.cancellable && !busy ? '' : 'disabled'} title="${escapeHtml(h.cancel_block || 'คืนชิ้นย่อยทั้งหมดกลับเข้าสินค้าเดิม')}">ยกเลิกการแยก</button>` : ''}
              </div>
              ${h.note ? `<div class="muted" style="font-size:12.5px">หมายเหตุ: ${escapeHtml(h.note)}</div>` : ''}
              <div style="font-size:13px;margin-top:4px">${h.items.map((it) => `${escapeHtml(it.name)}${it.barcode ? ` <span class="muted">(${escapeHtml(it.barcode)})</span>` : ''} × ${count(it.qty)}${it.created_product ? ' <span class="pill ok" style="font-size:11px">ใหม่</span>' : ''}`).join(' · ')}</div>
              ${!h.cancelled_at && h.cancel_block ? `<div class="muted" style="font-size:12px;margin-top:4px">ยกเลิกไม่ได้ — ${escapeHtml(h.cancel_block)}</div>` : ''}
            </div>`).join('')}
        </details>`;
    }

    function draw() {
        const src = p();
        box.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="width:900px">
            <div class="modal-head">
              <h2>แยกสินค้า: ${escapeHtml(src.name)}</h2>
              <button type="button" class="modal-close" data-x title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              <p class="muted" style="margin:0 0 10px;font-size:13px">ใช้กับสินค้าที่ซื้อมาเป็นแพ็ค แต่ข้างในมีหลายแบบที่มีบาร์โค้ดของตัวเอง (เช่น คละรส คละสี)
                ย้ายจำนวนชิ้นออกจากสินค้านี้ไปเป็นสินค้าแยกแต่ละแบบ ต้นทุน/ชิ้นเท่ากับสินค้าเดิม (฿${money(src.cost_price)})
                · บาร์โค้ดที่เป็นของสินค้าที่มีอยู่แล้ว ระบบจะใช้สินค้านั้น (ต้นทุนเฉลี่ยใหม่ตามจำนวนที่รับเพิ่ม)</p>
              <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-bottom:12px;font-size:13.5px">
                <span>คงเหลือ <b>${count(src.qty_stock)}</b> ชิ้น</span>
                <span>ต้นทุน/ชิ้น <b>฿${money(src.cost_price)}</b></span>
                <span>ราคาขาย/ชิ้น <b>฿${money(src.sale_price)}</b></span>
                ${src.barcode ? `<span class="muted">บาร์โค้ดชิ้น ${escapeHtml(src.barcode)}</span>` : ''}
              </div>
              ${src.consignor_id != null ? '<div class="error-box">แยกได้เฉพาะสินค้าของร้าน</div>' : src.qty_stock < 1 ? '<div class="error-box">สินค้านี้ไม่มีคงเหลือให้แยก</div>' : `
              ${info.template.length ? `<div class="actions-row" style="margin-bottom:10px">
                <button type="button" class="btn secondary sm" data-template>ใช้รายการเดิมจากการแยกครั้งก่อน (${count(info.template.length)} แบบ)</button></div>` : ''}
              ${rows.map(rowHtml).join('')}
              <div class="actions-row" style="flex-wrap:wrap;gap:8px;margin:4px 0 10px">
                <button type="button" class="btn secondary sm" data-add>+ เพิ่มสินค้าใหม่</button>
                <span class="muted" style="font-size:12.5px">หรือเพิ่มสินค้าที่มีอยู่:</span>
                <input id="sp-q" placeholder="ค้นหาชื่อ / บาร์โค้ด" autocomplete="off" value="${escapeHtml(q)}" style="width:220px">
              </div>
              ${found.length ? `<div style="border:1px solid var(--border);border-radius:10px;max-height:200px;overflow:auto;margin-bottom:10px">${found.map((f, i) => `
                <div style="display:flex;gap:10px;align-items:center;padding:6px 10px;border-bottom:1px solid var(--border)">
                  <span style="flex:1">${escapeHtml(f.name)} <span class="muted" style="font-size:12px">${f.barcode ? escapeHtml(f.barcode) + ' · ' : ''}คงเหลือ ${count(f.qty_stock)}</span></span>
                  ${f.consignor_id != null ? '<span class="muted" style="font-size:12px">สินค้าฝากขาย</span>'
                    : Number(f.id) === Number(src.id) ? '<span class="muted" style="font-size:12px">สินค้าตัวนี้</span>'
                    : `<button type="button" class="btn secondary sm" data-pick="${i}">เพิ่ม</button>`}
                </div>`).join('')}</div>` : ''}
              <label class="field">หมายเหตุ <input id="sp-note" maxlength="255" value="${escapeHtml(note)}" placeholder="ไม่บังคับ"></label>
              <div id="sp-total" style="margin-top:10px;font-size:13.5px">${totalHtml()}</div>
              ${error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(error)}</div>` : ''}`}
              ${historyHtml()}
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-x>ปิด</button>
              ${src.consignor_id == null && src.qty_stock > 0 ? `<button type="button" class="btn" id="sp-save" ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึกการแยกสินค้า'}</button>` : ''}
            </div>
          </div>`;
        bind();
    }

    function bind() {
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('[data-f]').forEach((inp) => {
            const r = rows[Number(inp.dataset.i)];
            inp.addEventListener('input', () => {
                r[inp.dataset.f] = inp.value;
                if (inp.dataset.f === 'qty') box.querySelector('#sp-total').innerHTML = totalHtml();
            });
            if (inp.dataset.f === 'barcode') {
                inp.addEventListener('change', () => lookup(r));
                inp.addEventListener('keydown', (e) => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    lookup(r);
                });
            }
        });
        box.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => {
            rows.splice(Number(b.dataset.del), 1);
            if (!rows.length) rows.push(blankRow(p().sale_price));
            draw();
        }));
        box.querySelectorAll('[data-new]').forEach((b) => b.addEventListener('click', () => {
            const r = rows[Number(b.dataset.new)];
            Object.assign(r, { product_id: null, name: '', barcode: '', sale_price: String(p().sale_price), stock: null, error: null });
            draw();
        }));
        box.querySelector('[data-add]')?.addEventListener('click', () => {
            rows.push(blankRow(p().sale_price));
            draw();
            const ins = box.querySelectorAll('[data-f="barcode"]');
            ins[ins.length - 1]?.focus();
        });
        box.querySelector('[data-template]')?.addEventListener('click', () => {
            const keep = rows.filter((r) => r.product_id || r.name.trim() || r.barcode.trim());
            const have = new Set(keep.filter((r) => r.product_id).map((r) => Number(r.product_id)));
            rows = [...keep, ...info.template.filter((t) => !have.has(Number(t.product_id))).map((t) => ({
                product_id: t.product_id, name: t.name, barcode: t.barcode || '', sale_price: t.sale_price, qty: '', stock: t.qty_stock, error: null,
            }))];
            draw();
            box.querySelector('[data-f="qty"]')?.focus();
        });
        const qi = box.querySelector('#sp-q');
        let timer = null;
        qi?.addEventListener('input', () => {
            q = qi.value;
            clearTimeout(timer);
            timer = setTimeout(search, 300);
        });
        qi?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); } });
        box.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
            const f = found[Number(b.dataset.pick)];
            addExisting(f);
            found = [];
            q = '';
            draw();
        }));
        box.querySelector('#sp-note')?.addEventListener('input', (e) => { note = e.target.value; });
        box.querySelector('#sp-save')?.addEventListener('click', save);
        box.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancelSplit(Number(b.dataset.cancel))));
    }

    function addExisting(f, into = null) {
        if (rows.some((r) => Number(r.product_id) === Number(f.id))) {
            if (into) Object.assign(into, { error: `"${f.name}" อยู่ในรายการแล้ว` });
            return;
        }
        const target = into || rows.find((r) => !r.product_id && !r.name.trim() && !r.barcode.trim()) || (rows.push(blankRow(0)), rows[rows.length - 1]);
        Object.assign(target, {
            product_id: Number(f.id), name: f.name, barcode: f.barcode || '', sale_price: f.sale_price, stock: Number(f.qty_stock), error: null,
            qty: target.qty,
        });
    }

    async function search() {
        const term = q.trim();
        if (!term) { found = []; draw(); return; }
        try {
            found = (await api.get(`/inventory/search?q=${encodeURIComponent(term)}`)).data;
        } catch (e) {
            found = [];
        }
        draw();
        const qi = box.querySelector('#sp-q');
        qi?.focus();
        qi?.setSelectionRange(qi.value.length, qi.value.length);
    }

    /** A typed / scanned barcode that already belongs to a product: use that product. */
    async function lookup(r) {
        const code = r.barcode.trim();
        r.error = null;
        if (!code || r.product_id) { draw(); return; }
        let f = null;
        try {
            f = (await api.get(`/inventory/lookup?code=${encodeURIComponent(code)}`)).data;
        } catch (e) {
            f = null; // a new barcode: a new product
        }
        if (f) {
            if (Number(f.scanned_pack_size) > 1) r.error = `บาร์โค้ด ${code} เป็นบาร์โค้ดแพ็คของ "${f.name}" ใช้เป็นบาร์โค้ดชิ้นไม่ได้`;
            else if (f.consignor_id != null) r.error = `บาร์โค้ด ${code} เป็นของสินค้าฝากขาย "${f.name}"`;
            else if (Number(f.id) === Number(p().id)) r.error = `บาร์โค้ด ${code} เป็นบาร์โค้ดของสินค้าตัวนี้เอง`;
            else addExisting(f, r);
        }
        draw();
        const next = box.querySelectorAll('[data-f="name"], [data-f="qty"]');
        const idx = rows.indexOf(r);
        const target = [...next].find((el) => Number(el.dataset.i) === idx && (r.product_id ? el.dataset.f === 'qty' : el.dataset.f === 'name'));
        target?.focus();
    }

    async function save() {
        error = null;
        const items = rows.filter((r) => r.product_id || r.name.trim() || r.barcode.trim() || Number(r.qty) > 0);
        for (const r of items) {
            const qty = Number(r.qty);
            if (!Number.isInteger(qty) || qty < 1) error = `${r.name || r.barcode || 'แถวใหม่'}: กรุณาระบุจำนวนชิ้น (จำนวนเต็ม)`;
            else if (!r.product_id && !r.name.trim()) error = `บาร์โค้ด ${r.barcode || '-'}: กรุณากรอกชื่อสินค้าใหม่`;
            else if (!r.product_id && !(Number(r.sale_price) > 0)) error = `${r.name}: ราคาขายต้องมากกว่า 0`;
            if (error) break;
        }
        if (!error && !items.length) error = 'กรุณาเพิ่มสินค้าที่จะแยกอย่างน้อย 1 รายการ';
        if (!error && total() > p().qty_stock) error = `แยกได้ไม่เกินยอดคงเหลือ ${count(p().qty_stock)} ชิ้น`;
        if (error) { draw(); return; }
        const fresh = items.filter((r) => !r.product_id).length;
        const ok = await confirmDialog(
            `ย้าย ${count(total())} ชิ้นออกจาก «${p().name}» ไปเป็น ${count(items.length)} รายการ${fresh ? ` (สร้างสินค้าใหม่ ${count(fresh)} รายการ)` : ''} ต้นทุน ฿${money(p().cost_price)}/ชิ้น`,
            { title: 'บันทึกการแยกสินค้า?', okText: 'บันทึก' }
        );
        if (!ok) return;
        busy = true;
        draw();
        try {
            const out = (await api.post(`/inventory/${productId}/split`, {
                note,
                items: items.map((r) => (r.product_id
                    ? { product_id: r.product_id, qty: Number(r.qty) }
                    : { name: r.name.trim(), barcode: r.barcode.trim(), sale_price: Number(r.sale_price), qty: Number(r.qty) })),
            })).data;
            changed = true;
            info = out;
            rows = [blankRow(info.product.sale_price)];
            note = '';
            busy = false;
            draw();
            alertDialog(`บันทึกการแยกสินค้า ${out.split?.doc_no || ''} แล้ว`, { title: 'สำเร็จ' });
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    async function cancelSplit(id) {
        const h = info.history.find((x) => x.id === id);
        const reason = await promptDialog(
            `ชิ้นย่อย ${count(h.qty)} ชิ้นจะถูกคืนกลับเข้า «${p().name}» (สินค้าที่สร้างจากการแยกยังอยู่ คงเหลือ 0)`,
            { title: `ยกเลิกการแยกสินค้า ${h.doc_no}?`, okText: 'ยกเลิกการแยก', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
        );
        if (reason === null || reason === undefined || !String(reason).trim()) return;
        busy = true;
        draw();
        try {
            info = (await api.post(`/inventory/splits/${id}/cancel`, { reason: String(reason).trim() })).data;
            changed = true;
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        busy = false;
        draw();
    }

    draw();
    box.querySelector('[data-f="barcode"]')?.focus();
}
