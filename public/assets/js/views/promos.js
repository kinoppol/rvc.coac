import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

const STATUS = {
    on: ['ok', 'ใช้งานอยู่'],
    upcoming: ['warn', 'ยังไม่ถึงวันเริ่ม'],
    ended: ['', 'หมดเขตแล้ว'],
    off: ['', 'ปิดอยู่'],
};

/** "ชุดละ ฿20", "ลด ฿5 ต่อชุด", "ลด 10% ต่อชุด" */
function dealText(p) {
    if (p.kind === 'price') return `ชุดละ ฿${money(p.value)}`;
    if (p.kind === 'amount') return `ลด ฿${money(p.value)} ต่อชุด`;
    return `ลด ${Number(p.value)}% ต่อชุด`;
}

/** What one set saves at its cheapest / dearest regular price. */
function saving(p, regular) {
    if (p.kind === 'price') return regular - p.value;
    if (p.kind === 'amount') return Math.min(p.value, regular);
    return Math.round(regular * p.value) / 100;
}

function rangeText(lo, hi) {
    return Math.abs(hi - lo) < 0.005 ? `฿${money(lo)}` : `฿${money(lo)}–${money(hi)}`;
}

function itemLabel(it) {
    return it.sale_group_id ? `▦ ${it.name}` : it.name;
}

/**
 * โปรโมชั่นจับคู่สินค้า (migration 0086): goods bought together sell for less on the web POS.
 * Each promotion has slots ("ช่อง"); a slot needs a number of pieces from any of its products or
 * sale groups, so "any drink + any snack" is two slots with several items each.
 */
