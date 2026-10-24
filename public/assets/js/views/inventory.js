import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';
import { openDetailModal } from '../detail-modal.js';

/** [key, header, tooltip, type] — type picks how the column sorts and which way it starts. */
const COLUMNS = [
    ['name', 'สินค้า', '', 'text'],
    ['barcode', 'บาร์โค้ดชิ้น', 'บาร์โค้ดบนตัวสินค้า 1 ชิ้น ใช้สแกนตอนขาย', 'text'],
    ['packs', 'บาร์โค้ดแพ็ค', 'บาร์โค้ดของแพ็ค/ลังที่ซื้อเข้า สินค้าเดียวกันมีได้หลายขนาดแพ็ค', 'text'],
    ['qty_received', 'รับเข้า', '', 'num'],
    ['qty_sold', 'ขายแล้ว', '', 'num'],
    ['qty_stock', 'คงเหลือ', '', 'num'],
    ['cost_price', 'ต้นทุน/ชิ้น', '', 'num'],
    ['sale_price', 'ราคาขาย', '', 'num'],
    ['margin_percent', 'กำไร %', '', 'num'],
    ['stock_cost_value', 'มูลค่าทุนคงเหลือ', '', 'num'],
    ['profit_total', 'กำไรสะสม', '', 'num'],
    ['last_purchase', 'ซื้อล่าสุด', '', 'date'],
];
const SORT_KEY = 'inventory.sort';
const FILTER_KEY = 'inventory.filter';
const FILTERS = [
    ['all', 'ทั้งหมด', () => true],
    ['nobarcode', 'ไม่มีบาร์โค้ด', (p) => !(p.barcode || '').trim()],
    ['nopic', 'ไม่มีรูป', (p) => !p.image_token],
    ['consign', 'สินค้าฝากขาย', (p) => p.consignor_id != null],
    ['out', 'สินค้าหมด (เหลือต่ำกว่า 1)', (p) => Number(p.qty_stock) < 1],
];
const thCollator = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });

