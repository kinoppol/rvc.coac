import { api } from './api.js';
import { money, count, escapeHtml, fmtDate } from './util.js';
import { alertDialog, confirmDialog, promptDialog, choiceDialog } from './dialog.js';
import { skBlock } from './skeleton.js';

// ทะเบียนผู้จำหน่ายสินค้า / รายชื่อร้าน/ผู้ขาย: one supplier typed several ways on purchases is
// merged under one main name; the other spellings become its aliases (ชื่อเล่น). Purchases keep
// the name they were typed with; prices, orders and the name list count them as one supplier.
// The same manager shows as a page (views/suppliers.js) and as a modal from the purchase pages.

const thCollator = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });

/** Suppliers as the name box suggests them: main name, tax id, aliases, most recently bought from first. */
let cache = null;
export async function supplierOptions(fresh = false) {
    if (!cache || fresh) {
        cache = api.get('/suppliers').then((r) => r.data
            .map((s) => ({ name: s.name, tax_id: s.tax_id || '', aliases: s.aliases.map((a) => a.alias), last_date: s.last_date, docs: s.docs }))
            .sort((a, b) => String(b.last_date || '').localeCompare(String(a.last_date || '')) || thCollator.compare(a.name, b.name)))
            .catch((e) => { cache = null; throw e; });
    }
    return cache;
}

