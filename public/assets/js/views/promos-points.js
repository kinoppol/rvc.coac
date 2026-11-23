import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';

const STATUS = { on: ['ok', 'ใช้งานอยู่'], upcoming: ['warn', 'ยังไม่ถึงวันเริ่ม'], paused: ['warn', 'นอกช่วงเวลาต่อวัน'], ended: ['', 'หมดเขตแล้ว'], off: ['', 'ปิดอยู่'] };

function inputValue(v) {
    return v ? v.slice(0, 16).replace(' ', 'T') : '';
}

function itemLabel(it) {
    return it.sale_group_id ? `▦ ${it.name}` : it.name;
}

const keyOf = (it) => (it.sale_group_id ? `g${it.sale_group_id}` : `p${it.product_id}`);

/** The lowest price a piece can sell for once points take the most they may off it. */
function lowest(it, max) {
    const price = Number(it.sale_price) || 0;
    return Math.max(0, price - (max === null ? price : Math.min(max, price)));
}

/** A piece whose lowest price falls below what it costs the store (null cost = unknown, no warning). */
function loss(it, max) {
    if (it.cost === null || it.cost === undefined) return 0;
    const l = Number(it.cost) - lowest(it, max);
    return l > 0.004 ? l : 0;
}

/**
 * ใช้แต้มสะสมเป็นส่วนลด: goods a member can take points off at the POS ("ใช้แต้ม" after the item in the
 * cart). Each rule: X points take ฿Y off a piece, at most ฿Z off a piece; points are held as soon as the
 * cashier presses the button. Warns when the price after points could fall below the store's cost.
 */
