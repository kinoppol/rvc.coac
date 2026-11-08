import { api } from '../api.js';
import { printConsignSlip } from '../print-slip.js';
import { money, escapeHtml, fmtDate, fmtDateTime, todayISO } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

const STATUS = {
    pending: ['warn', 'รอร้านตรวจรับ'],
    received: ['ok', 'รับฝากแล้ว'],
    rejected: ['bad', 'ไม่รับฝาก'],
    cancelled: ['muted', 'ยกเลิกแล้ว'],
};

/** Consignor view: list the goods and quantities to bring; staff check them and receive. */
export async function render(container) {
    let cart = [];
    let note = '';
    // The day the goods are brought in: today or up to 3 days ahead; nothing may be made after it.
    let depositDate = todayISO();
    const plusDays = (iso, n) => {
        const d = new Date(`${iso}T00:00:00Z`);
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    };
    let error = null;
    let success = null;
    let requests = [];
    let deleted = []; // soft-deleted, restorable until purge_at
    let past = []; // goods brought before, offered while typing a product name
    let retentionDays = 30;
    let quoteSeq = 0; // ignore quote replies that arrive after a newer keystroke
    let open = false; // the "แจ้งฝากขาย" modal

    const onKey = (e) => {
        if (!container.isConnected) { document.removeEventListener('keydown', onKey); return; }
        if (e.key === 'Escape' && open) closeModal();
    };
    document.addEventListener('keydown', onKey);

    async function load() {
        const res = await api.get('/my/requests');
        requests = res.data;
        deleted = res.deleted || [];
        past = res.past || [];
        retentionDays = res.retention_days || retentionDays;
        draw();
    }

    function requestCard(r) {
        const [cls, label] = STATUS[r.status] || ['muted', r.status];
        const qty = r.items.reduce((s, i) => s + Number(i.qty), 0);
        return `
        <section class="card">
          <div class="card-head">
            <h2 style="font-size:14px">${escapeHtml(r.request_no)} <span class="pill ${cls}">${label}</span></h2>
            <div class="actions-row">
              <span class="muted" style="font-size:12.5px">${r.deposit_date ? `ฝากวันที่ <b>${fmtDate(r.deposit_date)}</b> · ` : ''}แจ้งเมื่อ ${fmtDateTime(r.created_at)} · ${r.items.length} รายการ ${qty} ชิ้น</span>
              ${r.status !== 'cancelled' ? `<button type="button" class="btn sm secondary" data-print="${r.id}">พิมพ์ใบฝากขาย</button>` : ''}
              ${r.status === 'pending' ? `<button type="button" class="btn sm secondary" data-cancel="${r.id}">ยกเลิกคำขอ</button>` : ''}
              ${r.status === 'cancelled' ? `<button type="button" class="btn sm secondary" data-delete="${r.id}">ลบ</button>` : ''}
            </div>
          </div>
          ${r.status === 'received' ? `<div class="card-pad" style="padding-bottom:0;font-size:13px">ร้านรับฝากแล้ว เลขที่ใบรับฝาก <b>${escapeHtml(r.receive_doc_no || '')}</b>
              — จำนวนจริงที่รับดูได้ที่หน้า «สินค้าที่ฝากขาย»</div>` : ''}
          ${r.status === 'rejected' ? `<div class="card-pad" style="padding-bottom:0"><div class="error-box">เหตุผล: ${escapeHtml(r.review_note || '-')}</div></div>` : ''}
          ${r.note ? `<div class="card-pad muted" style="padding-bottom:0;font-size:12.5px">หมายเหตุ: ${escapeHtml(r.note)}</div>` : ''}
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th class="num">ราคาที่ต้องการได้</th><th class="num">จำนวน</th><th>วันที่ผลิต</th><th>วันหมดอายุ</th></tr></thead>
            <tbody>${r.items.map((i) => `
              <tr><td>${escapeHtml(i.name)}${i.barcode ? `<div class="muted" style="font-size:11.5px;font-family:ui-monospace,monospace">${escapeHtml(i.barcode)}</div>` : ''}</td>
                  <td class="num">${money(i.cost_price)}</td><td class="num">${Number(i.qty)}</td>
                  <td class="muted">${i.mfg_date ? fmtDate(i.mfg_date) : '-'}</td>
                  <td class="muted">${i.expiry_date ? fmtDate(i.expiry_date) : '-'}</td></tr>`).join('')}
            </tbody>
          </table></div>
        </section>`;
    }

    function drawModal() {
        return `
        <div class="modal-backdrop" id="req-modal">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="req-modal-title" style="width:900px">
            <div class="modal-head">
              <h2 id="req-modal-title">แจ้งฝากขาย</h2>
              <button type="button" class="modal-close" data-close title="ปิด" aria-label="ปิด">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"></path></svg>
              </button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:16px">
              <div class="muted" style="font-size:12.5px">กรอกสินค้าทีละรายการแล้วกด «เพิ่มลงรายการ» เมื่อครบแล้วกด «ส่งคำขอฝาก» จากนั้นนำสินค้ามาที่ร้าน</div>
              <div class="error-box" id="modal-error" ${error ? '' : 'hidden'}>${escapeHtml(error || '')}</div>
              <div class="form-grid">
                <label class="field">วันที่ฝาก (วันที่นำของมาส่ง) * <input id="f-deposit" type="date" value="${depositDate}" min="${todayISO()}" max="${plusDays(todayISO(), 3)}" required>
                  <span class="muted" style="font-size:11.5px;font-weight:400">ทำรายการล่วงหน้าได้ไม่เกิน 3 วัน</span></label>
                <div class="field" style="grid-column:span 2"><label for="f-name">ชื่อสินค้า *</label>
                  <div class="ac">
                    <input id="f-name" placeholder="${past.length ? 'พิมพ์เพื่อค้นสินค้าที่เคยฝาก หรือกรอกชื่อใหม่' : 'เช่น ขนมทองม้วนสมุนไพร'}" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="f-name-list">
                    <div class="ac-list" id="f-name-list" role="listbox" hidden></div>
                  </div>
                  <span class="muted" id="f-name-note" style="font-size:11.5px;font-weight:400" hidden>กรอกราคาและจำนวนตามครั้งล่าสุดให้แล้ว แก้ไขได้</span>
                </div>
                <label class="field">ราคาที่ต้องการได้ต่อชิ้น (บาท) * <input id="f-cost" type="number" step="0.25" min="0"></label>
                <div class="field">ราคาขายหน้าร้าน <div id="f-price" class="price-preview">-</div></div>
                <label class="field">จำนวน (ชิ้น) * <input id="f-qty" type="number" min="1" value="1"></label>
                <label class="field">วันที่ผลิต * <input id="f-mfg" type="date" value="${depositDate}" max="${depositDate}" required>
                  <span class="muted" style="font-size:11.5px;font-weight:400">ไม่เกินวันที่ฝาก</span></label>
                <label class="field">วันหมดอายุ (ถ้ามี) <input id="f-expiry" type="date"></label>
                <label class="field">บาร์โค้ด (ถ้ามี) <input id="f-barcode" data-barcode autocomplete="off"></label>
              </div>
              <div id="quote-box" class="muted" style="font-size:13px"></div>
              <div><button type="button" class="btn secondary" id="btn-add">+ เพิ่มลงรายการ</button></div>
              <div>
                <h3 style="font-size:14px;margin:0 0 8px">รายการที่จะฝาก (${cart.length})</h3>
                <div class="table-wrap"><table>
                  <thead><tr><th>สินค้า</th><th class="num">ราคาที่ต้องการได้</th><th class="num">ราคาขายหน้าร้าน</th><th class="num">จำนวน</th><th>วันที่ผลิต</th><th></th></tr></thead>
                  <tbody>${cart.map((it, i) => `
                    <tr><td>${escapeHtml(it.name)}</td><td class="num">${money(it.cost_price)}</td><td class="num">${money(it.price)}</td>
                        <td class="num">${it.qty}</td><td class="muted">${fmtDate(it.mfg_date)}</td><td style="text-align:right"><button type="button" class="btn secondary sm" data-remove="${i}">ลบ</button></td></tr>`).join('')
                    || '<tr><td colspan="6" class="empty">ยังไม่มีรายการ</td></tr>'}
                  </tbody>
                </table></div>
              </div>
              <label class="field">หมายเหตุถึงร้าน (ถ้ามี) <input id="f-note" value="${escapeHtml(note)}" placeholder="เช่น จะนำมาส่งวันจันทร์ช่วงพักกลางวัน"></label>
            </div>
            <div class="modal-foot">
              <button type="button" class="btn secondary" data-close>ยกเลิก</button>
              <button type="button" class="btn" id="btn-submit" ${cart.length ? '' : 'disabled'}>ส่งคำขอฝาก (${cart.length} รายการ)</button>
            </div>
          </div>
        </div>`;
    }

    function draw() {
        container.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:16px">
          <div class="hint"><div class="dot">i</div><div>
            กด «+ แจ้งฝากขาย» เพื่อกรอกสินค้าและจำนวนที่จะนำมาฝากขาย จากนั้นนำสินค้ามาที่ร้าน
            พนักงานจะตรวจนับและแก้ไขให้ตรงกับของจริงก่อนรับฝาก
          </div></div>
          <div class="actions-row" style="justify-content:space-between">
            <h2 style="font-size:15px;margin:0">คำขอฝากของฉัน</h2>
            <button type="button" class="btn" id="btn-open">+ แจ้งฝากขาย</button>
          </div>
          ${success ? `<div class="pill ok" style="align-self:flex-start;font-size:13px;padding:8px 14px">${escapeHtml(success)}</div>` : ''}
          ${!open && error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
          ${requests.length ? requests.map(requestCard).join('') : '<div class="empty">ยังไม่มีคำขอฝาก — กด «+ แจ้งฝากขาย» เพื่อเริ่ม</div>'}
          ${deleted.length ? `
          <details class="card">
            <summary class="card-pad" style="cursor:pointer;font-size:13.5px">ลบแล้ว ${deleted.length} รายการ
              <span class="muted" style="font-size:12.5px">— กู้คืนได้ภายใน ${retentionDays} วันหลังลบ จากนั้นจะถูกลบถาวร</span></summary>
            <div class="table-wrap"><table>
              <thead><tr><th>เลขที่</th><th>รายการ</th><th>จะลบถาวรเมื่อ</th><th></th></tr></thead>
              <tbody>${deleted.map((r) => `
                <tr>
                  <td style="font-family:ui-monospace,monospace;font-size:12.5px">${escapeHtml(r.request_no)}</td>
                  <td style="font-size:12.5px">${r.items.map((i) => `${escapeHtml(i.name)} × ${Number(i.qty)}`).join(', ')}</td>
                  <td class="muted" style="font-size:12.5px">${fmtDate(r.purge_at)}</td>
                  <td style="text-align:right"><button type="button" class="btn sm secondary" data-restore="${r.id}">กู้คืน</button></td>
                </tr>`).join('')}
              </tbody>
            </table></div>
          </details>` : ''}
        </div>
        ${open ? drawModal() : ''}`;
        wire();
        if (open) container.querySelector('#f-name')?.focus();
    }

    function openModal() {
        open = true;
        error = null;
        success = null;
        draw();
    }

    function closeModal() {
        open = false; // the list is kept; reopening continues where they left off
        error = null;
        draw();
    }

    function wire() {
        container.querySelector('#btn-open').addEventListener('click', openModal);
        container.querySelectorAll('[data-delete]').forEach((btn) => btn.addEventListener('click', async () => {
            if (!(await confirmDialog(`กู้คืนได้ภายใน ${retentionDays} วัน หลังจากนั้นจะถูกลบถาวร`, { title: 'ลบคำขอนี้?', okText: 'ลบ', danger: true }))) return;
            try {
                await api.post(`/my/requests/${btn.dataset.delete}/delete`);
                success = `ลบแล้ว — กู้คืนได้จากหัวข้อ «ลบแล้ว» ด้านล่างภายใน ${retentionDays} วัน`;
                error = null;
            } catch (e) {
                error = e.message;
            }
            await load();
        }));
        container.querySelectorAll('[data-restore]').forEach((btn) => btn.addEventListener('click', async () => {
            try {
                await api.post(`/my/requests/${btn.dataset.restore}/restore`);
                success = 'กู้คืนคำขอแล้ว';
                error = null;
            } catch (e) {
                error = e.message;
            }
            await load();
        }));
        container.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', async () => {
            const r = requests.find((x) => String(x.id) === btn.dataset.print);
            btn.disabled = true;
            try {
                await printConsignSlip(r, async (cost) => (await api.post('/my/quote', { cost_price: cost })).data.price);
            } catch (e) {
                alertDialog(e.message, { tone: 'error' });
            }
            btn.disabled = false;
        }));
        container.querySelectorAll('[data-cancel]').forEach((btn) => btn.addEventListener('click', async () => {
            if (!(await confirmDialog('', { title: 'ยกเลิกคำขอฝากนี้?', okText: 'ยกเลิกคำขอ', cancelText: 'ไม่ยกเลิก', danger: true }))) return;
            try {
                await api.post(`/my/requests/${btn.dataset.cancel}/cancel`);
                success = 'ยกเลิกคำขอแล้ว';
                error = null;
            } catch (e) {
                error = e.message;
            }
            await load();
        }));
        if (!open) return;
        container.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeModal));
        wireNamePicker();
        container.querySelector('#f-cost').addEventListener('input', updateQuote);
        container.querySelector('#f-note').addEventListener('input', (e) => { note = e.target.value; });
        container.querySelector('#f-deposit')?.addEventListener('change', (e) => {
            const v = e.target.value;
            depositDate = v >= todayISO() && v <= plusDays(todayISO(), 3) ? v : todayISO();
            e.target.value = depositDate;
            // Made on or before the deposit day: a later production date follows it back.
            const mfg = container.querySelector('#f-mfg');
            mfg.max = depositDate;
            if (!mfg.value || mfg.value > depositDate) mfg.value = depositDate;
        });
        container.querySelector('#btn-add').addEventListener('click', addItem);
        container.querySelector('#btn-submit').addEventListener('click', submit);
        container.querySelectorAll('[data-remove]').forEach((btn) => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            draw();
        }));
    }

    /** Typing a product name offers the goods brought before; picking one fills in its price, quantity and barcode. */
    function wireNamePicker() {
        const input = container.querySelector('#f-name');
        const list = container.querySelector('#f-name-list');
        const note = container.querySelector('#f-name-note');
        const barcode = container.querySelector('#f-barcode');
        let rows = [];
        let active = -1;
        let picked = null;

        const hide = () => {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            active = -1;
        };
        const highlight = () => list.querySelectorAll('.ac-item').forEach((el, i) => el.classList.toggle('active', i === active));
        const show = () => {
            const term = input.value.trim().toLowerCase();
            rows = past.filter((p) => !term || p.name.toLowerCase().includes(term) || (p.barcode || '').toLowerCase().includes(term)).slice(0, 8);
            // Nothing to offer, or the name is already exactly the only match.
            if (!rows.length || (rows.length === 1 && rows[0].name.toLowerCase() === term)) { hide(); return; }
            active = -1;
            list.innerHTML = rows.map((p, i) => `
              <div class="ac-item" role="option" data-i="${i}">
                <span>${escapeHtml(p.name)}</span>
                <small>ราคาที่ได้ ฿${money(p.cost_price)}${p.qty ? ` · ครั้งล่าสุด ${p.qty} ชิ้น` : ''}${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}</small>
              </div>`).join('');
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };
        const pick = (p) => {
            picked = p;
            input.value = p.name;
            container.querySelector('#f-cost').value = p.cost_price;
            if (p.qty) container.querySelector('#f-qty').value = p.qty;
            barcode.value = p.barcode || '';
            note.hidden = false;
            hide();
            updateQuote();
            container.querySelector('#f-qty').focus();
            container.querySelector('#f-qty').select();
        };

        input.addEventListener('focus', show);
        input.addEventListener('input', () => {
            // A different name is a different product: the picked one's barcode must not carry over.
            if (picked && input.value.trim() !== picked.name) {
                barcode.value = '';
                note.hidden = true;
                picked = null;
            }
            show();
        });
        input.addEventListener('blur', () => setTimeout(hide, 0));
        input.addEventListener('keydown', (e) => {
            if (list.hidden) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const n = rows.length;
                active = e.key === 'ArrowDown' ? (active + 1) % n : (active - 1 + n) % n;
                highlight();
                list.children[active]?.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                pick(rows[active]);
            } else if (e.key === 'Escape') {
                e.stopPropagation(); // close the list, not the dialog
                hide();
            }
        });
        // pointerdown, before the input loses focus and the list goes away.
        list.addEventListener('pointerdown', (e) => {
            const el = e.target.closest('.ac-item');
            if (!el) return;
            e.preventDefault();
            pick(rows[Number(el.dataset.i)]);
        });
    }

    async function updateQuote() {
        const cost = Number(container.querySelector('#f-cost').value);
        const box = container.querySelector('#quote-box');
        const priceEl = container.querySelector('#f-price');
        const seq = ++quoteSeq;
        if (!cost) { box.textContent = ''; priceEl.textContent = '-'; return; }
        try {
            const q = (await api.post('/my/quote', { cost_price: cost })).data;
            if (seq !== quoteSeq) return;
            priceEl.textContent = `฿ ${money(q.price)}`;
            box.innerHTML = `ราคาที่คุณได้ ฿${money(cost)} + ค่าฝากขาย ฿${money(q.fee)} = ฿${money(q.raw)} → ปัดเป็นราคาขาย <b>฿${money(q.price)}</b>`;
        } catch (e) {
            if (seq === quoteSeq) { priceEl.textContent = '-'; box.textContent = e.message; }
        }
    }

    async function addItem() {
        error = null;
        success = null;
        const name = container.querySelector('#f-name').value.trim();
        const cost = Number(container.querySelector('#f-cost').value);
        const qty = Number(container.querySelector('#f-qty').value);
        const barcode = container.querySelector('#f-barcode').value.trim();
        const expiry = container.querySelector('#f-expiry').value;
        const mfg = container.querySelector('#f-mfg').value;
        // Show the problem without redrawing, so what they typed stays in the form.
        const fail = (msg) => {
            const box = container.querySelector('#modal-error');
            box.textContent = msg;
            box.hidden = false;
        };
        if (!name || !cost || !qty || !mfg) return fail('กรุณากรอกชื่อสินค้า ราคา จำนวน และวันที่ผลิตให้ครบ');
        if (mfg > depositDate) return fail('วันที่ผลิตต้องไม่เกินวันที่ฝาก');
        if (expiry && expiry < mfg) return fail('วันหมดอายุต้องไม่ก่อนวันที่ผลิต');
        const q = (await api.post('/my/quote', { cost_price: cost })).data;
        cart.push({ name, cost_price: cost, qty, barcode: barcode || null, mfg_date: mfg, expiry_date: expiry || null, price: q.price });
        draw();
    }

    async function submit() {
        const late = cart.find((it) => it.mfg_date > depositDate);
        if (late) {
            error = `«${late.name}» วันที่ผลิตเกินวันที่ฝาก — แก้วันที่ฝาก หรือลบรายการแล้วเพิ่มใหม่`;
            draw();
            return;
        }
        try {
            const res = await api.post('/my/requests', {
                note,
                deposit_date: depositDate,
                items: cart.map(({ name, cost_price, qty, barcode, mfg_date, expiry_date }) => ({ name, cost_price, qty, barcode, mfg_date, expiry_date })),
            });
            cart = [];
            note = '';
            depositDate = todayISO();
            error = null;
            open = false;
            success = `ส่งคำขอฝาก ${res.data.request_no} แล้ว — นำสินค้ามาที่ร้านเพื่อให้พนักงานตรวจรับ`;
        } catch (e) {
            error = e.message;
        }
        await load();
    }

    await load();
}
