import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';
import { skBlock } from '../skeleton.js';

/**
 * นำเข้ายอดขายจากระบบ POS ภายนอก: the report has item names (and, in newer
 * reports, the POS's own SKU) but no barcode known here, so each item is paired
 * with a product; a saved pair is found by SKU first, then by name. Pairs saved from an
 * earlier import come back green; new pairs (AI or name/cost suggestions, or
 * picked by hand) are yellow until the import is saved and they are remembered.
 */
const OWNER_STORE = 'สินค้าของร้าน';

export async function render(container, { permissions = [] } = {}) {
    const canCancel = permissions.includes('sales.import.cancel');
    let tab = 'import';
    let step = 'input'; // input | review
    let text = '';
    let sourceLabel = '';
    let saleDate = todayISO();
    let dateTouched = false;
    let rows = [];
    let ai = null;
    let warnings = [];
    let previous = [];
    let filter = 'all';
    let allowShort = false;
    let busy = null; // 'analyze' | 'save'
    // AI pairing runs a few lines per request after the table is shown.
    let aiRun = { running: false, done: 0, total: 0, searches: 0, stopped: false, error: null };
    const AI_BATCH = 10;
    const MODEL_KEY = 'posimport.model';
    let models = [];
    let modelId = null;
    let error = null;
    let success = null;
    let recent = [];
    let consignors = [];
    let editing = null; // row index shown in the pairing modal
    let modalTab = 'pick';
    let viewing = null; // an earlier import opened from the "นำเข้าล่าสุด" list
    let repairing = null; // admin re-pairing one line of `viewing`: { item, no, q, results, product, pairs, mapIds, busy, error }
    let mappings = [];
    let mapQ = '';

    async function loadRecent() {
        try { recent = (await api.get('/pos-import')).data; } catch (e) { recent = []; }
    }
    try { consignors = (await api.get('/consignors/options')).data; } catch (e) { consignors = []; }
    try {
        // Tool-capable first (they can search the product database), then cheapest; unpriced last.
        const price = (m) => (m.price_in == null || m.price_out == null ? 1e9 : m.price_in + m.price_out);
        models = (await api.get('/pos-import/models')).data
            .sort((a, b) => (b.supports_tools - a.supports_tools) || (price(a) - price(b)));
    } catch (e) { models = []; }
    try {
        const saved = Number(localStorage.getItem(MODEL_KEY));
        modelId = models.some((m) => m.id === saved) ? saved : (models[0]?.id ?? null);
    } catch (e) { modelId = models[0]?.id ?? null; }
    const currentModel = () => models.find((m) => m.id === modelId) || null;
    await loadRecent();

    // ---------- row helpers ----------

    function toRow(l) {
        const r = { ...l, decision: null, confirmed: false, newProduct: null, qtyGuessed: false };
        if (l.match) {
            r.decision = { action: l.match.action, product: l.match.product, origin: l.match.by };
            r.confirmed = l.status === 'saved';
            r.savedDecision = l.status === 'saved' ? { ...r.decision } : null;
        }
        guessQty(r);
        return r;
    }

    /** POS printed "-" for qty: estimate it from the paired product's price. */
    function guessQty(r) {
        if (r.qty || !r.decision || r.decision.action !== 'product' || !r.decision.product?.sale_price) return;
        const q = Math.round(r.net / r.decision.product.sale_price);
        if (q > 0) { r.qty = q; r.qtyGuessed = true; perUnit(r); }
    }

    function perUnit(r) {
        r.unit_price = r.qty ? Math.round((r.net / r.qty) * 100) / 100 : null;
        r.unit_cost = r.qty && r.cost !== null ? Math.round((r.cost / r.qty) * 100) / 100 : null;
    }

    /** green = same as the pair saved earlier; yellow = any new pair. */
    function colorOf(r) {
        if (!r.decision) return '';
        return r.decision.origin === 'saved' ? 'pos-saved' : 'pos-fresh';
    }

    const isSkip = (r) => r.decision?.action === 'skip';
    const resolved = (r) => r.decision && r.confirmed && (isSkip(r) || r.qty > 0);
    const shortStock = (r) => r.decision?.action === 'product' && r.qty > (r.decision.product?.qty_stock ?? 0);

    function setDecision(i, decision, newProduct = null) {
        const r = rows[i];
        // Choosing again what was saved before keeps the row green.
        const s = r.savedDecision;
        if (s && s.action === decision.action && (decision.action === 'skip' || s.product?.id === decision.product?.id)) {
            decision = { ...s };
        }
        r.decision = decision;
        r.newProduct = newProduct;
        r.confirmed = true;
        if (r.qtyGuessed) { r.qty = null; r.qtyGuessed = false; perUnit(r); }
        guessQty(r);
    }

    function stats() {
        const s = { saved: 0, fresh: 0, unconfirmed: 0, none: 0, skip: 0, total: 0, short: 0, missingQty: 0 };
        rows.forEach((r) => {
            if (!r.decision) s.none++;
            else if (r.decision.origin === 'saved') s.saved++;
            else s.fresh++;
            if (r.decision && !r.confirmed) s.unconfirmed++;
            if (isSkip(r)) s.skip++;
            else s.total += Number(r.net) || 0;
            if (shortStock(r)) s.short++;
            if (r.decision && !isSkip(r) && !(r.qty > 0)) s.missingQty++;
        });
        return s;
    }

    // ---------- drawing ----------

    function draw() {
        container.innerHTML = `
        <div class="tabs" style="margin-bottom:16px">
          <button type="button" class="tab-btn ${tab === 'import' ? 'active' : ''}" data-tab="import">นำเข้ายอดขาย</button>
          <button type="button" class="tab-btn ${tab === 'mappings' ? 'active' : ''}" data-tab="mappings">คู่สินค้าที่จับไว้</button>
        </div>
        ${success ? `<div class="pill ok" style="font-size:13px;padding:8px 14px;margin-bottom:14px">${escapeHtml(success)}</div>` : ''}
        <div id="pos-body"></div>
        <div id="pos-modal"></div>`;
        container.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', async () => {
            tab = b.dataset.tab;
            success = null;
            if (tab === 'mappings') await loadMappings();
            draw();
        }));
        const body = container.querySelector('#pos-body');
        if (tab === 'mappings') drawMappings(body);
        else if (step === 'input') drawInput(body);
        else drawReview(body);
        drawModal();
    }

    function drawInput(body) {
        body.innerHTML = `
        <div class="two-col">
          <section class="card card-pad" style="display:flex;flex-direction:column;gap:14px">
            <div>
              <h2 style="margin-bottom:4px">รายงานยอดขายจาก POS</h2>
              <p class="muted" style="margin:0;font-size:13px">วางตารางรายงานยอดขายแยกตามสินค้า (Markdown, หรือคัดลอกจาก Excel) หรือเลือกไฟล์ .md / .csv / .txt
              ระบบจะใช้คู่สินค้าที่เคยจับไว้ก่อน (ตาม SKU ถ้ารายงานมี ไม่งั้นตามชื่อ) แล้วให้ผู้ช่วย AI แนะนำคู่ของรายการที่เหลือจากชื่อที่คล้ายกันและราคาทุนที่เท่ากัน</p>
            </div>
            ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
              <label class="field" style="flex:1;min-width:200px">ไฟล์รายงาน
                <input type="file" id="pos-file" accept=".md,.markdown,.txt,.csv,.tsv,text/plain,text/markdown,text/csv">
              </label>
              <label class="field">วันที่ขาย
                <input type="date" id="pos-date" value="${escapeHtml(saleDate)}" max="${todayISO()}">
              </label>
            </div>
            <label class="field">หรือวางรายงานที่นี่
              <textarea id="pos-text" rows="14" style="font-family:ui-monospace,monospace;font-size:12.5px" placeholder="| # | สินค้า | SKU | สินค้าที่ขาย | ยอดขายสุทธิ (฿) |&#10;| 1 | 7up | 10386 | 4 | 52.00 |&#10;&#10;หรือรูปแบบเดิม&#10;| รายการสินค้า | หมวดหมู่ | จำนวนที่ขาย | ยอดขายสุทธิ (บาท) | ต้นทุนสินค้า (บาท) |">${escapeHtml(text)}</textarea>
            </label>
            <p class="muted" style="margin:-6px 0 0;font-size:12px">ต้องมีคอลัมน์ชื่อสินค้าและยอดขาย ส่วน SKU จำนวน ต้นทุน และหมวดหมู่ช่วยให้จับคู่ได้แม่นขึ้น (SKU ใช้เป็นรหัสอ้างอิงในการนำเข้าครั้งถัดไป แม้ชื่อใน POS จะเปลี่ยน) — ถ้ารายงานระบุวันที่ ระบบจะใช้วันที่นั้นให้อัตโนมัติ (ถ้ายังไม่ได้เลือกเอง)</p>
            <div><button type="button" class="btn" id="pos-analyze" ${busy ? 'disabled' : ''}>${busy === 'analyze' ? 'กำลังอ่านรายงานและจับคู่สินค้า…' : 'อ่านรายงานและจับคู่สินค้า'}</button></div>
          </section>
          <section class="card">
            <div class="card-head"><h2>นำเข้าล่าสุด</h2></div>
            <div class="table-wrap"><table>
              <thead><tr><th>วันที่ขาย</th><th>เลขที่</th><th class="num">รายการ</th><th class="num">ยอดขาย</th></tr></thead>
              <tbody>
                ${recent.map((r) => `
                  <tr data-open="${r.id}" class="${r.status === 'cancelled' ? 'pc-cancelled' : ''}" style="cursor:pointer" title="ดูรายการที่นำเข้า">
                    <td>${escapeHtml(fmtDate(r.sale_date))}${r.status === 'cancelled' ? ` <span class="pill bad" title="${escapeHtml(r.cancel_reason || '')}">ยกเลิกแล้ว</span>` : ''}<div class="pos-sub">${escapeHtml(r.user_name || '')} · ${escapeHtml(fmtDateTime(r.created_at))}</div></td>
                    <td>${escapeHtml(r.doc_no || '-')}${r.new_products > 0 ? `<div class="pos-sub">สินค้าใหม่ ${count(r.new_products)}</div>` : ''}</td>
                    <td class="num">${count(r.line_count)}${r.skipped_count > 0 ? `<div class="pos-sub">ข้าม ${count(r.skipped_count)}</div>` : ''}</td>
                    <td class="num"><span class="${r.status === 'cancelled' ? 'pc-total' : ''}">${money(r.total_value)}</span></td>
                  </tr>`).join('') || `<tr><td colspan="4" class="empty">ยังไม่เคยนำเข้า</td></tr>`}
              </tbody>
            </table></div>
          </section>
        </div>`;

        body.querySelector('#pos-text').addEventListener('input', (e) => { text = e.target.value; });
        body.querySelector('#pos-date').addEventListener('change', (e) => { saleDate = e.target.value; dateTouched = true; });
        body.querySelector('#pos-file').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 1024 * 1024) { error = 'ไฟล์ใหญ่เกิน 1 MB'; draw(); return; }
            text = await file.text();
            sourceLabel = file.name;
            error = null;
            draw();
        });
        body.querySelector('#pos-analyze').addEventListener('click', analyze);
        body.querySelectorAll('[data-open]').forEach((tr) => tr.addEventListener('click', () => openImport(Number(tr.dataset.open))));
    }

    function drawReview(body) {
        const s = stats();
        const canSave = rows.length && rows.every(resolved) && (!s.short || allowShort) && rows.some((r) => !isSkip(r));
        const visible = rows.map((r, i) => [r, i]).filter(([r]) => {
            if (filter === 'todo') return !resolved(r);
            if (filter === 'saved') return r.decision?.origin === 'saved';
            if (filter === 'fresh') return r.decision && r.decision.origin !== 'saved';
            if (filter === 'none') return !r.decision;
            return true;
        });
        const todo = rows.filter((r) => !resolved(r)).length;

        body.innerHTML = `
        <section class="card card-pad" style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px">
          <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
              <label class="field">วันที่ขาย <input type="date" id="pos-date" value="${escapeHtml(saleDate)}" max="${todayISO()}"></label>
              <div style="font-size:13px">
                <div><b>${count(rows.length)}</b> รายการ${sourceLabel ? ` จาก ${escapeHtml(sourceLabel)}` : ''}</div>
                <div>ยอดขายที่จะนำเข้า <b>฿ ${money(s.total)}</b></div>
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button type="button" class="btn secondary" id="pos-back">← กลับไปแก้รายงาน</button>
              ${s.unconfirmed ? `<button type="button" class="btn secondary" id="pos-accept-all">✓ ยืนยันคำแนะนำทั้งหมด (${count(s.unconfirmed)})</button>` : ''}
              <button type="button" class="btn" id="pos-save" ${canSave && !busy && !aiRun.running ? '' : 'disabled'}>${busy === 'save' ? 'กำลังบันทึก…' : 'บันทึกยอดขายและจำคู่สินค้า'}</button>
            </div>
          </div>
          ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
          ${previous.length ? `<div class="pc-warn">วันที่ ${escapeHtml(fmtDate(saleDate))} เคยนำเข้าแล้ว ${previous.length} ครั้ง: ${previous.map((p) =>
                `${escapeHtml(p.doc_no || '-')} (฿ ${money(p.total_value)} โดย ${escapeHtml(p.user_name || '-')})`).join(', ')} — ตรวจให้แน่ใจว่าไม่ได้นำเข้าซ้ำ</div>` : ''}
          ${aiStatusHtml()}
          ${warnings.map((w) => `<div class="pc-warn">${escapeHtml(w)}</div>`).join('')}
          <div class="pos-legend">
            <span><i class="saved"></i>เขียว = คู่ที่เคยจับคู่ไว้แล้ว (ใช้คู่เดิม)</span>
            <span><i class="fresh"></i>เหลือง = คู่ใหม่ ${models.length ? '(ผู้ช่วย AI หรือระบบแนะนำ)' : ''} — ตรวจแล้วกด «ยืนยัน» หรือ «เปลี่ยน»</span>
            <span><i></i>ไม่มีสี = ยังไม่ได้จับคู่</span>
          </div>
          <div class="tabs">
            ${[['all', `ทั้งหมด ${rows.length}`], ['todo', `ต้องตรวจ ${todo}`], ['saved', `เคยจับคู่ ${s.saved}`], ['fresh', `คู่ใหม่ ${s.fresh}`], ['none', `ยังไม่จับคู่ ${s.none}`]]
                .map(([k, l]) => `<button type="button" class="tab-btn ${filter === k ? 'active' : ''}" data-filter="${k}">${l}</button>`).join('')}
          </div>
          ${s.missingQty ? `<div class="pc-warn">มี ${s.missingQty} รายการที่ไม่มีจำนวนขาย กรุณากรอกจำนวน (ช่องขอบแดง)</div>` : ''}
          ${s.short ? `<label style="display:flex;gap:8px;align-items:center;font-size:13px" class="pc-warn">
              <input type="checkbox" id="pos-short" ${allowShort ? 'checked' : ''}>
              ${s.short} รายการขายมากกว่าสต๊อกในระบบ — บันทึกต่อได้ (สต๊อกในระบบจะติดลบ แล้วค่อยปรับจากการตรวจนับ)</label>` : ''}
        </section>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr>
              <th>#</th><th>รายการจาก POS</th><th class="num">จำนวน</th><th class="num">ยอดขาย</th><th class="num">ทุน/ชิ้น</th>
              <th>สินค้าในระบบ</th><th style="text-align:right">จัดการ</th>
            </tr></thead>
            <tbody>
              ${visible.map(([r, i]) => rowHtml(r, i)).join('') || `<tr><td colspan="7" class="empty">ไม่มีรายการในกลุ่มนี้</td></tr>`}
            </tbody>
          </table></div>
        </section>`;

        body.querySelector('#pos-date').addEventListener('change', async (e) => {
            saleDate = e.target.value;
            dateTouched = true;
            try { previous = (await api.get(`/pos-import/previous?date=${encodeURIComponent(saleDate)}`)).data; } catch (err) { previous = []; }
            draw();
        });
        body.querySelector('#pos-back').addEventListener('click', () => { aiRun.stopped = true; step = 'input'; error = null; draw(); });
        body.querySelector('#pos-accept-all')?.addEventListener('click', () => {
            rows.forEach((r) => { if (r.decision) r.confirmed = true; });
            draw();
        });
        body.querySelector('#pos-save').addEventListener('click', save);
        body.querySelector('#ai-stop')?.addEventListener('click', () => { aiRun.stopped = true; draw(); });
        body.querySelector('#ai-run')?.addEventListener('click', () => startAi());
        body.querySelector('#ai-retry')?.addEventListener('click', () => runAi());
        body.querySelector('#ai-model')?.addEventListener('change', (e) => {
            modelId = Number(e.target.value);
            try { localStorage.setItem(MODEL_KEY, String(modelId)); } catch (err) { /* private mode */ }
            draw();
        });
        body.querySelector('#pos-short')?.addEventListener('change', (e) => { allowShort = e.target.checked; draw(); });
        body.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => { filter = b.dataset.filter; draw(); }));
        body.querySelectorAll('[data-confirm]').forEach((b) => b.addEventListener('click', () => {
            rows[Number(b.dataset.confirm)].confirmed = true;
            draw();
        }));
        body.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
            editing = Number(b.dataset.edit);
            modalTab = rows[editing].decision?.action === 'new' ? 'new' : 'pick';
            draw();
        }));
        body.querySelectorAll('[data-qty]').forEach((inp) => inp.addEventListener('change', (e) => {
            const r = rows[Number(e.target.dataset.qty)];
            const v = Math.floor(Number(e.target.value));
            r.qty = v > 0 ? v : null;
            r.qtyGuessed = false;
            perUnit(r);
            draw();
        }));
    }

    function matchHtml(r) {
        const d = r.decision;
        if (!d) {
            return `<span class="pill bad">ยังไม่จับคู่</span>${r.ai_note ? `<div class="pos-sub">AI: ${escapeHtml(r.ai_note)}</div>` : ''}`;
        }
        const badge = {
            saved: '<span class="pill ok">เคยจับคู่</span>',
            ai: `<span class="pill warn">AI แนะนำ${r.match?.confidence != null && d.origin === r.match?.by ? ` ${Math.round(r.match.confidence * 100)}%` : ''}</span>`,
            rule: '<span class="pill warn">แนะนำจากชื่อ/ราคา</span>',
            manual: '<span class="pill warn">เลือกเอง</span>',
            new: '<span class="pill warn">สินค้าใหม่</span>',
        }[d.origin] || '';
        const own = r.match && d.origin === r.match.by;
        const reason = (own && r.match.reason ? `<div class="pos-reason">${d.origin === 'ai' ? 'AI: ' : ''}${escapeHtml(r.match.reason)}</div>` : '')
            + (own && r.match.check ? `<div class="pos-sub">${escapeHtml(r.match.check)}</div>` : '');
        if (d.action === 'skip') {
            return `${badge} <span class="muted">ไม่นำเข้ารายการนี้</span>${reason}`;
        }
        if (d.action === 'new') {
            const np = r.newProduct || {};
            const owner = consignors.find((c) => c.id === Number(np.consignor_id))?.name || OWNER_STORE;
            return `${badge} <span class="pos-prod">${escapeHtml(np.name || r.pos_name)}</span>
              <div class="pos-sub">${escapeHtml(owner)} · ทุน ${money(np.cost_price)} · ขาย ${money(np.sale_price)} · สร้างใหม่เมื่อบันทึก</div>`;
        }
        const p = d.product;
        const priceDiff = r.unit_price != null && Math.abs(r.unit_price - p.sale_price) >= 0.01;
        return `${badge} <span class="pos-prod">${escapeHtml(p.name)}</span>
          <div class="pos-sub">${escapeHtml(p.consignor_name || OWNER_STORE)} · ทุน ${money(p.cost_price)} · ขาย ${money(p.sale_price)}
            · คงเหลือ <span style="${shortStock(r) ? 'color:var(--bad);font-weight:700' : ''}">${count(p.qty_stock)}</span>
            ${priceDiff ? ` · <span style="color:var(--warn)">ราคาใน POS ${money(r.unit_price)}</span>` : ''}</div>${reason}`;
    }

    function rowHtml(r, i) {
        const needsQty = r.decision && !isSkip(r) && !(r.qty > 0);
        const costDiff = r.decision?.action === 'product' && r.unit_cost != null && Math.abs(r.unit_cost - r.decision.product.cost_price) >= 0.01;
        return `
        <tr class="${colorOf(r)} ${isSkip(r) ? 'pos-skip' : ''}">
          <td class="muted">${i + 1}</td>
          <td><div class="pos-prod">${escapeHtml(r.pos_name)}</div>${r.pos_sku || r.pos_category
              ? `<div class="pos-sub">${[r.pos_sku ? `SKU ${escapeHtml(r.pos_sku)}` : '', escapeHtml(r.pos_category || '')].filter(Boolean).join(' · ')}</div>` : ''}</td>
          <td class="num">
            <input type="number" min="1" step="1" class="pos-qty ${needsQty ? 'missing' : ''}" data-qty="${i}" value="${r.qty ?? ''}" ${isSkip(r) ? 'disabled' : ''}>
            ${r.qtyGuessed ? '<div class="pos-sub">คำนวณจากราคาขาย</div>' : ''}
          </td>
          <td class="num">${money(r.net)}</td>
          <td class="num">${r.unit_cost != null ? money(r.unit_cost) : '-'}${costDiff ? `<div class="pos-sub" style="color:var(--warn)">ระบบ ${money(r.decision.product.cost_price)}</div>` : ''}</td>
          <td>${matchHtml(r)}</td>
          <td style="text-align:right;white-space:nowrap">
            ${r.decision && !r.confirmed ? `<button type="button" class="btn sm" data-confirm="${i}">✓ ยืนยัน</button>` : ''}
            ${r.decision && r.confirmed ? '<span class="pill ok" style="margin-right:4px">✓</span>' : ''}
            <button type="button" class="btn secondary sm" data-edit="${i}">${r.decision ? 'เปลี่ยน' : 'จับคู่'}</button>
          </td>
        </tr>`;
    }

    // ---------- pairing modal ----------

    /** "003 ขายฝาก ครูออย" → the consignor whose name contains "ครูออย". */
    function guessOwner(category) {
        const owner = String(category || '').replace(/^[\d\s.\-]*|ขายฝาก|ฝากขาย|\s+/g, '');
        if (owner.length < 2) return '';
        const hit = consignors.find((x) => {
            const n = String(x.name || '').replace(/\s+/g, '');
            return n && (n.includes(owner) || owner.includes(n));
        });
        return hit ? String(hit.id) : '';
    }

    function candHtml(c, r, extra = '') {
        const p = c.product || c;
        const current = r.decision?.action === 'product' && r.decision.product?.id === p.id;
        const tags = [];
        if (c.name_score != null) tags.push(`<span class="pill muted">ชื่อคล้าย ${Math.round(c.name_score * 100)}%</span>`);
        if (c.cost_match) tags.push('<span class="pill ok">ทุนเท่ากัน</span>');
        if (c.price_match) tags.push('<span class="pill ok">ราคาเท่ากัน</span>');
        if (c.owner_match) tags.push('<span class="pill ok">ผู้ฝากตรงหมวด</span>');
        return `
        <button type="button" class="pos-cand ${current ? 'current' : ''}" data-pick="${p.id}" ${extra}>
          <span>
            <span class="pos-prod">${escapeHtml(p.name)}</span>
            <div class="pos-sub">${escapeHtml(p.consignor_name || OWNER_STORE)}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''} · คงเหลือ ${count(p.qty_stock)}</div>
            ${tags.length ? `<div class="pos-tags">${tags.join('')}</div>` : ''}
          </span>
          <span class="num" style="font-size:12.5px;white-space:nowrap">ทุน ${money(p.cost_price)}<br>ขาย ${money(p.sale_price)}</span>
        </button>`;
    }

    async function openImport(id) {
        repairing = null;
        viewing = { id, loading: true };
        drawModal();
        try {
            viewing = (await api.get(`/pos-import/${id}`)).data;
        } catch (e) {
            viewing = { id, error: e.message };
        }
        drawModal();
    }

    async function cancelImport(v) {
        const reason = await promptDialog(`ยอดขายนี้จะถูกลบออก สินค้าคืนเข้าสต๊อก ${count(v.total_qty)} ชิ้น รายการในสมุดบัญชีผู้ฝากของการขายนี้ถูกถอนออก
และสินค้าที่สร้างใหม่ตอนนำเข้า (ถ้ายังไม่ถูกใช้ที่อื่น) จะถูกลบ — คู่สินค้าที่จับไว้ยังเก็บไว้ใช้ต่อ (ยกเลิกแล้วกู้คืนไม่ได้)
กรุณาระบุเหตุผลที่ยกเลิก:`, {
            title: `ยกเลิกการนำเข้ายอดขายวันที่ ${fmtDate(v.sale_date)}${v.doc_no ? ` (${v.doc_no})` : ''}?`,
            okText: 'ยกเลิกการนำเข้า', cancelText: 'ไม่ยกเลิก', placeholder: 'เหตุผลที่ยกเลิก', required: 'กรุณาระบุเหตุผลที่ยกเลิก', multiline: true, danger: true,
        });
        if (reason === null) return;
        try {
            const res = (await api.post(`/pos-import/${v.id}/cancel`, { reason: reason.trim() })).data;
            success = `ยกเลิกการนำเข้า${res.doc_no ? ` ${res.doc_no}` : ''} แล้ว — คืนสินค้าเข้าสต๊อก ${count(res.units)} ชิ้น`
                + (res.removed_products ? ` · ลบสินค้าที่สร้างตอนนำเข้า ${count(res.removed_products)} รายการ` : '');
            viewing = null;
            repairing = null;
            await loadRecent();
            draw();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
    }

    function importHtml() {
        const v = viewing;
        const items = v.items || [];
        const fee = items.reduce((s, it) => s + Number(it.fee_amount || 0), 0);
        const cancelled = v.status === 'cancelled';
        const canRepair = canCancel && !cancelled && !v.loading && !v.error && items.length > 0;
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:960px">
            <div class="modal-head">
              <h2>${v.loading ? 'กำลังโหลด…' : `นำเข้ายอดขายวันที่ ${escapeHtml(fmtDate(v.sale_date || ''))}${v.doc_no ? ` · ${escapeHtml(v.doc_no)}` : ''}`}</h2>
              <button type="button" class="modal-close" data-close-view title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              ${v.error ? `<div class="error-box">${escapeHtml(v.error)}</div>` : ''}
              ${v.loading && !v.error ? skBlock(6, 8) : ''}
              ${v.loading || v.error ? '' : `
                <div class="muted" style="font-size:13px;margin-bottom:12px">
                  นำเข้าโดย ${escapeHtml(v.user_name || '-')} เมื่อ ${escapeHtml(fmtDateTime(v.created_at))}${v.source_label ? ` · ไฟล์ ${escapeHtml(v.source_label)}` : ''}
                  · ${count(v.line_count)} รายการ${Number(v.skipped_count) ? ` · ไม่นำเข้า ${count(v.skipped_count)} รายการ` : ''}${Number(v.new_products) ? ` · สร้างสินค้าใหม่ ${count(v.new_products)}` : ''}
                </div>
                ${cancelled ? `<div class="error-box" style="margin-bottom:12px">
                  <b>การนำเข้านี้ถูกยกเลิกแล้ว</b> เมื่อ ${escapeHtml(fmtDateTime(v.cancelled_at))}${v.cancelled_by_name ? ` โดย ${escapeHtml(v.cancelled_by_name)}` : ''}
                  <div>เหตุผล: ${escapeHtml(v.cancel_reason || '-')} — รายการด้านล่างเป็นข้อมูล ณ ตอนยกเลิก ไม่นับรวมในยอดขายและสต๊อกแล้ว</div></div>` : ''}
                ${repairing ? repairHtml() : ''}
                <div class="table-wrap"><table>
                  <thead><tr><th>#</th><th>สินค้าในระบบ</th><th>ชื่อใน POS / SKU</th><th>เจ้าของ</th><th class="num">จำนวน</th><th class="num">ราคา/ชิ้น</th><th class="num">รวม</th><th class="num">ค่าฝาก/กำไร</th>${canRepair ? '<th></th>' : ''}</tr></thead>
                  <tbody>${items.map((it, i) => `
                    <tr class="${repairing?.item.id === it.id ? 'pos-repair-row' : ''}">
                      <td class="muted">${i + 1}</td>
                      <td><span class="pos-prod">${escapeHtml(it.product_name)}</span>${Number(it.is_new) ? ' <span class="pill warn">สร้างใหม่ตอนนำเข้า</span>' : ''}</td>
                      <td class="muted">${escapeHtml(it.pos_names || '-')}${it.pos_skus ? `<div class="pos-sub">SKU ${escapeHtml(it.pos_skus)}</div>` : ''}</td>
                      <td class="muted">${escapeHtml(it.owner)}</td>
                      <td class="num">${count(it.qty)}</td>
                      <td class="num">${money(it.unit_price)}</td>
                      <td class="num">${money(it.line_total)}</td>
                      <td class="num">${money(it.fee_amount)}</td>
                      ${canRepair ? `<td style="white-space:nowrap"><button type="button" class="btn secondary sm" data-repair="${i}" title="จับคู่รายการนี้กับสินค้าอื่น" ${repairing ? 'disabled' : ''}>แก้คู่</button></td>` : ''}
                    </tr>`).join('') || '<tr><td colspan="9" class="empty">ไม่มีรายการขาย (อาจถูกลบไปแล้ว)</td></tr>'}
                  </tbody>
                  <tfoot><tr style="font-weight:700"><td colspan="4">รวม</td><td class="num">${count(v.total_qty)}</td><td></td>
                    <td class="num">${money(v.total_value)}</td><td class="num">${money(fee)}</td>${canRepair ? '<td></td>' : ''}</tr></tfoot>
                </table></div>
                <p class="muted" style="font-size:12px;margin:10px 0 0">ค่าฝาก/กำไร: สินค้าฝากขาย = ค่าฝากที่ร้านได้ · สินค้าของร้าน = กำไรขั้นต้น ·
                  ชื่อใน POS มาจากคู่สินค้าที่จับไว้ (แก้ได้ที่แท็บ คู่สินค้าที่จับไว้)</p>`}
            </div>
            <div class="modal-foot">${canCancel && !cancelled && !v.loading && !v.error
                ? '<button type="button" class="btn secondary pc-cancel-btn" data-cancel-import>ยกเลิกการนำเข้านี้</button>' : '<span></span>'}
              <button type="button" class="btn secondary" data-close-view>ปิด</button></div>
          </div>
        </div>`;
    }

    /** Admin panel: move one line of a saved import to the right product. */
    function repairHtml() {
        const r = repairing;
        const it = r.item;
        return `
                <div class="pos-repair" id="pos-repair">
                  <div style="font-weight:700;margin-bottom:4px">แก้การจับคู่รายการที่ ${r.no}: ${escapeHtml(it.product_name)}</div>
                  <div class="muted" style="font-size:12.5px;margin-bottom:10px">ชื่อใน POS: ${escapeHtml(it.pos_names || '-')} · ${count(it.qty)} ชิ้น × ${money(it.unit_price)} = ${money(it.line_total)} · ${escapeHtml(it.owner)}</div>
                  ${r.product ? `
                    <div class="pos-repair-pick">
                      <div style="flex:1"><div class="muted" style="font-size:12px">เปลี่ยนเป็นสินค้า</div>
                        <span class="pos-prod">${escapeHtml(r.product.name)}</span>
                        <div class="pos-sub">${escapeHtml(r.product.consignor_name || OWNER_STORE)}${r.product.barcode ? ` · ${escapeHtml(r.product.barcode)}` : ''} · คงเหลือ ${count(r.product.qty_stock)}</div></div>
                      <button type="button" class="btn secondary sm" data-repair-change>เลือกใหม่</button>
                    </div>` : `
                    <div class="search-box">🔍 <input id="repair-q" placeholder="ค้นหาสินค้าที่ถูกต้อง (ชื่อหรือบาร์โค้ด)" value="${escapeHtml(r.q)}"></div>
                    <div class="pos-repair-results">${(r.results || []).filter((p) => Number(p.id) !== Number(it.product_id)).map((p) => `
                      <button type="button" class="pos-cand" data-repair-pick="${p.id}">
                        <span style="flex:1;text-align:left"><span class="pos-prod">${escapeHtml(p.name)}</span>
                          <div class="pos-sub">${escapeHtml(p.consignor_name || OWNER_STORE)}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''} · คงเหลือ ${count(p.qty_stock)}</div></span>
                        <span class="num" style="font-size:12.5px;white-space:nowrap">ขาย ${money(p.sale_price)}</span>
                      </button>`).join('') || (r.q ? '<div class="muted" style="font-size:13px">ไม่พบสินค้า</div>' : '')}</div>`}
                  ${r.pairs?.length ? `
                    <div style="margin-top:12px;font-size:13px">
                      <div style="font-weight:600;margin-bottom:4px">คู่ที่จับไว้ของ «${escapeHtml(it.product_name)}» — เลือกชื่อใน POS ที่ต้องย้ายไปสินค้าที่ถูกต้อง เพื่อให้นำเข้าครั้งต่อไปจับคู่ถูก</div>
                      ${r.pairs.map((m) => `<label style="display:flex;gap:8px;align-items:center;padding:3px 0;cursor:pointer">
                        <input type="checkbox" data-repair-map="${m.id}" ${r.mapIds.has(Number(m.id)) ? 'checked' : ''}>
                        <span>${escapeHtml(m.pos_name)}${m.pos_sku ? ` <span class="muted">· SKU ${escapeHtml(m.pos_sku)}</span>` : ''} <span class="muted">· ใช้แล้ว ${count(m.times_used)} ครั้ง</span></span></label>`).join('')}
                    </div>` : ''}
                  <div class="muted" style="font-size:12px;margin-top:10px">เมื่อบันทึก: «${escapeHtml(it.product_name)}» ได้สต๊อกคืน ${count(it.qty)} ชิ้น และสินค้าที่เลือกถูกตัดสต๊อกแทน (ราคาขายเท่าเดิม)
                    — ถ้าเป็นสินค้าฝากขาย ระบบลงรายการปรับยอดในสมุดบัญชีผู้ฝากให้ · ${Number(it.is_new) ? 'สินค้าที่สร้างตอนนำเข้านี้ ถ้าไม่ถูกใช้ที่อื่นจะถูกลบ' : ''}</div>
                  ${r.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(r.error)}</div>` : ''}
                  <div class="actions-row" style="margin-top:12px;justify-content:flex-end">
                    <button type="button" class="btn secondary" data-repair-cancel>ยกเลิก</button>
                    <button type="button" class="btn" data-repair-save ${r.product && !r.busy ? '' : 'disabled'}>${r.busy ? 'กำลังบันทึก…' : 'บันทึกการแก้คู่'}</button>
                  </div>
                </div>`;
    }

    async function startRepair(i) {
        const it = viewing.items[i];
        repairing = { item: it, no: i + 1, q: '', results: [], product: null, pairs: [], mapIds: new Set(), busy: false, error: null };
        drawModal();
        host().querySelector('#pos-repair')?.scrollIntoView({ block: 'nearest' });
        host().querySelector('#repair-q')?.focus();
        try {
            const pairs = (await api.get(`/pos-import/${viewing.id}/items/${it.id}/pairs`)).data;
            if (repairing?.item !== it) return;
            repairing.pairs = pairs;
            // A product with a single POS name: that name is the wrong pair.
            if (pairs.length === 1) repairing.mapIds.add(Number(pairs[0].id));
            const q = host().querySelector('#repair-q')?.value || '';
            drawModal();
            const input = host().querySelector('#repair-q');
            if (input) { input.focus(); input.setSelectionRange(q.length, q.length); }
        } catch (e) { /* pairs are optional */ }
    }

    async function saveRepair() {
        const r = repairing;
        const moved = r.pairs.filter((m) => r.mapIds.has(Number(m.id))).length;
        if (!(await confirmDialog(`รายการที่ ${r.no} «${r.item.product_name}» ${count(r.item.qty)} ชิ้น จะเปลี่ยนเป็น «${r.product.name}»`
            + (moved ? ` และย้ายคู่ที่จับไว้ ${moved} ชื่อไปที่สินค้านี้` : ''), { title: 'บันทึกการแก้คู่?', okText: 'บันทึก' }))) return;
        r.busy = true;
        r.error = null;
        drawModal();
        try {
            const res = await api.post(`/pos-import/${viewing.id}/items/${r.item.id}/repair`, { product_id: r.product.id, mapping_ids: [...r.mapIds] });
            viewing = res.import;
            repairing = null;
            success = `แก้คู่แล้ว: «${res.data.old_product || '-'}» → «${res.data.product}» ${count(res.data.qty)} ชิ้น`
                + (res.data.moved_pairs ? ` · ย้ายคู่ที่จับไว้ ${count(res.data.moved_pairs)} ชื่อ` : '')
                + (res.data.removed_product ? ' · ลบสินค้าที่สร้างตอนนำเข้าซึ่งไม่ถูกใช้แล้ว' : '');
            await loadRecent();
            draw();
        } catch (e) {
            r.busy = false;
            r.error = e.message;
            drawModal();
        }
    }

    function bindRepair(h) {
        h.querySelectorAll('[data-repair]').forEach((b) => b.addEventListener('click', () => startRepair(Number(b.dataset.repair))));
        if (!repairing) return;
        const r = repairing;
        h.querySelector('[data-repair-cancel]')?.addEventListener('click', () => { repairing = null; drawModal(); });
        h.querySelector('[data-repair-change]')?.addEventListener('click', () => { r.product = null; drawModal(); h.querySelector('#repair-q')?.focus(); });
        h.querySelector('[data-repair-save]')?.addEventListener('click', saveRepair);
        h.querySelectorAll('[data-repair-map]').forEach((c) => c.addEventListener('change', () => {
            if (c.checked) r.mapIds.add(Number(c.dataset.repairMap)); else r.mapIds.delete(Number(c.dataset.repairMap));
        }));
        h.querySelectorAll('[data-repair-pick]').forEach((b) => b.addEventListener('click', () => {
            r.product = r.results.find((p) => Number(p.id) === Number(b.dataset.repairPick));
            drawModal();
        }));
        const input = h.querySelector('#repair-q');
        let timer = null;
        input?.addEventListener('input', () => {
            r.q = input.value;
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const q = r.q.trim();
                try {
                    r.results = q ? (await api.get(`/pos-import/products?q=${encodeURIComponent(q)}`)).data : [];
                } catch (e) { r.error = e.message; }
                if (repairing !== r || r.product) return;
                drawModal();
                const again = host().querySelector('#repair-q');
                if (again) { again.focus(); again.setSelectionRange(again.value.length, again.value.length); }
            }, 250);
        });
    }

    function host() {
        return container.querySelector('#pos-modal');
    }

    function drawModal() {
        const host = container.querySelector('#pos-modal');
        if (!host) return;
        if (viewing && tab === 'import' && step === 'input') {
            host.innerHTML = importHtml();
            host.querySelectorAll('[data-close-view]').forEach((b) => b.addEventListener('click', () => { viewing = null; repairing = null; drawModal(); }));
            host.querySelector('[data-cancel-import]')?.addEventListener('click', () => cancelImport(viewing));
            bindRepair(host);
            return;
        }
        if (editing === null || tab !== 'import' || step !== 'review') { host.innerHTML = ''; return; }
        const r = rows[editing];
        const np = r.newProduct || {
            name: r.pos_name,
            consignor_id: guessOwner(r.pos_category),
            cost_price: r.unit_cost ?? '',
            sale_price: r.unit_price ?? '',
        };
        host.innerHTML = `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-head">
              <h2>จับคู่: ${escapeHtml(r.pos_name)}</h2>
              <button type="button" class="modal-close" data-close title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:12px">
              <div class="muted" style="font-size:13px">
                จาก POS: ${r.pos_sku ? `SKU ${escapeHtml(r.pos_sku)} · ` : ''}${r.pos_category ? `หมวด ${escapeHtml(r.pos_category)} · ` : ''}จำนวน ${r.qty ?? '-'} · ยอดขาย ${money(r.net)}
                · ราคา/ชิ้น ${r.unit_price != null ? money(r.unit_price) : '-'} · ทุน/ชิ้น ${r.unit_cost != null ? money(r.unit_cost) : '-'}
              </div>
              <div class="tabs">
                <button type="button" class="tab-btn ${modalTab === 'pick' ? 'active' : ''}" data-mtab="pick">เลือกสินค้าในระบบ</button>
                <button type="button" class="tab-btn ${modalTab === 'new' ? 'active' : ''}" data-mtab="new">＋ เพิ่มเป็นสินค้าใหม่</button>
              </div>
              ${modalTab === 'pick' ? `
                ${r.candidates?.length ? `<div style="font-size:13px;font-weight:600">สินค้าที่ใกล้เคียง</div>
                  <div style="display:flex;flex-direction:column;gap:6px">${r.candidates.map((c) => candHtml(c, r)).join('')}</div>` : ''}
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                  <div class="search-box" style="flex:1">🔍 <input id="pos-search" placeholder="ค้นหาสินค้าอื่นจากชื่อ หรือบาร์โค้ด"></div>
                  ${currentModel() ? `<button type="button" class="btn secondary" id="pos-ai-one" ${aiRun.running ? 'disabled' : ''}>✨ ให้ผู้ช่วย AI ค้นหาให้</button>` : ''}
                </div>
                ${r.ai_note ? `<div class="pos-sub">AI: ${escapeHtml(r.ai_note)}</div>` : ''}
                <div id="pos-search-results" style="display:flex;flex-direction:column;gap:6px"></div>
              ` : `
                <p class="muted" style="margin:0;font-size:12.5px">สินค้าที่มีใน POS แต่ยังไม่มีในระบบ จะถูกสร้างเมื่อกดบันทึก โดยรับเข้าเท่าจำนวนที่ขาย (สต๊อกคงเหลือเป็น 0)
                สินค้าฝากขาย: ผู้ฝากได้รับเท่าราคาทุน ร้านได้ส่วนต่าง</p>
                <label class="field">ชื่อสินค้า <input id="np-name" value="${escapeHtml(np.name)}"></label>
                <label class="field">เจ้าของสินค้า
                  <select id="np-owner">
                    <option value="">${OWNER_STORE} (ซื้อมาขายเอง)</option>
                    ${consignors.map((c) => `<option value="${c.id}" ${String(np.consignor_id) === String(c.id) ? 'selected' : ''}>${escapeHtml(c.code)} ${escapeHtml(c.name)}</option>`).join('')}
                  </select>
                </label>
                <div style="display:flex;gap:12px">
                  <label class="field" style="flex:1">ราคาทุน/ชิ้น <input id="np-cost" type="number" min="0" step="0.01" value="${escapeHtml(np.cost_price)}"></label>
                  <label class="field" style="flex:1">ราคาขาย/ชิ้น <input id="np-price" type="number" min="0.01" step="0.01" value="${escapeHtml(np.sale_price)}"></label>
                </div>
                <div><button type="button" class="btn" id="np-use">ใช้เป็นสินค้าใหม่</button></div>
              `}
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" id="pos-skip">ไม่นำเข้ารายการนี้ (จำไว้ครั้งต่อไป)</button>
              <button type="button" class="btn secondary" data-close>ปิด</button>
            </div>
          </div>
        </div>`;

        const close = () => { editing = null; draw(); };
        host.querySelector('#pos-ai-one')?.addEventListener('click', async (e) => {
            const i = editing;
            e.target.disabled = true;
            e.target.textContent = 'ผู้ช่วย AI กำลังค้นหา…';
            try {
                const res = (await api.post('/pos-import/suggest', { lines: [aiLine(rows[i], i)], model: modelId })).data;
                rows[i].confirmed = false;
                (res.matches || []).forEach((m) => applyAi(m, true));
                if (!res.matches?.length) rows[i].ai_note = 'AI ไม่ได้ตอบรายการนี้';
            } catch (err) {
                rows[i].ai_note = 'ขัดข้อง: ' + err.message;
            }
            if (editing === i) drawModal();
        });
        host.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
        host.querySelectorAll('[data-mtab]').forEach((b) => b.addEventListener('click', () => { modalTab = b.dataset.mtab; drawModal(); }));
        host.querySelector('#pos-skip').addEventListener('click', () => {
            setDecision(editing, { action: 'skip', product: null, origin: 'manual' });
            close();
        });
        const bindPick = (scope, list) => scope.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
            const id = Number(b.dataset.pick);
            const p = list.find((x) => (x.product || x).id === id);
            if (!p) return;
            const product = p.product || p;
            // Picking the AI/rule suggestion itself keeps its origin (and its reason).
            const origin = r.match && r.match.product?.id === id && r.match.by !== 'saved' ? r.match.by : 'manual';
            setDecision(editing, { action: 'product', product, origin });
            close();
        }));
        if (modalTab === 'pick') {
            bindPick(host, r.candidates || []);
            const input = host.querySelector('#pos-search');
            const out = host.querySelector('#pos-search-results');
            let timer = null;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(async () => {
                    const q = input.value.trim();
                    if (!q) { out.innerHTML = ''; return; }
                    try {
                        const found = (await api.get(`/pos-import/products?q=${encodeURIComponent(q)}`)).data;
                        out.innerHTML = found.map((p) => candHtml(p, r)).join('') || '<div class="muted" style="font-size:13px">ไม่พบสินค้า — เพิ่มเป็นสินค้าใหม่ได้ที่แท็บด้านบน</div>';
                        bindPick(out, found);
                    } catch (e) { out.innerHTML = `<div class="error-box">${escapeHtml(e.message)}</div>`; }
                }, 250);
            });
            input.focus();
        } else {
            host.querySelector('#np-use').addEventListener('click', () => {
                const name = host.querySelector('#np-name').value.trim();
                const costText = host.querySelector('#np-cost').value.trim();
                const cost = Number(costText);
                const price = Number(host.querySelector('#np-price').value);
                // Left blank when the POS has no cost: it must not silently become 0.
                if (!name || !(price > 0) || costText === '' || !(cost >= 0)) { alertDialog('กรุณากรอกชื่อ ราคาทุน และราคาขายให้ถูกต้อง', { title: 'ข้อมูลสินค้าไม่ครบ', tone: 'error' }); return; }
                setDecision(editing, { action: 'new', product: null, origin: 'new' }, {
                    name, consignor_id: host.querySelector('#np-owner').value || null, cost_price: cost, sale_price: price,
                });
                close();
            });
        }
    }

    // ---------- saved pairs ----------

    async function loadMappings() {
        try { mappings = (await api.get(`/pos-import/mappings?q=${encodeURIComponent(mapQ)}`)).data; } catch (e) { mappings = []; error = e.message; }
    }

    function drawMappings(body) {
        const SRC = { ai: 'AI แนะนำ', rule: 'จากชื่อ/ราคา', manual: 'เลือกเอง', new: 'สร้างสินค้าใหม่' };
        body.innerHTML = `
        <section class="card">
          <div class="card-head" style="gap:12px;flex-wrap:wrap">
            <h2>คู่สินค้าที่จับไว้ (${count(mappings.length)})</h2>
            <div class="search-box" style="max-width:320px">🔍 <input id="map-q" placeholder="ชื่อใน POS, SKU หรือชื่อสินค้า" value="${escapeHtml(mapQ)}"></div>
          </div>
          <p class="muted" style="margin:12px 18px 0;font-size:12.5px">ทุกครั้งที่นำเข้า ระบบจะใช้คู่เหล่านี้ให้ทันที (แสดงเป็นสีเขียว) ลบคู่ที่ผิดออกได้ แล้วจับคู่ใหม่ในการนำเข้าครั้งถัดไป</p>
          <div class="table-wrap"><table>
            <thead><tr><th>ชื่อใน POS</th><th>สินค้าในระบบ</th><th>ที่มา</th><th class="num">ใช้แล้ว</th><th>ใช้ล่าสุด</th><th></th></tr></thead>
            <tbody>
              ${mappings.map((m) => `
                <tr class="pos-saved">
                  <td><div class="pos-prod">${escapeHtml(m.pos_name)}</div>${m.pos_sku || m.pos_category
                    ? `<div class="pos-sub">${[m.pos_sku ? `SKU ${escapeHtml(m.pos_sku)}` : '', escapeHtml(m.pos_category || '')].filter(Boolean).join(' · ')}</div>` : ''}</td>
                  <td>${m.action === 'skip' ? '<span class="muted">ไม่นำเข้า</span>' : `${escapeHtml(m.product_name || '-')}<div class="pos-sub">${escapeHtml(m.consignor_name || '')}</div>`}</td>
                  <td>${escapeHtml(SRC[m.source] || m.source)}</td>
                  <td class="num">${count(m.times_used)}</td>
                  <td>${escapeHtml(fmtDateTime(m.last_used_at || m.updated_at))}</td>
                  <td style="text-align:right"><button type="button" class="btn secondary sm" data-del="${m.id}">ลบคู่</button></td>
                </tr>`).join('') || `<tr><td colspan="6" class="empty">ยังไม่มีคู่สินค้า — จะถูกบันทึกเมื่อนำเข้ายอดขายครั้งแรก</td></tr>`}
            </tbody>
          </table></div>
        </section>`;
        let timer = null;
        const q = body.querySelector('#map-q');
        q.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                mapQ = q.value.trim();
                await loadMappings();
                draw();
                const again = container.querySelector('#map-q');
                again?.focus();
                again?.setSelectionRange(again.value.length, again.value.length);
            }, 300);
        });
        body.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
            const m = mappings.find((x) => x.id === Number(b.dataset.del));
            if (!(await confirmDialog('ครั้งต่อไประบบจะแนะนำคู่ใหม่ให้', { title: `ลบคู่ «${m?.pos_name}»?`, okText: 'ลบ', danger: true }))) return;
            try {
                await api.del(`/pos-import/mappings/${b.dataset.del}`);
                await loadMappings();
            } catch (e) { alertDialog(e.message, { tone: 'error' }); }
            draw();
        }));
    }

    // ---------- actions ----------

    function aiStatusHtml() {
        if (!models.length) {
            return '<div class="pc-warn">ยังไม่มีโมเดลผู้ช่วย AI ที่เปิดให้บทบาทของคุณใช้ (ตั้งค่าได้ที่ ตั้งค่าผู้ช่วย AI) — ตอนนี้แนะนำคู่จากชื่อและราคาทุนเท่านั้น</div>';
        }
        const m = currentModel();
        // Every open line, so the button can run again (e.g. with another model).
        const todo = rows.filter(aiCandidateRow).length;
        const pct = aiRun.total ? Math.round((aiRun.done / aiRun.total) * 100) : 0;
        const price = (x) => (x.price_in != null && x.price_out != null ? ` · $${(x.price_in + x.price_out).toFixed(2)}/1M` : '');
        const picker = `
          <label style="display:flex;gap:6px;align-items:center;font-size:13px;font-weight:600">โมเดล
            <select id="ai-model" ${aiRun.running ? 'disabled' : ''} style="border:1px solid var(--border);background:var(--surface2);color:inherit;border-radius:8px;padding:6px 8px;max-width:340px">
              ${models.map((x) => `<option value="${x.id}" ${x.id === modelId ? 'selected' : ''}>${escapeHtml(x.label)} · ${escapeHtml(x.provider_name)}${x.supports_tools ? ' · 🔎 ค้นฐานข้อมูลได้' : ''}${price(x)}</option>`).join('')}
            </select>
          </label>`;
        const note = m && !m.supports_tools
            ? '<div class="pos-sub">โมเดลนี้ไม่รองรับ tools จึงค้นฐานข้อมูลเองไม่ได้ เลือกได้เฉพาะจากสินค้าที่ใกล้เคียงที่ระบบคัดให้ (เลือกโมเดลที่มี 🔎 จะแม่นกว่า)</div>'
            : '';
        let status = '';
        if (aiRun.running) {
            status = `<div style="flex-basis:100%;font-size:13px">✨ กำลังไล่หาสินค้าในฐานข้อมูล ${count(aiRun.done)}/${count(aiRun.total)} รายการ${aiRun.searches ? ` · ค้นแล้ว ${count(aiRun.searches)} ครั้ง` : ''}
              <div style="height:6px;background:var(--surface2);border-radius:99px;margin-top:6px;overflow:hidden"><div style="height:100%;width:${pct}%;background:var(--warn)"></div></div></div>`;
        } else if (aiRun.error) {
            status = `<div style="flex-basis:100%;font-size:13px;color:var(--bad)">ผู้ช่วย AI ขัดข้อง: ${escapeHtml(aiRun.error)} (ไล่หาไปแล้ว ${count(aiRun.done)}/${count(aiRun.total)})
              ${aiTargets().length ? '<button type="button" class="btn secondary sm" id="ai-retry" style="margin-left:8px">ลองต่อ</button>' : ''}</div>`;
        } else if (aiRun.total) {
            status = `<div style="flex-basis:100%;font-size:13px">✨ ผู้ช่วย AI ไล่หาแล้ว ${count(aiRun.done)} รายการ${aiRun.searches ? ` (ค้นฐานข้อมูล ${count(aiRun.searches)} ครั้ง)` : ''} — ตรวจแถวสีเหลืองแล้วกด «ยืนยัน»</div>`;
        }
        return `
        <div class="pc-warn" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0">
          ${picker}
          ${aiRun.running
              ? `<button type="button" class="btn secondary sm" id="ai-stop" ${aiRun.stopped ? 'disabled' : ''}>${aiRun.stopped ? 'กำลังหยุด…' : '■ หยุด'}</button>`
              : `<button type="button" class="btn sm" id="ai-run" ${todo ? '' : 'disabled'}>✨ จับคู่ด้วยผู้ช่วย AI${todo ? ` (${count(todo)} รายการ)` : ''}</button>`}
          <span class="pos-sub" style="margin:0">ส่งเฉพาะรายการที่ยังไม่ได้ยืนยันและไม่ใช่คู่เดิม ทีละ ${AI_BATCH} รายการ</span>
          ${note ? `<div style="flex-basis:100%">${note}</div>` : ''}
          ${status}
        </div>`;
    }

    /**
     * Lines the AI button works on: not confirmed by the user, not a saved
     * pair, and not an exact-name match; aiDone = answered in this run.
     */
    const aiCandidateRow = (r) => !r.confirmed && r.decision?.origin !== 'saved' && (r.ai_pending || !r.decision);
    const aiTargets = () => rows.map((r, i) => [r, i]).filter(([r]) => aiCandidateRow(r) && !r.aiDone);
    const pendingAi = aiTargets;
    const aiLine = (r, i) => ({ i, pos_name: r.pos_name, pos_category: r.pos_category, qty: r.qty, net: r.net, cost: r.cost });

    /** The button: (re)asks the AI about every line still open, with the chosen model. */
    function startAi() {
        rows.forEach((r) => { if (aiCandidateRow(r)) r.aiDone = false; });
        aiRun = { running: false, done: 0, total: 0, searches: 0, stopped: false, error: null };
        runAi();
    }

    /** One AI answer onto its row, unless the user has decided that row meanwhile. */
    function applyAi(m, force = false) {
        const r = rows[m.i];
        if (!r || (r.confirmed && !force)) return;
        r.aiDone = true;
        r.ai_note = null;
        if (m.product) {
            r.match = { action: 'product', product: m.product, by: 'ai', reason: m.reason, confidence: m.confidence, check: m.check };
            r.decision = { action: 'product', product: m.product, origin: 'ai' };
            r.status = 'suggested';
            if (!r.candidates.some((c) => c.product.id === m.product.id)) r.candidates.unshift({ product: m.product });
            if (r.qtyGuessed) { r.qty = null; r.qtyGuessed = false; perUnit(r); }
            guessQty(r);
        } else {
            r.ai_note = (m.reason || 'AI ไม่พบสินค้าที่ตรงกัน') + (m.check ? ` (${m.check})` : '');
            // The AI looked through the database and found nothing: drop the weaker name-only guess.
            if (r.decision?.origin === 'rule') { r.decision = null; r.match = null; r.status = 'none'; }
        }
    }

    /** Re-renders unless the pairing modal is open (it would lose the user's typing). */
    function refresh() {
        if (editing === null) draw();
    }

    async function runAi() {
        if (!currentModel() || aiRun.running) return;
        const todo = pendingAi();
        if (!todo.length) return;
        const before = aiRun.done;
        aiRun = { running: true, done: before, total: before + todo.length, searches: aiRun.searches || 0, stopped: false, error: null };
        refresh();
        while (!aiRun.stopped) {
            const batch = pendingAi().slice(0, AI_BATCH);
            if (!batch.length) break;
            try {
                const res = (await api.post('/pos-import/suggest', { lines: batch.map(([r, i]) => aiLine(r, i)), model: modelId })).data;
                (res.matches || []).forEach((m) => applyAi(m));
                aiRun.searches += res.searches || 0;
            } catch (e) {
                aiRun.error = e.message;
                break;
            }
            // Lines the AI did not answer are not asked again in this run.
            batch.forEach(([r]) => { r.aiDone = true; });
            aiRun.done += batch.length;
            if (step !== 'review') return;
            refresh();
        }
        aiRun.running = false;
        if (step === 'review') refresh();
    }

    async function analyze() {
        if (!text.trim()) { error = 'กรุณาวางรายงานยอดขาย หรือเลือกไฟล์'; draw(); return; }
        busy = 'analyze';
        error = null;
        success = null;
        draw();
        try {
            const res = (await api.post('/pos-import/analyze', { text, sale_date: dateTouched ? saleDate : '' })).data;
            rows = res.lines.map(toRow);
            ai = res.ai;
            warnings = res.warnings || [];
            previous = res.previous_imports || [];
            if (res.sale_date) saleDate = res.sale_date;
            filter = 'all';
            allowShort = false;
            step = 'review';
            aiRun = { running: false, done: 0, total: 0, searches: 0, stopped: false, error: null };
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

    async function save() {
        busy = 'save';
        error = null;
        draw();
        try {
            const res = (await api.post('/pos-import', {
                sale_date: saleDate,
                source_label: sourceLabel,
                allow_short_stock: allowShort,
                lines: rows.map((r) => ({
                    pos_name: r.pos_name,
                    pos_sku: r.pos_sku,
                    pos_category: r.pos_category,
                    qty: r.qty,
                    net: r.net,
                    cost: r.cost,
                    action: r.decision.action,
                    product_id: r.decision.product?.id ?? null,
                    origin: r.decision.origin,
                    new_product: r.decision.action === 'new' ? r.newProduct : null,
                })),
            })).data;
            success = res.sale
                ? `นำเข้ายอดขายวันที่ ${fmtDate(saleDate)} แล้ว: ${res.sale.doc_no} · ${count(res.lines)} รายการ · ฿ ${money(res.sale.total_value)}`
                    + (res.new_products ? ` · สินค้าใหม่ ${res.new_products}` : '') + (res.skipped ? ` · ไม่นำเข้า ${res.skipped}` : '') + ' — จำคู่สินค้าไว้ใช้ครั้งต่อไปแล้ว'
                : 'บันทึกคู่สินค้าแล้ว (ไม่มีรายการที่ต้องบันทึกยอดขาย)';
            step = 'input';
            text = '';
            sourceLabel = '';
            rows = [];
            dateTouched = false;
            saleDate = todayISO();
            await loadRecent();
        } catch (e) {
            error = e.message;
        }
        busy = null;
        draw();
    }

    draw();
}