export async function render(container, { navigate, permissions, user }) {
    let search = '';
    let res = null;
    // Barcode editor: piece barcode (scanned at the POS) + any number of pack barcodes.
    // Admins can also hand the product to a consignor (goods that skipped receiving).
    let editing = null; // { id, name, barcode, packs: [{ barcode, pack_size }], consign, consignorId, consignCost, error, busy }
    const canEdit = permissions.includes('purchase.create');
    const canConsign = canEdit && user?.role === 'admin';
    // Admin / manager: sales booked with no cost (sold before the first purchase) that can now be costed.
    const canFixCost = ['admin', 'manager'].includes(user?.role);
    let zeroCost = [];
    let consignors = null; // loaded the first time an admin opens the editor
    let sort = { key: 'name', dir: 'asc' };
    try {
        const saved = JSON.parse(localStorage.getItem(SORT_KEY) || 'null');
        if (saved && COLUMNS.some(([k]) => k === saved.key) && ['asc', 'desc'].includes(saved.dir)) sort = saved;
    } catch (e) { /* private mode / bad value */ }
    let filter = 'all';
    try {
        const saved = localStorage.getItem(FILTER_KEY);
        if (FILTERS.some(([k]) => k === saved)) filter = saved;
    } catch (e) { /* private mode */ }

    function sortValue(p, key) {
        if (key === 'packs') return p.pack_barcodes[0]?.barcode ?? '';
        return p[key];
    }

    /** Sorted copy; empty values (no barcode, never bought) always go last. */
    function sorted(list) {
        const [, , , type] = COLUMNS.find(([k]) => k === sort.key);
        const sign = sort.dir === 'asc' ? 1 : -1;
        return [...list].sort((a, b) => {
            const va = sortValue(a, sort.key);
            const vb = sortValue(b, sort.key);
            const ea = va === null || va === undefined || va === '';
            const eb = vb === null || vb === undefined || vb === '';
            if (ea || eb) return ea === eb ? thCollator.compare(a.name, b.name) : (ea ? 1 : -1);
            let c = type === 'num' ? Number(va) - Number(vb) : thCollator.compare(String(va), String(vb));
            if (c === 0) c = thCollator.compare(a.name, b.name) * sign;
            return c * sign;
        });
    }

    function headHtml() {
        return COLUMNS.map(([key, label, title, type]) => {
            const on = sort.key === key;
            const aria = on ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
            return `<th aria-sort="${aria}" class="${type === 'num' ? 'num' : ''}"${title ? ` title="${escapeHtml(title)}"` : ''}>
              <button type="button" class="th-sort ${on ? 'on' : ''}" data-sort="${key}">${escapeHtml(label)}<span class="th-arrow" aria-hidden="true">${on ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}</span></button></th>`;
        }).join('');
    }

    async function load() {
        const q = search ? `&q=${encodeURIComponent(search)}` : '';
        res = await api.get(`/inventory?consigned=1${q}`);
        if (canFixCost) zeroCost = (await api.get('/inventory/zero-cost').catch(() => ({ data: [] }))).data;
        draw();
    }

    function draw() {
        const s = res.summary;
        const canBuy = permissions.includes('purchase.create');
        container.innerHTML = `
        <div class="kpi-grid">
          <div class="kpi"><div class="lbl">มูลค่าสต๊อก (ต้นทุน)</div><div class="val">฿ ${money(s.stock_cost_value)}</div>
            <div class="sub">${count(s.stock_units)} ชิ้น · ${count(s.items)} รายการ${s.out_of_stock ? ` · หมด ${count(s.out_of_stock)}` : ''}</div></div>
          <div class="kpi"><div class="lbl">มูลค่าสต๊อก (ราคาขาย)</div><div class="val">฿ ${money(s.stock_retail_value)}</div>
            <div class="sub">กำไรที่คาดว่าจะได้ ฿ ${money(s.expected_profit)}</div></div>
          <div class="kpi"><div class="lbl">ยอดขายสะสม</div><div class="val">฿ ${money(s.sales_total)}</div>
            <div class="sub">${count(s.qty_sold)} ชิ้น · ต้นทุนขาย ฿ ${money(s.cogs_total)}</div></div>
          <div class="kpi"><div class="lbl">กำไรขั้นต้น</div><div class="val">฿ ${money(s.gross_profit)}</div>
            <div class="sub">หักค่าวัสดุใช้ในร้าน ฿ ${money(s.supply_cost)}${s.wage_cost ? ` · ค่าแรง ฿ ${money(s.wage_cost)}` : ''} = กำไรสุทธิ ฿ ${money(s.net_profit)}</div></div>
        </div>
        ${zeroCost.length ? zeroCostHtml() : ''}
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <input type="search" id="q" class="search-input" placeholder="🔍 ค้นหาชื่อหรือบาร์โค้ด (ชิ้น/แพ็ค) แล้วกด Enter" value="${escapeHtml(search)}">
            ${canBuy ? `<button type="button" class="btn" id="go-buy">ซื้อสินค้าเข้าร้าน</button>` : ''}
          </div>
          <div class="tabs" style="margin-top:12px" role="group" aria-label="กรองสินค้า">
            ${FILTERS.map(([k, l, fn]) => `<button type="button" class="tab-btn ${k === filter ? 'active' : ''}" data-filter="${k}">${l} (${count(res.data.filter(fn).length)})</button>`).join('')}
          </div>
          ${filter === 'all' || filter === 'consign' ? '<div class="muted" style="font-size:12px;margin-top:8px">ตัวเลขสรุปด้านบนเป็นของสินค้าของร้านเท่านั้น · สินค้าฝากขายแสดงเฉพาะล็อตที่ยังมีของเหลือ (ต้นทุน = ราคาที่ผู้ฝากได้, กำไร = ค่าฝากขายของร้าน)</div>' : ''}
        </section>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr>${headHtml()}</tr></thead>
            <tbody>${sorted(res.data.filter(FILTERS.find(([k]) => k === filter)[2])).map(row).join('')
                || `<tr><td colspan="12" class="empty">${filter === 'all' ? 'ยังไม่มีสินค้าของร้าน — เริ่มจากเมนู ซื้อสินค้าเข้าร้าน' : 'ไม่มีสินค้าในกลุ่มนี้'}</td></tr>`}</tbody>
          </table></div>
        </section>
        ${editing ? editHtml() : ''}`;

        const input = container.querySelector('#q');
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { search = input.value.trim(); load(); } });
        container.querySelectorAll('[data-sort]').forEach((b) => b.addEventListener('click', () => {
            const key = b.dataset.sort;
            const [, , , type] = COLUMNS.find(([k]) => k === key);
            // Same column flips; a new column starts A→ก for text, biggest/newest first for numbers and dates.
            sort = sort.key === key ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: type === 'text' ? 'asc' : 'desc' };
            try { localStorage.setItem(SORT_KEY, JSON.stringify(sort)); } catch (e) { /* private mode */ }
            draw();
        }));
        container.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => {
            filter = b.dataset.filter;
            try { localStorage.setItem(FILTER_KEY, filter); } catch (e) { /* private mode */ }
            draw();
        }));
        container.querySelectorAll('[data-claim]').forEach((b) => b.addEventListener('click', async () => {
            const p = res.data.find((x) => String(x.id) === b.dataset.claim);
            const ok = await confirmDialog(
                `ยอดขาย «${p.name}» ${count(p.store_sold_qty)} ชิ้นที่ขายก่อนเปลี่ยนเป็นสินค้าฝากขาย จะย้ายจากกำไรของร้านไปเป็นรายรับในบัญชีของ ${p.consignor_name} (หักค่าฝากขายแล้ว)`,
                { title: 'โอนยอดขายเดิมเข้าบัญชีผู้ฝาก?', okText: 'โอนยอดขาย' }
            );
            if (!ok) return;
            b.disabled = true;
            try {
                const r = (await api.post(`/inventory/${p.id}/claim-sales`)).data;
                await alertDialog(`โอนยอดขาย ${count(r.qty)} ชิ้น ฿${money(r.sale)} หักค่าฝากขาย ฿${money(r.fee)} เข้าบัญชี ${p.consignor_name} แล้ว`, { title: 'โอนยอดขายแล้ว' });
                await load();
            } catch (err) {
                b.disabled = false;
                alertDialog(err.message, { tone: 'error' });
            }
        }));
        container.querySelector('#zc-view')?.addEventListener('click', () => openDetailModal(async () => ({
            title: 'ยอดขายที่ต้นทุนเป็น 0',
            note: 'สินค้าเหล่านี้ขายไปก่อนบันทึกใบซื้อครั้งแรก ตอนขายยังไม่มีต้นทุน ระบบจึงบันทึกต้นทุนเป็น 0 ทำให้กำไรสูงเกินจริง ต้นทุนที่จะเติมคิดจากทุน/ชิ้นในใบซื้อแรกของสินค้านั้น',
            sections: [{ title: 'แยกตามสินค้า', columns: [['product', 'สินค้า', 'text'], ['first_purchase', 'ใบซื้อแรก', 'text'], ['qty', 'ชิ้นที่ขาย', 'qty'], ['sales', 'ยอดขาย', 'money'], ['piece_cost', 'ทุน/ชิ้น', 'money'], ['cost', 'ต้นทุนที่จะเติม', 'money']], rows: zeroCost }],
        })));
        container.querySelector('#zc-fill')?.addEventListener('click', fillZeroCost);
        const buy = container.querySelector('#go-buy');
        if (buy) buy.addEventListener('click', () => navigate('purchase'));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', async () => {
            const p = res.data.find((x) => String(x.id) === b.dataset.edit);
            const consigned = p.consignor_id != null;
            if (canConsign && !consigned && !consignors) consignors = (await api.get('/consignors/options').catch(() => ({ data: [] }))).data;
            editing = {
                id: p.id, name: p.name, barcode: p.barcode || '', packs: p.pack_barcodes.map((x) => ({ ...x })), consigned, consignorName: p.consignor_name,
                consign: false, consignorId: '', consignCost: String(Number(p.cost_price)), salePrice: Number(p.sale_price),
                pastQty: Number(p.store_sold_qty), claimPast: true,
                error: null, busy: false,
            };
            if (!consigned && !editing.packs.length) editing.packs.push({ barcode: '', pack_size: '' });
            draw();
            container.querySelector(consigned ? '#bc-piece' : '#bc-name')?.focus();
            if (!consigned) {
                const e = editing;
                const opts = (await api.get(`/inventory/${p.id}/name-options`).catch(() => ({ data: [] }))).data;
                if (editing === e && opts.length) {
                    e.nameOptions = opts;
                    const focused = document.activeElement?.id;
                    draw();
                    if (focused) container.querySelector(`#${focused}`)?.focus();
                }
            }
        }));
        if (editing) bindEdit();
    }

    function zeroCostHtml() {
        const qty = zeroCost.reduce((s, r) => s + Number(r.qty), 0);
        const cost = zeroCost.reduce((s, r) => s + Number(r.cost), 0);
        return `
        <div class="hint" style="align-items:center">
          <div class="dot">!</div>
          <div style="flex:1">มียอดขาย <b>${count(zeroCost.length)} สินค้า (${count(qty)} ชิ้น)</b> ที่ขายไปก่อนบันทึกใบซื้อ จึงบันทึกต้นทุนเป็น 0 —
            เติมต้นทุนย้อนหลังจากใบซื้อแรกได้ ฿ ${money(cost)} (กำไรขั้นต้นจะลดลงเท่ากัน)</div>
          <button type="button" class="btn secondary sm" id="zc-view">ดูรายการ</button>
          <button type="button" class="btn sm" id="zc-fill">เติมต้นทุนย้อนหลัง</button>
        </div>`;
    }

    async function fillZeroCost() {
        const cost = zeroCost.reduce((s, r) => s + Number(r.cost), 0);
        const ok = await confirmDialog(
            `ยอดขาย ${zeroCost.length} สินค้าที่ต้นทุนเป็น 0 จะได้ต้นทุนตามทุน/ชิ้นของใบซื้อแรก รวม ฿${money(cost)} กำไรขั้นต้นสะสมจะลดลงเท่ากัน (บันทึกในประวัติการแก้ไข)`,
            { title: 'เติมต้นทุนย้อนหลัง?', okText: 'เติมต้นทุน' }
        );
        if (!ok) return;
        try {
            const r = (await api.post('/inventory/zero-cost')).data;
            await load();
            alertDialog(`เติมต้นทุนให้ ${count(r.products)} สินค้า ${count(r.lines)} รายการขาย (${count(r.qty)} ชิ้น) รวม ฿${money(r.cost)} แล้ว`, { title: 'เติมต้นทุนย้อนหลังแล้ว' });
        } catch (e) { alertDialog(e.message, { tone: 'error' }); }
    }

    function row(p) {
        const low = Number(p.qty_stock) <= 3;
        return `<tr>
          <td>${escapeHtml(p.name)}${p.consignor_id != null ? `<div class="muted" style="font-size:12px">ฝากขาย: ${escapeHtml(p.consignor_name)}</div>` : ''}
            ${canConsign && p.consignor_id != null && Number(p.store_sold_qty) > 0
                ? `<button type="button" class="btn secondary sm" data-claim="${p.id}" style="margin-top:4px" title="ยอดขายนี้เกิดก่อนเปลี่ยนเป็นสินค้าฝากขาย จึงยังเป็นของร้าน">โอนยอดขายเดิม ${count(p.store_sold_qty)} ชิ้น เข้าบัญชีผู้ฝาก</button>` : ''}</td>
          <td class="muted">${p.barcode ? escapeHtml(p.barcode) : '<span title="ยังไม่มีบาร์โค้ดชิ้น">—</span>'}</td>
          <td style="white-space:nowrap">
            ${p.pack_barcodes.map((b) => `<div class="muted" style="font-size:12px">${escapeHtml(b.barcode)} <span class="pill muted">×${b.pack_size}</span></div>`).join('')}
            ${canEdit && p.consignor_id == null ? `<button type="button" class="btn secondary sm" data-edit="${p.id}" title="แก้ไขชื่อสินค้า บาร์โค้ดชิ้น/แพ็ค">แก้ไข</button>` : ''}
            ${canConsign && p.consignor_id != null ? `<button type="button" class="btn secondary sm" data-edit="${p.id}" title="แก้ไขบาร์โค้ดชิ้นของสินค้าฝากขาย">แก้ไข</button>` : ''}
          </td>
          <td class="num">${count(p.qty_received)}</td>
          <td class="num">${count(p.qty_sold)}</td>
          <td class="num" style="${low ? 'color:var(--warn);font-weight:600' : ''}">${count(p.qty_stock)}</td>
          <td class="num">${money(p.cost_price)}</td>
          <td class="num">${money(p.sale_price)}</td>
          <td class="num">${p.margin_percent}%</td>
          <td class="num">${money(p.stock_cost_value)}</td>
          <td class="num">${money(p.profit_total)}</td>
          <td class="muted">${fmtDate(p.last_purchase)}</td>
        </tr>`;
    }

    function editHtml() {
        const e = editing;
        return `
        <div class="modal-backdrop">
          <div class="modal" role="dialog" aria-modal="true" style="width:560px">
            <div class="modal-head">
              <h2>${e.consigned ? 'แก้ไขบาร์โค้ดสินค้าฝากขาย' : 'แก้ไขสินค้า'}</h2>
              <button type="button" class="modal-close" data-close-edit title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              ${e.consigned ? `<div style="margin-bottom:16px"><div style="font-weight:600">${escapeHtml(e.name)}</div>
                <div class="muted" style="font-size:12.5px">ฝากขาย: ${escapeHtml(e.consignorName)} · สินค้าฝากขายใช้ได้เฉพาะบาร์โค้ดชิ้น</div></div>`
                : `<label class="field" style="display:block;margin-bottom:16px">ชื่อสินค้า (ชื่อที่ใช้ขายต่อชิ้น)
                <input id="bc-name" value="${escapeHtml(e.name)}" maxlength="191" style="width:100%">
              </label>${nameOptionsHtml(e)}`}
              <label class="field" style="display:block;margin-bottom:16px">บาร์โค้ดชิ้น (สแกนตอนขาย)
                <input id="bc-piece" value="${escapeHtml(e.barcode)}" placeholder="สแกนบาร์โค้ดบนตัวสินค้า 1 ชิ้น" style="width:100%">
              </label>
              ${e.consigned ? '' : packsHtml(e)}
              ${canConsign && !e.consigned ? consignHtml(e) : ''}
              ${e.error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(e.error)}</div>` : ''}
            </div>
            <div class="modal-foot"><button type="button" class="btn secondary" data-close-edit>ยกเลิก</button>
              <button type="button" class="btn" id="bc-save" ${e.busy ? 'disabled' : ''}>${e.busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div>
          </div>
        </div>`;
    }

    /** Names this product goes by on receipts / in the POS, offered as a replacement for a mistyped name. */
    function nameOptionsHtml(e) {
        if (!e.nameOptions?.length) return '';
        const toggle = `<button type="button" class="name-opts-toggle" data-name-toggle aria-expanded="${e.namesOpen ? 'true' : 'false'}">
                ${e.namesOpen ? '▾' : '▸'} ชื่ออื่น ๆ ในระบบ (${e.nameOptions.length})</button>`;
        if (!e.namesOpen) return `<div class="name-opts">${toggle}</div>`;
        return `
              <div class="name-opts">
                ${toggle}
                <div class="muted" style="font-size:12.5px;margin-bottom:2px">ชื่อของสินค้าตัวนี้จากใบเสร็จ/POS — กดเพื่อใช้แทนชื่อปัจจุบัน</div>
                ${e.nameOptions.map((o, i) => `<button type="button" class="name-opt ${o.name === e.name ? 'on' : ''}" data-name-opt="${i}">
                  <span class="name-opt-name">${escapeHtml(o.name)}</span>
                  <span class="name-opt-src">${o.trimmed ? 'ตัดขนาดแพ็คออก · ' : ''}${o.sources.map((s) => `${escapeHtml(s.name)}${s.times > 1 ? ` ×${s.times}` : ''}`).join(', ')}${o.last_date ? ` · ล่าสุด ${fmtDate(o.last_date)}` : ''}</span>
                </button>`).join('')}
              </div>`;
    }

    function packsHtml(e) {
        return `
              <div style="font-weight:600;margin-bottom:4px">บาร์โค้ดแพ็ค/ลัง</div>
              <div class="muted" style="font-size:12.5px;margin-bottom:8px">สินค้าเดียวกันอาจมาหลายแพ็ค (เช่น 24, 30, 50 ชิ้น) แต่ละแพ็คมีบาร์โค้ดของตัวเอง
                ระบบใช้จับคู่สินค้าและเติมจำนวนชิ้น/แพ็คให้ตอนซื้อเข้า</div>
              <table><thead><tr><th>บาร์โค้ดแพ็ค</th><th class="num">ชิ้น/แพ็ค</th><th></th></tr></thead><tbody>
                ${e.packs.map((p, i) => `<tr data-pack="${i}">
                  <td><input data-pf="barcode" value="${escapeHtml(p.barcode)}" style="width:100%"></td>
                  <td><input data-pf="pack_size" type="number" min="2" step="1" value="${escapeHtml(p.pack_size)}" class="num" style="width:80px"></td>
                  <td><button type="button" class="btn secondary sm" data-del-pack="${i}" title="ลบ">✕</button></td>
                </tr>`).join('')}
              </tbody></table>
              <button type="button" class="btn secondary sm" id="bc-add" style="margin-top:8px">+ เพิ่มบาร์โค้ดแพ็ค</button>`;
    }

    /** Admin section: turn this store product into a consignor's product. */
    function consignHtml(e) {
        const cost = Number(e.consignCost);
        const fee = e.salePrice - cost;
        return `
          <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
            <label style="display:flex;gap:8px;align-items:center;font-weight:600;cursor:pointer">
              <input type="checkbox" id="cs-on" ${e.consign ? 'checked' : ''}> เปลี่ยนเป็นสินค้าฝากขาย <span class="pill muted">ผู้ดูแลระบบ</span>
            </label>
            <div class="muted" style="font-size:12.5px;margin-top:4px">สำหรับสินค้าของผู้ฝากที่ไม่ได้ผ่านขั้นตอนรับฝาก — ยอดขายต่อจากนี้จะเข้าบัญชีผู้ฝาก ยอดขายก่อนหน้ายังเป็นของร้าน</div>
            ${e.consign ? `
              <div class="form-grid" style="margin-top:10px">
                <label class="field" style="grid-column:span 2">ผู้ฝากขาย *
                  <select id="cs-consignor"><option value="">— เลือกผู้ฝากขาย —</option>
                    ${(consignors || []).map((o) => `<option value="${o.id}" ${String(o.id) === String(e.consignorId) ? 'selected' : ''}>${escapeHtml(o.code)} - ${escapeHtml(o.name)}</option>`).join('')}
                  </select>
                </label>
                <label class="field">ราคาที่ผู้ฝากได้/ชิ้น (บาท) *
                  <input id="cs-cost" type="number" min="0" step="0.25" max="${e.salePrice}" value="${escapeHtml(e.consignCost)}">
                </label>
                <div class="field">ค่าฝากขายของร้าน/ชิ้น
                  <div class="price-preview" id="cs-fee">${Number.isFinite(fee) && fee >= 0 ? `฿ ${money(fee)}` : '-'}</div>
                </div>
              </div>
              <div class="muted" style="font-size:12.5px;margin-top:6px">ราคาขายคงเดิม ฿${money(e.salePrice)} · ไม่คิดค่าทำข้อมูล</div>
              ${e.pastQty > 0 ? `<label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-size:13px;cursor:pointer">
                <input type="checkbox" id="cs-past" ${e.claimPast ? 'checked' : ''}>
                <span>โอนยอดขายที่ขายไปแล้ว ${count(e.pastQty)} ชิ้น เข้าบัญชีผู้ฝากด้วย
                  <span class="muted" style="display:block;font-size:12px">ยอดขายเดิมจะย้ายจากกำไรของร้านไปเป็นรายรับของผู้ฝาก (หักค่าฝากขายแล้ว)</span></span>
              </label>` : ''}` : ''}
          </div>`;
    }

    function bindEdit() {
        const e = editing;
        container.querySelector('#cs-on')?.addEventListener('change', (ev) => { e.consign = ev.target.checked; draw(); });
        container.querySelector('#cs-consignor')?.addEventListener('change', (ev) => { e.consignorId = ev.target.value; });
        container.querySelector('#cs-past')?.addEventListener('change', (ev) => { e.claimPast = ev.target.checked; });
        container.querySelector('#cs-cost')?.addEventListener('input', (ev) => {
            e.consignCost = ev.target.value;
            const fee = e.salePrice - Number(ev.target.value);
            container.querySelector('#cs-fee').textContent = ev.target.value !== '' && fee >= 0 ? `฿ ${money(fee)}` : '-';
        });
        container.querySelector('#bc-name')?.addEventListener('input', (ev) => {
            e.name = ev.target.value;
            container.querySelectorAll('[data-name-opt]').forEach((b) => b.classList.toggle('on', e.nameOptions[Number(b.dataset.nameOpt)].name === e.name));
        });
        container.querySelector('[data-name-toggle]')?.addEventListener('click', () => {
            e.namesOpen = !e.namesOpen;
            draw();
        });
        container.querySelectorAll('[data-name-opt]').forEach((b) => b.addEventListener('click', () => {
            e.name = e.nameOptions[Number(b.dataset.nameOpt)].name;
            draw();
            container.querySelector('#bc-name')?.focus();
        }));
        container.querySelector('#bc-piece').addEventListener('input', (ev) => { e.barcode = ev.target.value; });
        container.querySelectorAll('tr[data-pack]').forEach((tr) => {
            const p = e.packs[Number(tr.dataset.pack)];
            tr.querySelectorAll('[data-pf]').forEach((inp) => inp.addEventListener('input', () => { p[inp.dataset.pf] = inp.value; }));
        });
        container.querySelectorAll('[data-del-pack]').forEach((b) => b.addEventListener('click', () => {
            e.packs.splice(Number(b.dataset.delPack), 1);
            draw();
        }));
        container.querySelector('#bc-add')?.addEventListener('click', () => {
            e.packs.push({ barcode: '', pack_size: '' });
            draw();
            const inputs = container.querySelectorAll('[data-pf="barcode"]');
            inputs[inputs.length - 1]?.focus();
        });
        container.querySelectorAll('[data-close-edit]').forEach((b) => b.addEventListener('click', () => { editing = null; draw(); }));
        container.querySelector('#bc-save').addEventListener('click', async () => {
            if (!e.name.trim()) { e.error = 'กรุณากรอกชื่อสินค้า'; draw(); return; }
            let consignor = null;
            if (e.consign) {
                consignor = (consignors || []).find((o) => String(o.id) === String(e.consignorId));
                const cost = Number(e.consignCost);
                if (!consignor) { e.error = 'กรุณาเลือกผู้ฝากขาย'; draw(); return; }
                if (e.consignCost === '' || !(cost >= 0) || cost > e.salePrice) {
                    e.error = `ราคาที่ผู้ฝากได้ต้องอยู่ระหว่าง 0 ถึง ${money(e.salePrice)} บาท`;
                    draw();
                    return;
                }
                const ok = await confirmDialog(
                    `«${e.name.trim()}» จะย้ายไปเป็นสินค้าฝากขายของ ${consignor.code} - ${consignor.name} และหายไปจากรายการสต๊อกสินค้าของร้าน`,
                    { title: 'เปลี่ยนเป็นสินค้าฝากขาย?', okText: 'เปลี่ยนเป็นสินค้าฝากขาย' }
                );
                if (!ok) return;
            }
            e.busy = true; e.error = null; draw();
            try {
                const out = await api.put(`/inventory/${e.id}/barcodes`, {
                    name: e.name.trim(),
                    barcode: e.barcode.trim(),
                    pack_barcodes: e.packs
                        .filter((p) => String(p.barcode).trim())
                        .map((p) => ({ barcode: String(p.barcode).trim(), pack_size: Number(p.pack_size) })),
                });
                const p = res.data.find((x) => x.id === e.id);
                p.name = out.data.name;
                p.barcode = out.data.barcode;
                p.pack_barcodes = out.data.pack_barcodes;
                if (consignor) {
                    await api.post(`/inventory/${e.id}/consign`, {
                        consignor_id: consignor.id, cost_price: Number(e.consignCost), include_past_sales: e.pastQty > 0 && e.claimPast,
                    });
                    editing = null;
                    await load(); // the product left the store's stock: refresh list and totals
                    return;
                }
                editing = null;
            } catch (err) {
                e.busy = false;
                e.error = err.message;
            }
            draw();
        });
    }

    await load();
}
