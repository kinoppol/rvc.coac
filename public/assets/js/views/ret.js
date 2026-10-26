import { api } from '../api.js';
import { money, escapeHtml, fmtDate, fmtDateTime } from '../util.js';
import { alertDialog, promptDialog } from '../dialog.js';

export async function render(container, { user } = {}) {
    const isAdmin = user?.role === 'admin';
    const options = (await api.get('/consignors/options')).data;
    // No consignor until one is chosen, so goods are never returned to someone by accident.
    let consignorId = null;
    let stock = [];
    let qtyByProduct = {};
    let recipient = '';
    let formOpen = false; // the return form modal
    let loading = false;
    let error = null;
    let success = null;

    async function load() {
        stock = [];
        qtyByProduct = {};
        if (consignorId) {
            loading = true;
            draw();
            try {
                stock = (await api.get(`/returns/stock/${consignorId}`)).data;
            } catch (e) {
                error = e.message;
            }
            loading = false;
        }
        draw();
    }

    function totals() {
        const items = stock.map((p) => ({ p, qty: Number(qtyByProduct[p.id] || 0) })).filter((x) => x.qty > 0);
        return {
            lines: items.length,
            qty: items.reduce((s, x) => s + x.qty, 0),
            value: items.reduce((s, x) => s + x.qty * Number(x.p.sale_price), 0),
        };
    }

    function summaryHtml() {
        const t = totals();
        return `
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนรายการ</span><span>${t.lines}</span></div>
              <div style="display:flex;justify-content:space-between"><span class="muted">จำนวนชิ้นรวม</span><span>${t.qty}</span></div>
              <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--border);padding-top:8px"><span>มูลค่ารวม</span><span>฿ ${money(t.value)}</span></div>`;
    }

    function formHtml() {
        const t = totals();
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="rt-title" style="width:1100px">
            <div class="modal-head">
              <h2 id="rt-title">คืนสินค้าให้ผู้ฝากขาย</h2>
              <button type="button" class="modal-close" data-close-form title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
        <div class="two-col">
          <div style="display:flex;flex-direction:column;gap:16px">
            <section class="card card-pad">
              <label class="field">ผู้ฝากขายที่มาขอรับสินค้าคืน *
                <select id="sel-consignor" required>
                  <option value="" ${consignorId == null ? 'selected' : ''} disabled>— เลือกผู้ฝากขาย —</option>
                  ${options.map((o) => `<option value="${o.id}" ${Number(o.id) === Number(consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}</select>
              </label>
            </section>
            ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
            <section class="card">
              <div class="card-head"><h2>สินค้าคงเหลือในร้าน — ใส่จำนวนที่ต้องการคืน</h2></div>
              <div class="table-wrap"><table>
                <thead><tr><th>สินค้า</th><th class="num">ราคาขาย</th><th class="num">คงเหลือ</th><th>วันหมดอายุ</th><th class="num">จำนวนที่คืน</th></tr></thead>
                <tbody>
                  ${!consignorId ? '<tr><td colspan="5" class="empty">เลือกผู้ฝากขายก่อน</td></tr>'
                    : loading ? '<tr><td colspan="5" class="empty">กำลังโหลด...</td></tr>'
                    : stock.map((p) => `
                    <tr>
                      <td>${escapeHtml(p.name)}</td>
                      <td class="num">${money(p.sale_price)}</td>
                      <td class="num">${p.qty_stock}</td>
                      <td>${p.expiry_date ? fmtDate(p.expiry_date) : '-'}</td>
                      <td class="num"><input type="number" min="0" max="${p.qty_stock}" style="width:80px;text-align:right" data-qty="${p.id}" value="${qtyByProduct[p.id] || ''}"></td>
                    </tr>
                  `).join('') || '<tr><td colspan="5" class="empty">ไม่มีสินค้าคงเหลือของผู้ฝากรายนี้</td></tr>'}
                </tbody>
              </table></div>
            </section>
          </div>

          <section class="card card-pad">
            <h2 style="margin-bottom:12px">สรุปใบคืนสินค้า</h2>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13.5px;margin-bottom:14px" data-summary>${summaryHtml()}</div>
            <p class="muted" style="font-size:12px">สินค้าที่คืนไม่มีการคิดค่าฝากขาย เพราะค่าฝากหักตอนขายได้เท่านั้น</p>
            <label class="field" style="margin:12px 0">ผู้รับสินค้าคืน <input id="f-recipient" placeholder="ชื่อผู้มารับ / ผู้รับมอบอำนาจ" value="${escapeHtml(recipient)}"></label>
            <button type="button" class="btn" style="width:100%" id="btn-submit" ${t.lines && consignorId ? '' : 'disabled'}>บันทึกการคืนสินค้า</button>
          </section>
        </div>
            </div>
          </div>
        </div>`;
    }

    function draw() {
        const pending = totals().lines;
        container.innerHTML = `
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
        <div class="actions-row" style="justify-content:space-between">
          <div class="hint" style="flex:1;margin:0"><div class="dot">i</div><div>กด <b>คืนสินค้า</b> เมื่อผู้ฝากขายมาขอรับสินค้าคืน แล้วเลือกผู้ฝากและใส่จำนวนที่คืน</div></div>
          <button type="button" class="btn" id="btn-open" style="font-size:15px;padding:12px 22px">+ คืนสินค้า${pending ? ` (ค้าง ${pending} รายการ)` : ''}</button>
        </div>
        <div id="rt-docs"></div>
        ${formOpen ? formHtml() : ''}`;
        drawDocs();

        container.querySelector('#btn-open').addEventListener('click', () => {
            formOpen = true;
            success = null;
            draw();
            container.querySelector('#sel-consignor')?.focus();
        });
        if (!formOpen) return;
        // Closing keeps what was entered (the «คืนสินค้า» button shows it is waiting).
        container.querySelectorAll('[data-close-form]').forEach((b) => b.addEventListener('click', () => { formOpen = false; error = null; draw(); }));
        container.querySelector('#sel-consignor').addEventListener('change', (e) => {
            consignorId = Number(e.target.value) || null;
            error = null;
            load();
        });
        container.querySelector('#f-recipient').addEventListener('input', (e) => { recipient = e.target.value; });
        // Typing a quantity updates the summary in place, so the box keeps its focus.
        container.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('input', (e) => {
            qtyByProduct[e.target.dataset.qty] = e.target.value;
            container.querySelector('[data-summary]').innerHTML = summaryHtml();
            container.querySelector('#btn-submit').disabled = !(totals().lines && consignorId);
        }));
        container.querySelector('#btn-submit').addEventListener('click', submit);
    }

    async function submit() {
        const items = stock
            .filter((p) => Number(qtyByProduct[p.id] || 0) > 0)
            .map((p) => ({ product_id: p.id, qty: Number(qtyByProduct[p.id]) }));
        try {
            const result = await api.post('/returns', {
                consignor_id: consignorId,
                items,
                recipient_name: recipient || null,
            });
            success = `บันทึกใบคืนสินค้าสำเร็จ: ${result.data.doc_no}`;
            error = null;
            formOpen = false;
            consignorId = null; // the next one starts with no consignor chosen
            recipient = '';
            stock = [];
            qtyByProduct = {};
            draw();
            loadDocs();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    // ---------------- Return documents already saved (admins may cancel one) ----------------

    let docs = [];
    let docsQ = '';
    let docsTimer;
    let docsError = null; // shown in the list, so a failed load doesn't look like an empty one
    let docModal = null; // { el, doc, busy }

    async function loadDocs() {
        try {
            docs = (await api.get(`/returns/docs?q=${encodeURIComponent(docsQ)}`)).data;
            docsError = null;
        } catch (e) {
            docs = [];
            docsError = e.message;
        }
        drawDocs();
    }

    function drawDocs() {
        const box = container.querySelector('#rt-docs');
        if (!box) return;
        const focused = document.activeElement?.id === 'rt-docs-q';
        box.innerHTML = `
        <section class="card" style="margin-top:16px">
          <div class="card-head" style="flex-wrap:wrap;gap:10px">
            <h2>รายการคืนสินค้าผู้ฝาก</h2>
            <input id="rt-docs-q" type="search" value="${escapeHtml(docsQ)}" placeholder="ค้นหาเลขที่ ผู้ฝาก ผู้รับ หรือชื่อสินค้า" style="max-width:280px;flex:1">
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>เลขที่</th><th>วันที่คืน</th><th>ผู้ฝากขาย</th><th>สินค้า</th><th>ผู้รับคืน</th><th class="num">ชิ้น</th><th class="num">มูลค่า (ราคาขาย)</th><th></th></tr></thead>
            <tbody>${docs.map((d) => `
              <tr${d.cancelled_at ? ' style="opacity:.6"' : ''}>
                <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(d.doc_no)}${d.cancelled_at ? '<div><span class="pill muted" style="font-size:11px">ยกเลิกแล้ว</span></div>' : ''}</td>
                <td style="font-size:12.5px;white-space:nowrap">${fmtDateTime(d.created_at)}</td>
                <td>${escapeHtml(d.consignor_code)} - ${escapeHtml(d.consignor_name)}</td>
                <td style="font-size:12.5px;max-width:320px">${escapeHtml(d.names || '')}<div class="muted">${Number(d.items)} รายการ</div>${d.cancelled_at && d.cancel_reason ? `<div class="muted" style="font-size:11.5px">เหตุผลที่ยกเลิก: ${escapeHtml(d.cancel_reason)}</div>` : ''}</td>
                <td style="font-size:12.5px">${escapeHtml(d.recipient_name || '-')}</td>
                <td class="num">${Number(d.total_qty)}</td>
                <td class="num">${money(d.total_value)}</td>
                <td style="text-align:right"><button type="button" class="btn sm secondary" data-doc="${d.id}">ดู</button></td>
              </tr>`).join('') || `<tr><td colspan="8" class="empty">${docsError ? `โหลดรายการไม่สำเร็จ: ${escapeHtml(docsError)}` : docsQ ? 'ไม่พบใบคืนสินค้าที่ค้นหา' : 'ยังไม่มีรายการคืนสินค้า'}</td></tr>`}
            </tbody>
          </table></div>
        </section>`;
        const q = box.querySelector('#rt-docs-q');
        if (focused) { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
        q.addEventListener('input', () => {
            docsQ = q.value.trim();
            clearTimeout(docsTimer);
            docsTimer = setTimeout(loadDocs, 300);
        });
        box.querySelectorAll('[data-doc]').forEach((b) => b.addEventListener('click', () => openDoc(Number(b.dataset.doc))));
    }

    async function openDoc(id) {
        try {
            const doc = (await api.get(`/returns/docs/${id}`)).data;
            docModal = { el: document.createElement('div'), doc, busy: false };
            document.body.appendChild(docModal.el);
            drawDoc();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    function closeDoc() {
        docModal?.el.remove();
        docModal = null;
    }

    function drawDoc() {
        const m = docModal;
        const d = m.doc;
        const canCancel = isAdmin && !d.cancelled_at;
        m.el.innerHTML = `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" aria-label="ใบคืนสินค้า ${escapeHtml(d.doc_no)}" style="width:900px">
            <div class="modal-head">
              <h2>ใบคืนสินค้า ${escapeHtml(d.doc_no)}${d.cancelled_at ? ' <span class="pill muted">ยกเลิกแล้ว</span>' : ''}</h2>
              <button type="button" class="modal-close" data-doc-close aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              ${d.cancelled_at ? `<div class="error-box" style="margin-bottom:12px">ยกเลิกเมื่อ ${fmtDateTime(d.cancelled_at)}${d.cancelled_by_name ? ` โดย ${escapeHtml(d.cancelled_by_name)}` : ''} · เหตุผล: ${escapeHtml(d.cancel_reason || '-')}</div>` : ''}
              <div class="stat-row" style="margin-bottom:14px;font-size:13.5px">
                <div><div class="muted">ผู้ฝากขาย</div><b>${escapeHtml(d.consignor_code)} - ${escapeHtml(d.consignor_name)}</b></div>
                <div><div class="muted">วันที่คืน</div><b>${fmtDateTime(d.created_at)}</b><div class="muted">โดย ${escapeHtml(d.user_name || '-')}</div></div>
                <div><div class="muted">ผู้รับคืน</div><b>${escapeHtml(d.recipient_name || '-')}</b></div>
                <div><div class="muted">รวม</div><b>${Number(d.total_qty)} ชิ้น · ฿ ${money(d.total_value)}</b></div>
              </div>
              <div class="table-wrap" style="border:1px solid var(--border);border-radius:10px"><table>
                <thead><tr><th>สินค้า</th><th class="num">จำนวนที่คืน</th><th class="num">ราคาขาย</th><th class="num">มูลค่า</th><th>วันหมดอายุ</th><th class="num">คงเหลือในร้านตอนนี้</th></tr></thead>
                <tbody>${d.items.map((it) => `
                  <tr>
                    <td>${escapeHtml(it.name)}${it.barcode ? `<div class="muted" style="font-size:11.5px">บาร์โค้ด ${escapeHtml(it.barcode)}</div>` : ''}</td>
                    <td class="num">${Number(it.qty)}</td>
                    <td class="num">${money(it.sale_price)}</td>
                    <td class="num">${money(Number(it.qty) * Number(it.sale_price))}</td>
                    <td style="font-size:12.5px">${it.expiry_date ? fmtDate(it.expiry_date) : '-'}</td>
                    <td class="num">${Number(it.qty_stock)}</td>
                  </tr>`).join('')}
                </tbody>
              </table></div>
              ${canCancel ? '<div class="muted" style="font-size:12px;margin-top:8px">ผู้ดูแลระบบยกเลิกการคืนได้ (เช่น บันทึกผิดหรือผู้ฝากยังไม่ได้รับของ): สินค้าทุกรายการในใบนี้จะกลับเข้าสต๊อกของร้าน ใบคืนเดิมยังอยู่และแสดงว่ายกเลิกแล้ว</div>' : ''}
            </div>
            <div class="modal-foot">
              ${canCancel ? `<button type="button" class="btn danger" id="rt-doc-cancel" ${m.busy ? 'disabled' : ''}>ยกเลิกการคืนสินค้า</button>` : '<span></span>'}
              <button type="button" class="btn secondary" data-doc-close>ปิด</button>
            </div>
          </div>
        </div>`;
        m.el.querySelectorAll('[data-doc-close]').forEach((b) => b.addEventListener('click', closeDoc));
        m.el.querySelector('#rt-doc-cancel')?.addEventListener('click', cancelDoc);
    }

    async function cancelDoc() {
        const m = docModal;
        const d = m.doc;
        const reason = await promptDialog(
            `สินค้า ${d.items.length} รายการ (${Number(d.total_qty)} ชิ้น) ของ ${d.consignor_name} จะกลับเข้าสต๊อกของร้าน (ใบคืนเดิมยังอยู่ แสดงว่ายกเลิกแล้ว)`,
            { title: `ยกเลิกใบคืนสินค้า ${d.doc_no}?`, okText: 'ยกเลิกการคืนสินค้า', placeholder: 'เหตุผลที่ยกเลิก (จำเป็น)', required: true, danger: true }
        );
        if (reason === null || reason === undefined || !String(reason).trim() || docModal !== m) return;
        m.busy = true;
        drawDoc();
        try {
            m.doc = (await api.post(`/returns/docs/${d.id}/cancel`, { reason: String(reason).trim() })).data;
            loadDocs();
            if (consignorId === Number(d.consignor_id)) load(); // the return form's stock list is now stale
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        m.busy = false;
        drawDoc();
    }

    window.addEventListener('hashchange', closeDoc, { once: true });

    draw();
    loadDocs();
}