export async function render(container) {
    let list = [];
    let canManage = false;

    async function load() {
        const res = await api.get('/point-redeems');
        if (!res.ready) {
            container.innerHTML = '<div class="card card-pad"><div class="error-box">ยังใช้แต้มสะสมไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0088) ที่เมนู ระบบ ก่อน</div></div>';
            return;
        }
        list = res.data;
        canManage = res.can_manage;
        draw();
    }

    function ruleText(r) {
        return `${count(r.points)} แต้ม ลด ฿${money(r.baht)}`;
    }

    function draw() {
        container.innerHTML = `
          <div class="card"><div class="card-pad" style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
            <div style="flex:1;min-width:260px"><h2 style="margin:0 0 4px;font-size:17px">ใช้แต้มสะสมเป็นส่วนลด</h2>
              <div class="muted" style="font-size:13px">กำหนดสินค้าที่สมาชิกใช้แต้มลดราคาได้ (ค่าเริ่มต้น 1 แต้ม = ลด 1 บาท) และส่วนลดสูงสุดต่อชิ้น
                · หน้า POS เมื่อระบุสมาชิกที่มีแต้ม จะมีปุ่ม "ใช้แต้ม" หลังชื่อสินค้าที่ร่วมรายการ กดแล้วแต้มถูกจองไว้ทันที (ได้คืนถ้าเอาออกหรือยกเลิกการขาย)
                · สินค้าฝากขาย ร้านรับภาระส่วนลด ผู้ฝากได้ส่วนแบ่งเต็ม · ระบบเตือนเมื่อราคาหลังใช้แต้มต่ำกว่าต้นทุน
                · อัตราสะสมแต้มตั้งที่เมนู <a href="#/membersettings">ตั้งค่าแต้มสะสม</a></div></div>
            ${canManage ? '<button type="button" class="btn" data-new>+ เพิ่มรายการใช้แต้ม</button>' : ''}
          </div></div>
          <div class="card" style="margin-top:12px">${list.length ? `<div class="table-wrap"><table>
            <thead><tr><th>รายการ</th><th>สินค้าที่ร่วมรายการ</th><th class="num">อัตรา</th><th>ช่วงเวลา</th><th class="num">ใช้ไปแล้ว</th>${canManage ? '<th></th>' : ''}</tr></thead>
            <tbody>${list.map((r) => {
                const [tone, label] = STATUS[r.status] || STATUS.off;
                const losing = r.items.filter((it) => loss(it, r.max_discount) > 0);
                return `<tr>
                  <td style="min-width:150px"><b>${escapeHtml(r.name)}</b><div><span class="pill ${tone}">${label}</span></div>
                    ${r.note ? `<div class="muted" style="font-size:12px">${escapeHtml(r.note)}</div>` : ''}</td>
                  <td style="min-width:240px">${r.items.map((it) => `<span class="pill ${loss(it, r.max_discount) > 0 ? 'bad' : ''}" style="margin:2px 2px 0 0"
                      title="ราคา ฿${money(it.sale_price ?? 0)} · ต้นทุน ${it.cost === null ? '-' : `฿${money(it.cost)}`} · ต่ำสุดหลังใช้แต้ม ฿${money(lowest(it, r.max_discount))}">${escapeHtml(itemLabel(it))} ฿${money(it.sale_price ?? 0)}</span>`).join('') || '<span class="muted">ยังไม่มีสินค้า</span>'}
                    ${losing.length ? `<div style="font-size:12px;color:var(--bad);margin-top:3px">⚠ ${losing.length} รายการ ราคาหลังใช้แต้มอาจต่ำกว่าต้นทุน</div>` : ''}</td>
                  <td class="num" style="white-space:nowrap"><b>${ruleText(r)}</b><div class="muted" style="font-size:12px">${r.max_discount === null ? 'ลดได้ถึงเต็มราคา' : `สูงสุด ฿${money(r.max_discount)}/ชิ้น`}</div></td>
                  <td style="white-space:nowrap;font-size:13px">${r.starts_on ? escapeHtml(fmtDateTime(r.starts_on.slice(0, 16))) : 'ทันที'}<br>ถึง ${r.ends_on ? escapeHtml(fmtDateTime(r.ends_on.slice(0, 16))) : 'ไม่กำหนด'}${r.daily_from && r.daily_to ? `<br><span class="muted">⏰ ${escapeHtml(r.daily_from)}–${escapeHtml(r.daily_to)} น. ทุกวัน</span>` : ''}</td>
                  <td class="num" style="white-space:nowrap;font-size:13px">${count(r.used_bills)} บิล · ${count(r.used_points)} แต้ม<br><span class="muted">ลดไป ฿${money(r.used_discount)}</span></td>
                  ${canManage ? `<td style="white-space:nowrap"><button type="button" class="btn secondary sm" data-edit="${r.id}">แก้ไข</button>
                    <button type="button" class="btn secondary sm" data-toggle="${r.id}">${r.active ? 'ปิด' : 'เปิด'}</button>
                    <button type="button" class="btn secondary sm" data-del="${r.id}" style="color:var(--bad)">ลบ</button></td>` : ''}
                </tr>`;
            }).join('')}</tbody></table></div>` : `<div class="card-pad empty">ยังไม่มีสินค้าที่ใช้แต้มลดราคาได้${canManage ? ' — กด "+ เพิ่มรายการใช้แต้ม" เพื่อเริ่ม' : ''}</div>`}</div>`;
    }

    container.addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.new !== undefined) return editor(null);
        const id = Number(t.dataset.edit || t.dataset.toggle || t.dataset.del);
        const r = list.find((x) => x.id === id);
        if (!r) return;
        try {
            if (t.dataset.edit) editor(r);
            else if (t.dataset.toggle) { await api.post(`/point-redeems/${id}/active`, { active: !r.active }); await load(); }
            else if (t.dataset.del && await confirmDialog(`ลบรายการใช้แต้ม "${r.name}"? บิลที่เคยใช้แต้มยังคงเดิม`, { title: 'ลบรายการใช้แต้ม', okText: 'ลบ', danger: true })) {
                await api.post(`/point-redeems/${id}/delete`, {});
                await load();
            }
        } catch (err) {
            alertDialog(err.message, { tone: 'error' });
        }
    });

    function editor(src) {
        const f = src ? {
            name: src.name, points: src.points, baht: src.baht, max_discount: src.max_discount ?? '', starts_on: inputValue(src.starts_on), ends_on: inputValue(src.ends_on),
            daily_from: src.daily_from || '', daily_to: src.daily_to || '',
            active: src.active, note: src.note || '', items: src.items.map((it) => ({ ...it })),
        } : { name: '', points: 1, baht: 1, max_discount: '', starts_on: '', ends_on: '', daily_from: '', daily_to: '', active: true, note: '', items: [] };
        let error = null;
        let busy = false;
        let search = { q: '', items: [], loading: false };
        let timer;
        const box = document.createElement('div');
        box.className = 'modal-backdrop';
        document.body.appendChild(box);
        const onKey = (e) => { if (e.key === 'Escape' && !busy) { e.stopPropagation(); close(); } };
        const close = () => { box.remove(); document.removeEventListener('keydown', onKey, true); };
        document.addEventListener('keydown', onKey, true);

        const maxValue = () => (f.max_discount === '' || f.max_discount === null ? null : Number(f.max_discount));

        function itemsHtml() {
            const max = maxValue();
            const pts = Number(f.points) || 0;
            const baht = Number(f.baht) || 0;
            if (!f.items.length) return '<div class="muted" style="font-size:12.5px">ยังไม่มีสินค้า — ค้นหาด้านล่างเพื่อเพิ่ม</div>';
            return `<div class="table-wrap"><table style="font-size:13px"><thead><tr><th>สินค้า</th><th class="num">ราคาขาย</th><th class="num">ต้นทุน/ชิ้น</th>
              <th class="num">ลดได้สูงสุด</th><th class="num">ราคาหลังใช้แต้ม (ต่ำสุด)</th><th></th></tr></thead><tbody>${f.items.map((it, j) => {
                const price = Number(it.sale_price) || 0;
                const off = price - lowest(it, max);
                const steps = baht > 0 ? Math.floor(off / baht + 1e-9) : 0;
                const l = loss(it, max);
                return `<tr${l > 0 ? ' style="background:color-mix(in srgb, var(--bad) 8%, transparent)"' : ''}>
                  <td>${escapeHtml(itemLabel(it))}${it.consigned ? ' <small class="muted">(ฝากขาย: ต้นทุน = ส่วนแบ่งผู้ฝาก)</small>' : ''}${it.sale_group_id ? ' <small class="muted">(ต้นทุนสูงสุดในกลุ่ม)</small>' : ''}</td>
                  <td class="num">฿${money(price)}</td>
                  <td class="num">${it.cost === null || it.cost === undefined ? '<span class="muted">ไม่ทราบ</span>' : `฿${money(it.cost)}`}</td>
                  <td class="num">฿${money(steps * baht)}<div class="muted" style="font-size:11.5px">${count(steps * pts)} แต้ม</div></td>
                  <td class="num"><b style="${l > 0 ? 'color:var(--bad)' : ''}">฿${money(price - steps * baht)}</b>${l > 0 ? `<div style="font-size:11.5px;color:var(--bad)">⚠ ต่ำกว่าต้นทุน ขาดทุน ฿${money(Number(it.cost) - (price - steps * baht))}/ชิ้น</div>` : ''}</td>
                  <td><button type="button" class="link-btn" data-item-del="${j}" aria-label="เอาออก">✕</button></td></tr>`;
            }).join('')}</tbody></table></div>`;
        }

        function warnHtml() {
            const max = maxValue();
            const bad = f.items.filter((it) => {
                const price = Number(it.sale_price) || 0;
                const baht = Number(f.baht) || 0;
                const steps = baht > 0 ? Math.floor((price - lowest(it, max)) / baht + 1e-9) : 0;
                return it.cost !== null && it.cost !== undefined && price - steps * baht < Number(it.cost) - 0.004;
            });
            if (!bad.length) return '';
            return `<div class="error-box" style="margin-top:8px">⚠ ราคาขายหลังใช้แต้มต่ำกว่าต้นทุน ${bad.length} รายการ: ${bad.map((it) => escapeHtml(it.name)).join(', ')}
              <div style="font-size:12.5px;margin-top:2px">ร้านจะขาดทุนเมื่อลูกค้าใช้แต้มเต็มจำนวน — ลด "ส่วนลดสูงสุดต่อชิ้น" หรือเอาสินค้าออก (บันทึกได้ถ้าตั้งใจ)</div></div>`;
        }

        function resultsHtml() {
            if (!search.q) return '';
            const have = new Set(f.items.map(keyOf));
            const rows = search.items.filter((it) => !have.has(keyOf(it)));
            return `<div style="border:1px solid var(--border);border-radius:8px;margin-top:6px;max-height:220px;overflow:auto">
              ${search.loading && !rows.length ? '<div class="muted" style="padding:8px">กำลังค้นหา...</div>' : rows.length ? rows.map((it) => `
                <button type="button" class="link-btn" data-add="${keyOf(it)}" style="display:flex;width:100%;gap:8px;justify-content:space-between;padding:6px 10px;text-align:left;border-bottom:1px solid var(--border)">
                  <span>${escapeHtml(itemLabel(it))}<small class="muted"> · ${it.sale_group_id ? `กลุ่มขาย ${count(it.members)} รายการ` : escapeHtml(it.consignor_name || '')}${it.barcode ? ` · ${escapeHtml(it.barcode)}` : ''}</small></span>
                  <span style="white-space:nowrap">฿${money(it.sale_price)} <small class="muted">ทุน ${it.cost === null ? '-' : `฿${money(it.cost)}`}</small></span>
                </button>`).join('') : '<div class="muted" style="padding:8px">ไม่พบสินค้า</div>'}</div>`;
        }

        function refresh() {
            box.querySelector('[data-items]').innerHTML = itemsHtml();
            box.querySelector('[data-warn]').innerHTML = warnHtml();
        }

        function draw() {
            const scrolled = box.querySelector('.modal-body')?.scrollTop || 0;
            const searching = document.activeElement?.dataset?.search !== undefined;
            box.innerHTML = `<div class="modal" role="dialog" aria-modal="true" style="width:820px">
              <div class="modal-head"><h2>${src ? 'แก้ไขรายการใช้แต้ม' : 'เพิ่มรายการใช้แต้มเป็นส่วนลด'}</h2><button type="button" class="modal-close" data-close aria-label="ปิด">✕</button></div>
              <div class="modal-body" style="padding-top:12px">
                <label class="field"><span>ชื่อรายการ</span><input type="text" data-f="name" maxlength="200" value="${escapeHtml(f.name)}" placeholder="เช่น ใช้แต้มลดราคาเครื่องดื่ม"></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin-top:8px">
                  <label class="field"><span>ใช้กี่แต้ม</span><input type="number" min="1" step="1" data-f="points" value="${escapeHtml(String(f.points))}"></label>
                  <label class="field"><span>ลดกี่บาท</span><input type="number" min="0.01" step="0.01" data-f="baht" value="${escapeHtml(String(f.baht))}"></label>
                  <label class="field"><span>ลดสูงสุดต่อชิ้น (บาท, ว่าง = ถึงเต็มราคา)</span><input type="number" min="0" step="0.01" data-f="max_discount" value="${escapeHtml(String(f.max_discount))}"></label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:8px">
                  <label class="field"><span>เริ่ม (เว้นว่าง = ทันที)</span><input type="datetime-local" data-f="starts_on" value="${escapeHtml(f.starts_on)}"></label>
                  <label class="field"><span>สิ้นสุด (เว้นว่าง = ไม่กำหนด)</span><input type="datetime-local" data-f="ends_on" value="${escapeHtml(f.ends_on)}"></label>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:8px">
                  <label class="field"><span>ใช้แต้มได้ตั้งแต่เวลา (ต่อวัน, เว้นว่าง = ทั้งวัน)</span><input type="time" data-f="daily_from" value="${escapeHtml(f.daily_from)}"></label>
                  <label class="field"><span>ถึงเวลา (ต่อวัน)</span><input type="time" data-f="daily_to" value="${escapeHtml(f.daily_to)}"></label>
                </div>
                <div class="muted" style="font-size:12px;margin-top:2px">กำหนดช่วงเวลาต่อวันเพื่อให้ใช้แต้มได้เฉพาะช่วงนั้น (เช่น 09:00–15:00) เลี่ยงช่วงลูกค้าหนาแน่น · เว้นว่างทั้งคู่ = ใช้ได้ทั้งวัน</div>
                <h3 style="margin:16px 0 4px;font-size:14.5px">สินค้าที่ร่วมรายการ</h3>
                <div class="muted" style="font-size:12.5px;margin-bottom:6px">ต้นทุน: สินค้าของร้าน = ราคาทุน · ฝากขาย = ส่วนแบ่งที่ต้องจ่ายผู้ฝาก · กลุ่มขาย (▦) = ต้นทุนสูงสุดในกลุ่ม</div>
                <div data-items>${itemsHtml()}</div>
                <div class="field" style="margin-top:6px"><input type="search" data-search placeholder="ค้นหาชื่อสินค้า บาร์โค้ด หรือกลุ่มขาย เพื่อเพิ่ม" autocomplete="off" value="${escapeHtml(search.q)}"></div>
                ${resultsHtml()}
                <div data-warn>${warnHtml()}</div>
                <label class="field" style="margin-top:8px"><span>หมายเหตุ</span><input type="text" data-f="note" maxlength="500" value="${escapeHtml(f.note)}"></label>
                <label class="pos-switch" style="margin-top:8px"><input type="checkbox" data-f="active" ${f.active ? 'checked' : ''}><span aria-hidden="true"></span> เปิดใช้งาน</label>
                ${error ? `<div class="error-box" style="margin-top:8px">${escapeHtml(error)}</div>` : ''}
              </div>
              <div class="modal-foot"><button type="button" class="btn secondary" data-close>ยกเลิก</button>
                <button type="button" class="btn" data-save ${busy ? 'disabled' : ''}>${busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div></div>`;
            box.querySelector('.modal-body').scrollTop = scrolled;
            if (searching) {
                const inp = box.querySelector('[data-search]');
                inp.focus();
                inp.setSelectionRange?.(inp.value.length, inp.value.length);
            }
        }

        function runSearch(q) {
            search = { q, items: search.items, loading: !!q };
            clearTimeout(timer);
            draw();
            if (!q) return;
            timer = setTimeout(async () => {
                try {
                    const res = await api.get(`/point-redeems/items?q=${encodeURIComponent(q)}`);
                    if (search.q === q) search = { q, items: res.data, loading: false };
                } catch (err) {
                    search.loading = false;
                }
                if (box.isConnected) draw();
            }, 250);
        }

        box.addEventListener('input', (e) => {
            const t = e.target;
            if (t.dataset.search !== undefined) return runSearch(t.value.trim());
            if (t.dataset.f && t.type !== 'checkbox') {
                f[t.dataset.f] = t.value;
                if (['points', 'baht', 'max_discount'].includes(t.dataset.f)) refresh();
            }
        });
        box.addEventListener('change', (e) => { if (e.target.dataset.f === 'active') f.active = e.target.checked; });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.target.dataset.search !== undefined) {
                e.preventDefault();
                box.querySelector('[data-add]')?.click();
            }
        });
        box.addEventListener('click', async (e) => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.close !== undefined) return close();
            if (t.dataset.itemDel !== undefined) { f.items.splice(Number(t.dataset.itemDel), 1); draw(); return; }
            if (t.dataset.add !== undefined) {
                const it = search.items.find((x) => keyOf(x) === t.dataset.add);
                if (it) f.items.push({ ...it });
                search = { q: '', items: [], loading: false };
                draw();
                box.querySelector('[data-search]')?.focus();
                return;
            }
            if (t.dataset.save === undefined || busy) return;
            busy = true;
            error = null;
            draw();
            const body = {
                name: f.name, points: Number(f.points), baht: Number(f.baht), max_discount: f.max_discount === '' ? null : Number(f.max_discount),
                starts_on: f.starts_on, ends_on: f.ends_on, daily_from: f.daily_from, daily_to: f.daily_to, active: f.active, note: f.note,
                items: f.items.map((it) => (it.sale_group_id ? { sale_group_id: it.sale_group_id } : { product_id: it.product_id })),
            };
            try {
                const res = src ? await api.put(`/point-redeems/${src.id}`, body) : await api.post('/point-redeems', body);
                close();
                await load();
                const warnings = res.data?.warnings || [];
                if (warnings.length) alertDialog(`บันทึกแล้ว แต่ราคาหลังใช้แต้มต่ำกว่าต้นทุน:\n${warnings.join('\n')}`, { title: 'คำเตือน: ต่ำกว่าต้นทุน', tone: 'error' });
            } catch (err) {
                busy = false;
                error = err.message;
                draw();
            }
        });
        draw();
        box.querySelector('[data-f="name"]').focus();
    }

    await load();
}