export async function render(container) {
    let promos = [];
    let ready = true;
    let canManage = false;

    async function load() {
        const res = await api.get('/promos');
        promos = res.data;
        ready = res.ready;
        canManage = res.can_manage;
        draw();
    }

    function slotsHtml(p) {
        return p.slots.map((s, i) => `
          <div style="margin-top:4px;font-size:13px">
            <b>${escapeHtml(s.label || `ช่อง ${i + 1}`)}</b> <span class="muted">${count(s.qty)} ชิ้น จาก</span>
            ${s.items.map((it) => `<span class="pill" style="margin:2px 2px 0 0" title="${it.sale_group_id ? `กลุ่มขาย ${count(it.members)} รายการ · ` : ''}ราคา ฿${money(it.sale_price ?? 0)} · คงเหลือ ${count(it.qty_stock)}">${escapeHtml(itemLabel(it))} ฿${money(it.sale_price ?? 0)}</span>`).join('')}
          </div>`).join('');
    }

    function draw() {
        if (!ready) {
            container.innerHTML = `<div class="card card-pad"><div class="error-box">ยังใช้โปรโมชั่นจับคู่สินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0086) ที่เมนู ระบบ ก่อน</div></div>`;
            return;
        }
        container.innerHTML = `
          <div class="card">
            <div class="card-pad" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
              <div style="flex:1;min-width:260px">
                <h2 style="margin:0 0 4px;font-size:17px">โปรโมชั่นจับคู่สินค้า</h2>
                <div class="muted" style="font-size:13px">ซื้อสินค้าคู่กันแล้วได้ราคาพิเศษ เช่น "น้ำดื่มใดก็ได้ + ขนมใดก็ได้ ชุดละ ฿20" หรือ "ขนม 3 ชิ้น ฿10"
                  · แต่ละช่องเลือกได้หลายสินค้าหรือกลุ่มขายหน้า POS (ลูกค้าหยิบรายการใดในช่องก็ได้)
                  · หน้า POS คิดส่วนลดให้เองเมื่อสินค้าในตะกร้าครบชุด (ครบหลายชุดก็ลดหลายชุด เลือกชิ้นราคาสูงก่อนให้ลูกค้าได้ส่วนลดมากที่สุด)
                  · มีหลายโปรที่ใช้สินค้าเดียวกัน ระบบใช้โปรที่สร้างก่อน · สินค้าฝากขาย ร้านรับภาระส่วนลด ผู้ฝากได้ส่วนแบ่งเต็มตามราคาปกติ
                  · ยกแพ็คไม่ร่วมโปร</div>
              </div>
              ${canManage ? '<button type="button" class="btn" data-new>+ สร้างโปรโมชั่น</button>' : ''}
            </div>
          </div>
          <div class="card" style="margin-top:12px">
            ${promos.length ? `<div class="table-wrap"><table>
              <thead><tr><th>โปรโมชั่น</th><th>สินค้าที่จับคู่</th><th class="num">ราคา/ส่วนลด</th><th>ช่วงเวลา</th><th class="num">ใช้ไปแล้ว</th>${canManage ? '<th></th>' : ''}</tr></thead>
              <tbody>${promos.map((p) => {
                  const [tone, label] = STATUS[p.status] || STATUS.off;
                  const lo = saving(p, p.regular_min);
                  const hi = saving(p, p.regular_max);
                  return `<tr>
                    <td style="min-width:160px"><b>${escapeHtml(p.name)}</b><div><span class="pill ${tone}">${label}</span></div>
                      ${p.note ? `<div class="muted" style="font-size:12px">${escapeHtml(p.note)}</div>` : ''}</td>
                    <td style="min-width:240px">${slotsHtml(p)}</td>
                    <td class="num" style="white-space:nowrap"><b>${dealText(p)}</b>
                      <div class="muted" style="font-size:12px">ราคาปกติ ${rangeText(p.regular_min, p.regular_max)}</div>
                      <div style="font-size:12px;${hi < 0.005 ? 'color:var(--bad)' : ''}">${hi < 0.005 ? 'ไม่ได้ลดจากราคาปกติ' : `ลูกค้าประหยัด ${rangeText(Math.max(0, lo), hi)}`}</div></td>
                    <td style="white-space:nowrap;font-size:13px">${p.starts_on ? fmtDate(p.starts_on) : 'ไม่กำหนด'}<br>ถึง ${p.ends_on ? fmtDate(p.ends_on) : 'ไม่กำหนด'}</td>
                    <td class="num" style="white-space:nowrap;font-size:13px">${count(p.used_bills)} บิล<br><span class="muted">ลดไป ฿${money(p.used_discount)}</span></td>
                    ${canManage ? `<td style="white-space:nowrap">
                      <button type="button" class="btn secondary sm" data-edit="${p.id}">แก้ไข</button>
                      <button type="button" class="btn secondary sm" data-toggle="${p.id}">${p.active ? 'ปิด' : 'เปิด'}</button>
                      <button type="button" class="btn secondary sm" data-del="${p.id}" style="color:var(--bad)">ลบ</button></td>` : ''}
                  </tr>`;
              }).join('')}</tbody></table></div>`
              : `<div class="card-pad empty">ยังไม่มีโปรโมชั่นจับคู่สินค้า${canManage ? ' — กด "+ สร้างโปรโมชั่น" เพื่อเริ่ม' : ''}</div>`}
          </div>`;
    }

    container.addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.new !== undefined) return editor(null);
        const id = Number(t.dataset.edit || t.dataset.toggle || t.dataset.del);
        const p = promos.find((x) => x.id === id);
        if (!p) return;
        try {
            if (t.dataset.edit) {
                editor(p);
            } else if (t.dataset.toggle) {
                await api.post(`/promos/${id}/active`, { active: !p.active });
                await load();
            } else if (t.dataset.del) {
                const yes = await confirmDialog(`ลบโปรโมชั่น "${p.name}"? บิลที่เคยได้ส่วนลดจากโปรนี้ยังคงเดิม${p.active ? '\nถ้าแค่หยุดใช้ชั่วคราว ให้กด "ปิด" แทน' : ''}`, { title: 'ลบโปรโมชั่น', okText: 'ลบ', danger: true });
                if (!yes) return;
                await api.post(`/promos/${id}/delete`, {});
                await load();
            }
        } catch (err) {
            alertDialog(err.message, { tone: 'error' });
        }
    });

    /** Create / edit a promotion: name, deal, dates, and the slots with their products / groups. */
    function editor(src) {
        const f = src ? {
            name: src.name, kind: src.kind, value: src.value, starts_on: src.starts_on || '', ends_on: src.ends_on || '',
            active: src.active, note: src.note || '',
            slots: src.slots.map((s) => ({ label: s.label || '', qty: s.qty, items: s.items.map((it) => ({ ...it })) })),
        } : {
            name: '', kind: 'price', value: '', starts_on: '', ends_on: '', active: true, note: '',
            slots: [{ label: '', qty: 1, items: [] }, { label: '', qty: 1, items: [] }],
        };
        let error = null;
        let busy = false;
        let search = { slot: -1, q: '', items: [], loading: false };
        let searchTimer;

        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        document.body.appendChild(box);
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey, true); };
        const onKey = (e) => { if (e.key === 'Escape' && !busy) { e.stopPropagation(); close(); } };
        document.addEventListener('keydown', onKey, true);

        const keyOf = (it) => (it.sale_group_id ? `g${it.sale_group_id}` : `p${it.product_id}`);

        function preview() {
            let lo = 0;
            let hi = 0;
            for (const s of f.slots) {
                const prices = s.items.map((it) => Number(it.sale_price) || 0);
                if (!prices.length) return '';
                lo += Math.min(...prices) * (Number(s.qty) || 0);
                hi += Math.max(...prices) * (Number(s.qty) || 0);
            }
            const v = Number(f.value);
            if (!(v > 0)) return `ราคาปกติต่อชุด ${rangeText(lo, hi)}`;
            const p = { kind: f.kind, value: v };
            const sLo = saving(p, lo);
            const sHi = saving(p, hi);
            return `ราคาปกติต่อชุด ${rangeText(lo, hi)} → ${dealText(p)} · ลูกค้าประหยัด ${sHi < 0.005 ? '<b style="color:var(--bad)">ไม่ได้ลด (ราคาชุดสูงกว่าราคาปกติ)</b>' : rangeText(Math.max(0, sLo), sHi)}${sLo < 0.005 && sHi >= 0.005 ? ' <span class="muted">(ชุดที่ราคาปกติไม่ถึงราคาชุดจะไม่ได้ลด)</span>' : ''}`;
        }

        function resultsHtml(i) {
            if (search.slot !== i || !search.q) return '';
            const have = new Set(f.slots[i].items.map(keyOf));
            const list = search.items.filter((it) => !have.has(keyOf(it)));
            return `<div style="border:1px solid var(--border);border-radius:8px;margin-top:6px;max-height:220px;overflow:auto">
              ${search.loading && !list.length ? '<div class="muted" style="padding:8px">กำลังค้นหา...</div>'
                : list.length ? list.map((it) => `
                  <button type="button" class="link-btn" data-add="${i}" data-key="${keyOf(it)}" style="display:flex;width:100%;gap:8px;justify-content:space-between;padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)">
                    <span>${escapeHtml(itemLabel(it))}<small class="muted"> · ${it.sale_group_id ? `กลุ่มขาย ${count(it.members)} รายการ` : escapeHtml(it.consignor_name)}${it.barcode ? ` · ${escapeHtml(it.barcode)}` : ''}</small></span>
                    <span style="white-space:nowrap">฿${money(it.sale_price)} <small class="muted">เหลือ ${count(it.qty_stock)}</small></span>
                  </button>`).join('')
                : '<div class="muted" style="padding:8px">ไม่พบสินค้า</div>'}
            </div>`;
        }

        function draw() {
            const body = box.querySelector('.modal-body');
            const scrolled = body?.scrollTop || 0;
            const focusSlot = document.activeElement?.dataset?.search;
            const caret = document.activeElement?.selectionStart;
            box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:760px">
              <div class="modal-head"><h2>${src ? 'แก้ไขโปรโมชั่น' : 'สร้างโปรโมชั่นจับคู่สินค้า'}</h2><button type="button" class="modal-close" data-close aria-label="ปิด">✕</button></div>
              <div class="modal-body" style="padding-top:12px">
                <label class="field"><span>ชื่อโปรโมชั่น (แสดงในตะกร้าหน้า POS)</span><input type="text" data-f="name" maxlength="200" value="${escapeHtml(f.name)}" placeholder="เช่น น้ำ + ขนม ชุดละ 20 บาท"></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin-top:8px">
                  <label class="field"><span>รูปแบบ</span><select data-f="kind">
                    <option value="price" ${f.kind === 'price' ? 'selected' : ''}>ราคาชุด (ทั้งชุดขายราคา)</option>
                    <option value="amount" ${f.kind === 'amount' ? 'selected' : ''}>ลดเป็นบาทต่อชุด</option>
                    <option value="percent" ${f.kind === 'percent' ? 'selected' : ''}>ลดเป็น % ต่อชุด</option></select></label>
                  <label class="field"><span>${f.kind === 'price' ? 'ราคาชุด (บาท)' : f.kind === 'amount' ? 'ลด (บาท/ชุด)' : 'ลด (%)'}</span><input type="number" step="0.01" min="0" data-f="value" value="${escapeHtml(String(f.value))}"></label>
                  <label class="field"><span>เริ่ม (เว้นว่าง = ทันที)</span><input type="date" data-f="starts_on" value="${escapeHtml(f.starts_on)}"></label>
                  <label class="field"><span>สิ้นสุด (เว้นว่าง = ไม่กำหนด)</span><input type="date" data-f="ends_on" value="${escapeHtml(f.ends_on)}"></label>
                </div>

                <h3 style="margin:16px 0 4px;font-size:14.5px">สินค้าที่จับคู่ (1 ชุด)</h3>
                <div class="muted" style="font-size:12.5px;margin-bottom:6px">แต่ละช่องต้องครบตามจำนวนชิ้น ลูกค้าหยิบสินค้าใดในช่องก็ได้ · ใส่กลุ่มขายหน้า POS (▦) ได้ทั้งกลุ่ม · ต้องการ "ซื้อ 3 ชิ้น ราคาพิเศษ" ใช้ช่องเดียว จำนวน 3</div>
                ${f.slots.map((s, i) => `
                  <div style="border:1px solid var(--border);border-radius:10px;padding:10px;margin-top:8px">
                    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                      <label class="field" style="flex:1;min-width:160px"><span>ช่องที่ ${i + 1} — ชื่อช่อง (ไม่บังคับ)</span><input type="text" data-slot-label="${i}" maxlength="100" value="${escapeHtml(s.label)}" placeholder="เช่น เครื่องดื่ม"></label>
                      <label class="field" style="width:110px"><span>จำนวนชิ้น</span><input type="number" min="1" max="99" step="1" data-slot-qty="${i}" value="${escapeHtml(String(s.qty))}"></label>
                      ${f.slots.length > 1 ? `<button type="button" class="btn secondary sm" data-slot-del="${i}" style="color:var(--bad)">ลบช่อง</button>` : ''}
                    </div>
                    <div style="margin-top:6px">${s.items.map((it, j) => `<span class="pill" style="margin:2px 4px 2px 0">${escapeHtml(itemLabel(it))} ฿${money(it.sale_price ?? 0)}${it.consigned ? ' <small>(ฝากขาย)</small>' : ''}
                        <button type="button" class="link-btn" data-item-del="${i}" data-j="${j}" aria-label="เอาออก" style="margin-left:4px">✕</button></span>`).join('') || '<span class="muted" style="font-size:12.5px">ยังไม่มีสินค้าในช่องนี้</span>'}</div>
                    <div class="field" style="margin-top:6px"><input type="search" data-search="${i}" placeholder="ค้นหาชื่อสินค้า บาร์โค้ด หรือกลุ่มขาย เพื่อเพิ่มเข้าช่องนี้" autocomplete="off" value="${search.slot === i ? escapeHtml(search.q) : ''}"></div>
                    ${resultsHtml(i)}
                  </div>`).join('')}
                <button type="button" class="btn secondary sm" data-slot-add style="margin-top:8px">+ เพิ่มช่องสินค้า</button>

                <div class="hint" style="margin-top:12px"><div class="dot">i</div><div data-preview>${preview() || 'เพิ่มสินค้าให้ครบทุกช่องเพื่อดูราคาปกติต่อชุด'}</div></div>
                <label class="field" style="margin-top:8px"><span>หมายเหตุ</span><input type="text" data-f="note" maxlength="500" value="${escapeHtml(f.note)}"></label>
                <label class="pos-switch" style="margin-top:8px"><input type="checkbox" data-f="active" ${f.active ? 'checked' : ''}><span aria-hidden="true"></span> เปิดใช้งาน</label>
                ${error ? `<div class="error-box" style="margin-top:8px">${escapeHtml(error)}</div>` : ''}
              </div>
              <div class="modal-foot"><button type="button" class="btn secondary" data-close>ยกเลิก</button>
                <button type="button" class="btn" data-save ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div></div>`;
            box.querySelector('.modal-body').scrollTop = scrolled;
            if (focusSlot !== undefined) {
                const inp = box.querySelector(`[data-search="${focusSlot}"]`);
                inp?.focus();
                try { inp?.setSelectionRange(caret, caret); } catch (err) { /* not a text input */ }
            }
        }

        function runSearch(i, q) {
            search = { slot: i, q, items: search.slot === i ? search.items : [], loading: !!q };
            clearTimeout(searchTimer);
            if (!q) { draw(); return; }
            draw();
            searchTimer = setTimeout(async () => {
                try {
                    const res = await api.get(`/promos/items?q=${encodeURIComponent(q)}`);
                    if (search.slot === i && search.q === q) search = { slot: i, q, items: res.data, loading: false };
                } catch (err) {
                    search.loading = false;
                }
                if (box.isConnected) draw();
            }, 250);
        }

        box.addEventListener('input', (e) => {
            const t = e.target;
            if (t.dataset.search !== undefined) return runSearch(Number(t.dataset.search), t.value.trim());
            if (t.dataset.f && t.type !== 'checkbox' && t.tagName !== 'SELECT') f[t.dataset.f] = t.value;
            if (t.dataset.slotLabel !== undefined) f.slots[Number(t.dataset.slotLabel)].label = t.value;
            if (t.dataset.slotQty !== undefined) f.slots[Number(t.dataset.slotQty)].qty = t.value;
            const pv = box.querySelector('[data-preview]');
            if (pv) pv.innerHTML = preview() || 'เพิ่มสินค้าให้ครบทุกช่องเพื่อดูราคาปกติต่อชุด';
        });
        box.addEventListener('change', (e) => {
            const t = e.target;
            if (t.dataset.f === 'active') f.active = t.checked;
            else if (t.dataset.f === 'kind') { f.kind = t.value; draw(); }
        });
        box.addEventListener('keydown', (e) => {
            // Enter in the search box adds the first match.
            if (e.key === 'Enter' && e.target.dataset.search !== undefined) {
                e.preventDefault();
                box.querySelector(`[data-add="${e.target.dataset.search}"]`)?.click();
            }
        });
        box.addEventListener('click', async (e) => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.close !== undefined) return close();
            if (t.dataset.slotAdd !== undefined) { f.slots.push({ label: '', qty: 1, items: [] }); draw(); return; }
            if (t.dataset.slotDel !== undefined) { f.slots.splice(Number(t.dataset.slotDel), 1); search = { slot: -1, q: '', items: [] }; draw(); return; }
            if (t.dataset.itemDel !== undefined) { f.slots[Number(t.dataset.itemDel)].items.splice(Number(t.dataset.j), 1); draw(); return; }
            if (t.dataset.add !== undefined) {
                const i = Number(t.dataset.add);
                const it = search.items.find((x) => keyOf(x) === t.dataset.key);
                if (it) f.slots[i].items.push({ ...it });
                search = { slot: i, q: '', items: [] };
                draw();
                box.querySelector(`[data-search="${i}"]`)?.focus();
                return;
            }
            if (t.dataset.save !== undefined) {
                if (busy) return;
                busy = true;
                error = null;
                draw();
                const body = {
                    name: f.name, kind: f.kind, value: f.value, starts_on: f.starts_on, ends_on: f.ends_on, active: f.active, note: f.note,
                    slots: f.slots.map((s) => ({
                        label: s.label, qty: Number(s.qty) || 0,
                        items: s.items.map((it) => (it.sale_group_id ? { sale_group_id: it.sale_group_id } : { product_id: it.product_id })),
                    })),
                };
                try {
                    if (src) await api.put(`/promos/${src.id}`, body);
                    else await api.post('/promos', body);
                    busy = false;
                    close();
                    await load();
                } catch (err) {
                    busy = false;
                    error = err.message;
                    draw();
                }
            }
        });
        draw();
        box.querySelector('[data-f="name"]').focus();
    }

    await load();
}
