import { money, count, escapeHtml, fmtDate } from './util.js';

// ตรวจใบสั่งซื้อกับใบเสร็จ: what was really bought often differs from the draft / order —
// some lines were not available, some were bought on top, prices changed. The receipt
// (a photo or a .md file, read by the usual AI pipeline) is matched line by line with the
// order, and the user reviews every difference here before the form is changed.

const norm = (s) => String(s || '').toLowerCase().replace(/[\s()[\]{}.,\-_/\\'"*#:;+]+/g, '');
const num = (n) => Number(n) || 0;

/**
 * Pairs each receipt line with an order line (each order line once): same barcode, then the
 * same product, then the same name, then one name containing the other.
 * @param {Array<{name:string, receipt_name?:string, barcode?:string, productId?:number|null}>} order
 * @param {Array<{name:string, product_name?:string, barcode?:string, productId?:number|null}>} rec
 * @returns {number[]} for each receipt line the order line's index, or -1
 */
export function matchReceipt(order, rec) {
    const pair = rec.map(() => -1);
    const used = new Set();
    const names = (x) => [x.name, x.receipt_name, x.product_name].map(norm).filter(Boolean);
    const passes = [
        (o, r) => !!r.barcode && !!o.barcode && String(r.barcode).trim() === String(o.barcode).trim(),
        (o, r) => !!r.productId && !!o.productId && Number(r.productId) === Number(o.productId),
        (o, r) => names(r).some((a) => names(o).includes(a)),
        (o, r) => names(r).some((a) => a.length >= 4 && names(o).some((b) => b.length >= 4 && (a.includes(b) || b.includes(a)))),
    ];
    for (const same of passes) {
        rec.forEach((r, i) => {
            if (pair[i] >= 0) return;
            const j = order.findIndex((o, k) => !used.has(k) && same(o, r));
            if (j >= 0) { pair[i] = j; used.add(j); }
        });
    }
    return pair;
}

/** What changes on an order line when it takes the receipt line's figures. */
export function lineChanges(o, r) {
    const out = [];
    if (num(o.qty) !== num(r.qty)) out.push(`จำนวน ${count(o.qty)} → <b>${count(r.qty)}</b>`);
    if (Math.abs(num(o.unit_cost) - num(r.unit_cost)) >= 0.005) {
        const up = num(r.unit_cost) > num(o.unit_cost);
        out.push(`ทุน/หน่วย ${money(o.unit_cost)} → <b style="color:var(${up ? '--bad' : '--ok'})">${money(r.unit_cost)}</b>`);
    }
    if (Math.abs(num(o.line_total) - num(r.line_total)) >= 0.005) out.push(`รวม ${money(o.line_total)} → <b>${money(r.line_total)}</b>`);
    return out;
}

/**
 * The review dialog. Resolves to null (closed) or
 * { pair: number[] (receipt line → order line, -1 = add as a new line, -2 = leave out),
 *   remove: number[] (order lines not received, to take out), header: boolean }.
 */
export function openReconcile({ docNo, order, rec, receipt = {}, warnings = [] }) {
    return new Promise((resolve) => {
        const pair = matchReceipt(order, rec);
        const keepMissing = new Set(); // order lines not on the receipt that the user keeps anyway
        let useHeader = true;

        const el = document.createElement('div');
        el.className = 'modal-backdrop';
        document.body.appendChild(el);
        const done = (v) => { el.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
        const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === el) done(null); };
        document.addEventListener('keydown', onKey);

        const missing = () => order.map((o, k) => k).filter((k) => !pair.includes(k));
        const sumOrder = order.reduce((s, o) => s + num(o.line_total), 0);
        const headerBits = [
            receipt.receipt_no && `เลขที่ ${escapeHtml(receipt.receipt_no)}`,
            receipt.receipt_date && `วันที่ ${escapeHtml(fmtDate(receipt.receipt_date))}`,
            num(receipt.discount) && `ส่วนลด ฿${money(receipt.discount)}`,
            num(receipt.vat) && `VAT ฿${money(receipt.vat)}`,
            num(receipt.total) && `ยอดสุทธิ ฿${money(receipt.total)}`,
        ].filter(Boolean);

        function recRowHtml(r, i) {
            const k = pair[i];
            const o = k >= 0 ? order[k] : null;
            const changes = o ? lineChanges(o, r) : [];
            const state = k === -2 ? '<span class="pill muted">ไม่ใช้</span>'
                : !o ? '<span class="pill warn">ได้เพิ่ม</span>'
                    : changes.length ? '<span class="pill warn">เปลี่ยน</span>' : '<span class="pill ok">ตรงกัน</span>';
            const opts = order.map((x, j) => {
                const taken = j !== k && pair.includes(j);
                return `<option value="${j}" ${j === k ? 'selected' : ''}>${taken ? '• ' : ''}#${j + 1} ${escapeHtml(x.name)} (${count(x.qty)} × ${money(x.unit_cost)})</option>`;
            }).join('');
            return `<tr data-rec="${i}" class="${k === -2 ? 'po-off' : ''}">
              <td class="muted">${i + 1}</td>
              <td>${escapeHtml(r.name)}${r.product_name && r.product_name !== r.name ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.product_name)}</div>` : ''}${r.barcode ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.barcode)}</div>` : ''}</td>
              <td class="num" style="white-space:nowrap">${count(r.qty)} × ${money(r.unit_cost)}<div class="muted">= ${money(r.line_total)}</div></td>
              <td><select data-pair style="max-width:300px" aria-label="รายการในใบสั่งซื้อที่ตรงกับรายการที่ ${i + 1} ของใบเสร็จ">
                <option value="-1" ${k === -1 ? 'selected' : ''}>＋ เพิ่มเป็นรายการใหม่ (ไม่อยู่ในใบสั่งซื้อ)</option>
                <option value="-2" ${k === -2 ? 'selected' : ''}>✕ ไม่ใช้รายการนี้</option>
                <optgroup label="ตรงกับรายการในใบสั่งซื้อ">${opts}</optgroup></select></td>
              <td style="font-size:12.5px">${changes.join('<br>') || (o ? '<span class="muted">ไม่เปลี่ยน</span>' : '')}</td>
              <td>${state}</td>
            </tr>`;
        }

        function draw() {
            const miss = missing();
            const used = rec.filter((r, i) => pair[i] !== -2);
            const matched = rec.filter((r, i) => pair[i] >= 0);
            const changed = rec.filter((r, i) => pair[i] >= 0 && lineChanges(order[pair[i]], r).length).length;
            const extra = rec.filter((r, i) => pair[i] === -1).length;
            const removing = miss.filter((k) => !keepMissing.has(k)).length;
            const sumRec = used.reduce((s, r) => s + num(r.line_total), 0);
            el.innerHTML = `
              <div class="modal" style="width:1080px" role="dialog" aria-modal="true" aria-labelledby="rc-title">
                <div class="modal-head"><h2 id="rc-title">ตรวจ ${escapeHtml(docNo)} กับใบเสร็จ</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
                <div class="modal-body">
                  <div class="hint" style="margin-bottom:12px"><div class="dot">i</div><div>
                    AI อ่านใบเสร็จได้ <b>${count(rec.length)}</b> รายการ เทียบกับใบสั่งซื้อ <b>${count(order.length)}</b> รายการ ·
                    ตรวจการจับคู่ในช่อง "ในใบสั่งซื้อ" (เปลี่ยนได้) แล้วกด <b>ปรับตามใบเสร็จ</b> — รายการที่ตรงกันจะใช้จำนวนและราคาทุนตามใบเสร็จ
                    รายการที่ได้เพิ่มจะถูกเพิ่มเข้าไป และรายการที่ไม่ได้รับจะถูกนำออก ยังไม่บันทึกจนกว่าจะกดบันทึกในฟอร์ม</div></div>
                  <div class="stat-row" style="margin-bottom:12px;font-size:13.5px">
                    <div><div class="muted">ตรงกัน</div><b>${count(matched.length)}</b> <span class="muted">(เปลี่ยน ${count(changed)})</span></div>
                    <div><div class="muted">ได้เพิ่ม</div><b>${count(extra)}</b></div>
                    <div><div class="muted">ไม่ได้รับ</div><b>${count(miss.length)}</b></div>
                    <div><div class="muted">ยอดรวมรายการ</div>฿${money(sumOrder)} → <b>฿${money(sumRec)}</b></div>
                  </div>
                  ${warnings.length ? `<div class="pc-warn" style="margin:0 0 12px"><b>ข้อสังเกตจาก AI</b><ul>${warnings.map((w) => `<li>${escapeHtml(w)}</li>`).join('')}</ul></div>` : ''}
                  <h3 style="font-size:14.5px;margin:0 0 6px">รายการในใบเสร็จ</h3>
                  <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px;max-height:46vh"><table>
                    <thead><tr><th>#</th><th>ในใบเสร็จ</th><th class="num">จำนวน × ทุน</th><th>ในใบสั่งซื้อ</th><th>เปลี่ยนแปลง</th><th></th></tr></thead>
                    <tbody>${rec.map(recRowHtml).join('') || '<tr><td colspan="6" class="empty">ไม่พบรายการในใบเสร็จ</td></tr>'}</tbody>
                  </table></div>
                  ${miss.length ? `
                    <h3 style="font-size:14.5px;margin:14px 0 6px">อยู่ในใบสั่งซื้อ แต่ไม่มีในใบเสร็จ (ไม่ได้รับ)</h3>
                    <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
                      <thead><tr><th>#</th><th>สินค้า</th><th class="num">จำนวน × ทุน</th><th>นำออกจากใบนี้</th></tr></thead>
                      <tbody>${miss.map((k) => `<tr class="${keepMissing.has(k) ? '' : 'po-off'}">
                        <td class="muted">${k + 1}</td><td>${escapeHtml(order[k].name)}</td>
                        <td class="num">${count(order[k].qty)} × ${money(order[k].unit_cost)}</td>
                        <td><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-miss="${k}" ${keepMissing.has(k) ? '' : 'checked'}> ไม่ได้รับ นำออก</label></td></tr>`).join('')}</tbody>
                    </table></div>` : ''}
                  ${headerBits.length ? `<label style="display:flex;gap:8px;align-items:center;margin-top:14px">
                    <input type="checkbox" data-header ${useHeader ? 'checked' : ''}> ใช้ข้อมูลจากใบเสร็จ: ${headerBits.join(' · ')}</label>` : ''}
                </div>
                <div class="modal-foot">
                  <span class="muted" style="font-size:12.5px">ปรับแล้ว: ${count(matched.length)} ตรงกัน · เพิ่ม ${count(extra)} · นำออก ${count(removing)} — ตรวจราคาขายในฟอร์มอีกครั้งถ้าทุนเปลี่ยน</span>
                  <div class="actions-row">
                    <button type="button" class="btn secondary" data-x>ยกเลิก</button>
                    <button type="button" class="btn" data-ok ${used.length || removing ? '' : 'disabled'}>ปรับตามใบเสร็จ</button>
                  </div>
                </div>
              </div>`;
            el.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => done(null)));
            el.querySelectorAll('tr[data-rec]').forEach((tr) => {
                tr.querySelector('[data-pair]').addEventListener('change', (e) => {
                    const i = Number(tr.dataset.rec);
                    const v = Number(e.target.value);
                    // An order line belongs to one receipt line: the one it was paired with before becomes new.
                    if (v >= 0) pair.forEach((p, x) => { if (p === v && x !== i) pair[x] = -1; });
                    pair[i] = v;
                    draw();
                });
            });
            el.querySelectorAll('[data-miss]').forEach((c) => c.addEventListener('change', () => {
                const k = Number(c.dataset.miss);
                if (c.checked) keepMissing.delete(k); else keepMissing.add(k);
                draw();
            }));
            el.querySelector('[data-header]')?.addEventListener('change', (e) => { useHeader = e.target.checked; });
            el.querySelector('[data-ok]').addEventListener('click', () => done({
                pair: [...pair],
                remove: missing().filter((k) => !keepMissing.has(k)),
                header: useHeader && headerBits.length > 0,
            }));
        }
        draw();
    });
}