// Words that say what kind of business it is or which branch, left out when looking for names
// that are probably the same supplier.
const NOISE = [
    'บริษัท', 'บจก.', 'บจก', 'บมจ.', 'บมจ', 'จำกัด', '(มหาชน)', 'มหาชน', 'ห้างหุ้นส่วนจำกัด', 'ห้างหุ้นส่วนสามัญ', 'ห้างหุ้นส่วน', 'หจก.', 'หจก',
    'สำนักงานใหญ่', '(สำนักงานใหญ่)', 'ร้าน', 'company', 'limited', 'public', 'co.,', 'co.', 'ltd.', 'ltd', 'plc', 'inc.', 'inc',
];
function likeKey(name) {
    let s = ` ${String(name).toLowerCase()} `;
    s = s.replace(/สาขา\S*/gu, ' ').replace(/branch\s*\S*/gu, ' ');
    NOISE.forEach((w) => { s = s.split(w).join(' '); });
    return s.replace(/[\s.,()\-_'"/]+/gu, '');
}

/** Groups of names that are probably one supplier: the same tax id, or the same name once business words are left out. */
function suggestions(list) {
    const groups = new Map();
    const add = (key, s) => { if (!key) return; if (!groups.has(key)) groups.set(key, new Set()); groups.get(key).add(s.name); };
    list.forEach((s) => {
        const tax = String(s.tax_id || '').replace(/\D/g, '');
        if (tax.length >= 10) add(`tax:${tax}`, s);
        const k = likeKey(s.name);
        if (k.length >= 3) add(`name:${k}`, s);
    });
    // One list per set of names (a pair found by both tax id and name shows once).
    const seen = new Set();
    const out = [];
    for (const [key, names] of groups) {
        if (names.size < 2) continue;
        const sorted = [...names].sort((a, b) => thCollator.compare(a, b));
        const id = sorted.join('\u0001');
        if (seen.has(id)) continue;
        seen.add(id);
        out.push({ names: sorted, why: key.startsWith('tax:') ? 'เลขผู้เสียภาษีเดียวกัน' : 'ชื่อคล้ายกัน' });
    }
    return out;
}

/**
 * Draws the supplier manager into `host`. asPage: a page of its own (no modal frame);
 * otherwise host is the .modal element. onClose (modal only) closes it.
 */
export async function mountSupplierManager(host, { asPage = false, onChange = () => {}, onClose = null } = {}) {
    let res;
    let q = '';
    let sort = 'name'; // name | docs | last | amount
    const picked = new Set(); // main names ticked to merge
    const skipped = new Set(); // suggestion groups set aside this visit
    let editing = null; // { name, newName, taxId }
    let busy = false;

    async function load() {
        res = await api.get('/suppliers');
        [...picked].forEach((n) => { if (!res.data.some((s) => s.name === n)) picked.delete(n); });
    }
    try {
        await load();
    } catch (e) {
        host.innerHTML = `<div class="error-box">${escapeHtml(e.message)}</div>`;
        return;
    }

    const matches = (s) => {
        if (!q) return true;
        const t = q.toLowerCase();
        return s.name.toLowerCase().includes(t) || (s.tax_id || '').includes(q) || s.aliases.some((a) => a.alias.toLowerCase().includes(t));
    };
    const SORTS = {
        name: (a, b) => thCollator.compare(a.name, b.name),
        docs: (a, b) => b.docs - a.docs || thCollator.compare(a.name, b.name),
        last: (a, b) => String(b.last_date || '').localeCompare(String(a.last_date || '')) || thCollator.compare(a.name, b.name),
        amount: (a, b) => Number(b.amount) - Number(a.amount) || thCollator.compare(a.name, b.name),
    };

    async function run(fn) {
        if (busy) return;
        busy = true;
        draw();
        try {
            await fn();
            cache = null;
            onChange();
            await load();
        } catch (e) {
            alertDialog(e.message, { tone: 'error' });
        }
        busy = false;
        draw();
    }

    function rowHtml(s) {
        const edit = res.can_edit && res.ready;
        const isEditing = editing && editing.name === s.name;
        const main = isEditing
            ? `<div style="display:flex;flex-direction:column;gap:6px">
                 <input data-e-name value="${escapeHtml(editing.newName)}" aria-label="ชื่อหลัก" placeholder="ชื่อหลัก">
                 <input data-e-tax value="${escapeHtml(editing.taxId)}" aria-label="เลขประจำตัวผู้เสียภาษี" placeholder="เลขประจำตัวผู้เสียภาษี">
                 <div class="actions-row" style="gap:6px"><button type="button" class="btn sm" data-e-save>บันทึก</button><button type="button" class="btn secondary sm" data-e-cancel>ยกเลิก</button></div>
                 <div class="muted" style="font-size:11.5px">เปลี่ยนชื่อหลักแล้ว ชื่อเดิมจะเก็บไว้เป็นชื่อเล่น</div>
               </div>`
            : `<b>${escapeHtml(s.name)}</b>${s.tax_id ? `<div class="muted" style="font-size:12px">เลขผู้เสียภาษี ${escapeHtml(s.tax_id)}</div>` : ''}
               ${edit ? `<div><button type="button" class="link-btn" data-edit="${escapeHtml(s.name)}" style="font-size:12px">แก้ไขชื่อหลัก / เลขผู้เสียภาษี</button></div>` : ''}`;
        return `<tr>
          ${edit ? `<td><input type="checkbox" data-pick="${escapeHtml(s.name)}" ${picked.has(s.name) ? 'checked' : ''} aria-label="เลือก ${escapeHtml(s.name)}"></td>` : ''}
          <td style="min-width:200px">${main}</td>
          <td><div class="sp-aliases">${s.aliases.map((a) => `<span class="pill muted sp-alias" title="${a.docs ? `ใบซื้อในชื่อนี้ ${a.docs} ใบ` : 'ยังไม่มีใบซื้อในชื่อนี้'}">${escapeHtml(a.alias)}${a.docs ? ` <small>(${count(a.docs)})</small>` : ''}${edit ? ` <button type="button" class="sp-x" data-unalias="${a.id}" data-alias="${escapeHtml(a.alias)}" aria-label="เอาชื่อเล่น ${escapeHtml(a.alias)} ออก" title="เอาออก: ชื่อนี้กลับไปเป็นผู้ขายแยก">✕</button>` : ''}</span>`).join('')}
            ${edit ? `<button type="button" class="btn secondary sm" data-alias-add="${escapeHtml(s.name)}">+ ชื่อเล่น</button>` : ''}
            ${!s.aliases.length && !edit ? '<span class="muted">-</span>' : ''}</div></td>
          <td class="num">${count(s.docs)}</td>
          <td style="white-space:nowrap">${s.last_date ? escapeHtml(fmtDate(s.last_date)) : '<span class="muted">-</span>'}</td>
          <td class="num">${money(s.amount)}</td>
        </tr>`;
    }

    function suggestHtml(edit) {
        if (!edit) return '';
        const list = suggestions(res.data).filter((g) => !skipped.has(g.names.join('\u0001')));
        if (!list.length) return '';
        const docs = (n) => res.data.find((s) => s.name === n)?.docs || 0;
        return `<div class="sp-suggest">
          <div style="font-weight:600;margin-bottom:6px">ชื่อที่อาจเป็นผู้ขายรายเดียวกัน (${count(list.length)} กลุ่ม)
            <span class="muted" style="font-weight:400;font-size:12.5px"> — ตรวจก่อนรวม ชื่อที่ต่างสาขาหรือต่างบริษัทอาจคล้ายกันได้</span></div>
          ${list.map((g) => `<div class="sp-suggest-row">
              <span class="pill ${g.why === 'เลขผู้เสียภาษีเดียวกัน' ? 'ok' : 'muted'}" style="flex:none">${g.why}</span>
              <span class="sp-suggest-names">${g.names.map((n) => `<b>${escapeHtml(n)}</b> <small class="muted">(${count(docs(n))} ใบ)</small>`).join(' · ')}</span>
              <span style="flex:none;display:flex;gap:6px"><button type="button" class="btn sm" data-sg-merge="${escapeHtml(g.names.join('\u0001'))}" ${busy ? 'disabled' : ''}>รวมเป็นรายเดียว</button>
              <button type="button" class="btn secondary sm" data-sg-skip="${escapeHtml(g.names.join('\u0001'))}" title="ไม่ใช่รายเดียวกัน: ซ่อนไว้จนกว่าจะเปิดหน้านี้ใหม่">ไม่ใช่</button></span>
            </div>`).join('')}
        </div>`;
    }

    function bodyHtml() {
        const shown = res.data.filter(matches).sort(SORTS[sort]);
        const edit = res.can_edit && res.ready;
        const th = (k, label, cls = '') => `<th class="${cls} sp-sort${sort === k ? ' active' : ''}" data-sort="${k}" title="กดเพื่อเรียงตามคอลัมน์นี้">${label}${sort === k ? ' ▼' : ''}</th>`;
        return `
            <div class="hint"><div class="dot">i</div><div>ผู้ขายรายเดียวกันที่บันทึกไว้หลายชื่อ (สะกดต่างกัน ภาษาไทย/อังกฤษ มีสาขา) ติ๊กเลือกแล้วกด <b>รวมรายชื่อที่เลือก</b> เลือกชื่อหลัก ชื่ออื่นจะเก็บเป็น<b>ชื่อเล่น</b> ·
              ใบซื้อเดิมยังแสดงชื่อตามที่บันทึก แต่การเปรียบเทียบราคา ร่างใบสั่งซื้อ ตัวกรองผู้ขาย การค้นหา และรายงานจะนับเป็นผู้ขายรายเดียวกัน · เพิ่มชื่อเล่นไว้ล่วงหน้าได้ (เช่น ชื่อที่พิมพ์บนใบเสร็จ) · เอาชื่อเล่นออก (✕) เพื่อแยกกลับได้</div></div>
            ${!res.ready ? '<div class="error-box" style="margin-top:10px">ยังรวมรายชื่อ/เพิ่มชื่อเล่นไม่ได้ — ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0074) ก่อน</div>' : ''}
            ${res.ready && !res.can_edit ? '<div class="muted" style="font-size:12.5px;margin-top:8px">รวมรายชื่อและแก้ไขชื่อเล่นได้เฉพาะผู้ดูแลระบบและผู้จัดการร้าน</div>' : ''}
            ${suggestHtml(edit)}
            <div class="actions-row" style="justify-content:space-between;margin-top:12px">
              <input type="search" id="sp-q" class="search-input" placeholder="🔍 ค้นหาชื่อ ชื่อเล่น หรือเลขผู้เสียภาษี" value="${escapeHtml(q)}">
              ${edit ? `<button type="button" class="btn" id="sp-merge" ${picked.size > 1 && !busy ? '' : 'disabled'}>รวมรายชื่อที่เลือก (${picked.size})</button>` : ''}
            </div>
            ${picked.size ? `<div class="muted" style="font-size:12.5px;margin-top:6px">เลือกไว้: ${[...picked].map(escapeHtml).join(' · ')} <button type="button" class="link-btn" id="sp-clear" style="font-size:12.5px">ล้าง</button></div>` : ''}
            <div class="table-wrap" style="${asPage ? '' : 'max-height:460px;'}border:1px solid var(--border);border-radius:10px;margin-top:10px"><table>
              <thead><tr>${edit ? '<th></th>' : ''}${th('name', 'ชื่อหลัก')}<th>ชื่อเล่น (ชื่ออื่นของผู้ขายรายนี้)</th>${th('docs', 'ใบซื้อ', 'num')}${th('last', 'ซื้อล่าสุด')}${th('amount', 'ยอดซื้อรวม', 'num')}</tr></thead>
              <tbody>${shown.map(rowHtml).join('') || `<tr><td colspan="${edit ? 6 : 5}" class="empty">${res.data.length ? 'ไม่พบชื่อที่ค้นหา' : 'ยังไม่มีผู้ขาย'}</td></tr>`}</tbody>
            </table></div>
            <div class="muted" style="font-size:12px;margin-top:6px">${count(res.data.length)} ราย · ใบซื้อและยอดซื้อไม่รวมใบที่ยกเลิก (รวมร่างและใบสั่งซื้อ)</div>`;
    }

    function draw() {
        host.innerHTML = asPage
            ? `<section class="card card-pad">${bodyHtml()}</section>`
            : `<div class="modal-head"><h2>รายชื่อร้าน/ผู้ขาย</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
               <div class="modal-body" style="padding-top:12px">${bodyHtml()}</div>
               <div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>`;
        wire();
    }

    function wire() {
        host.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', () => onClose?.()));
        const search = host.querySelector('#sp-q');
        search.addEventListener('input', () => {
            q = search.value.trim();
            const at = search.selectionStart;
            draw();
            const again = host.querySelector('#sp-q');
            again.focus();
            again.setSelectionRange(at, at);
        });
        host.querySelectorAll('[data-sort]').forEach((t) => t.addEventListener('click', () => { sort = t.dataset.sort; draw(); }));
        host.querySelector('#sp-clear')?.addEventListener('click', () => { picked.clear(); draw(); });
        host.querySelectorAll('[data-pick]').forEach((c) => c.addEventListener('change', () => {
            if (c.checked) picked.add(c.dataset.pick); else picked.delete(c.dataset.pick);
            draw();
        }));
        host.querySelector('#sp-merge')?.addEventListener('click', () => merge([...picked]));
        host.querySelectorAll('[data-sg-merge]').forEach((b) => b.addEventListener('click', () => merge(b.dataset.sgMerge.split('\u0001'))));
        host.querySelectorAll('[data-sg-skip]').forEach((b) => b.addEventListener('click', () => { skipped.add(b.dataset.sgSkip); draw(); }));
        host.querySelectorAll('[data-alias-add]').forEach((b) => b.addEventListener('click', async () => {
            const name = b.dataset.aliasAdd;
            const alias = await promptDialog(`ชื่ออื่นที่ใช้เรียก «${name}» เช่น ชื่อที่พิมพ์บนใบเสร็จ ชื่อภาษาอังกฤษ หรือชื่อย่อ`, {
                title: 'เพิ่มชื่อเล่นผู้ขาย', okText: 'เพิ่ม', placeholder: 'ชื่อเล่น', required: 'ใส่ชื่อเล่น',
            });
            if (alias === null || !alias.trim()) return;
            run(() => api.post('/suppliers/aliases', { name, alias: alias.trim() }));
        }));
        host.querySelectorAll('[data-unalias]').forEach((b) => b.addEventListener('click', async () => {
            const ok = await confirmDialog(`เอา «${b.dataset.alias}» ออกจากชื่อเล่น ใบซื้อที่บันทึกด้วยชื่อนี้จะกลับไปนับเป็นผู้ขายแยก`, { title: 'เอาชื่อเล่นออก?', okText: 'เอาออก' });
            if (ok) run(() => api.del(`/suppliers/aliases/${b.dataset.unalias}`));
        }));
        host.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
            const s = res.data.find((x) => x.name === b.dataset.edit);
            editing = { name: s.name, newName: s.name, taxId: s.tax_id || '' };
            draw();
            host.querySelector('[data-e-name]')?.focus();
        }));
        if (editing) {
            host.querySelector('[data-e-name]')?.addEventListener('input', (e) => { editing.newName = e.target.value; });
            host.querySelector('[data-e-tax]')?.addEventListener('input', (e) => { editing.taxId = e.target.value; });
            host.querySelector('[data-e-cancel]')?.addEventListener('click', () => { editing = null; draw(); });
            host.querySelector('[data-e-save]')?.addEventListener('click', () => {
                const e = editing;
                if (!e.newName.trim()) { alertDialog('ใส่ชื่อหลัก', { tone: 'error' }); return; }
                editing = null;
                run(() => api.post('/suppliers/update', { name: e.name, new_name: e.newName.trim(), tax_id: e.taxId.trim() }));
            });
        }
    }

    /** Merges the names under one main name the user picks (the most-used offered first). */
    async function merge(names) {
        if (names.length < 2) return;
        const docs = (n) => res.data.find((s) => s.name === n)?.docs || 0;
        names = [...names].sort((a, b) => docs(b) - docs(a));
        const main = await choiceDialog('เลือกชื่อหลักของผู้ขายรายนี้ ชื่ออื่นที่เลือกไว้ (และชื่อเล่นของชื่อเหล่านั้น) จะเก็บเป็นชื่อเล่น', names.map((n) => ({
            key: n, label: `${n} (${count(docs(n))} ใบ)`,
        })), { title: `รวม ${names.length} ชื่อเป็นผู้ขายรายเดียว` });
        if (!main) return;
        run(async () => {
            await api.post('/suppliers/merge', { main, names: names.filter((n) => n !== main) });
            names.forEach((n) => picked.delete(n));
        });
    }

    draw();
}

export async function openSupplierModal({ onChange } = {}) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:980px"><div class="modal-body">' + skBlock(6, 6) + '</div></div>';
    document.body.appendChild(box);
    let changed = false;
    const close = () => {
        box.remove();
        document.removeEventListener('keydown', onKey);
        if (changed) onChange?.();
    };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);
    await mountSupplierManager(box.querySelector('.modal'), { onChange: () => { changed = true; }, onClose: close });
    // Could not load: the error shows in the modal with no close button, so add one.
    if (!box.querySelector('[data-x]')) {
        box.querySelector('.modal').insertAdjacentHTML('beforeend', '<div class="modal-foot"><button type="button" class="btn secondary" data-x>ปิด</button></div>');
        box.querySelector('[data-x]').addEventListener('click', close);
    }
}
