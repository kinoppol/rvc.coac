import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';
import { alertDialog, confirmDialog } from '../dialog.js';
import { printPurchaseList } from '../print-slip.js';

// รายการซื้อสินค้าใกล้หมด: the store's goods at or below the low-stock line (set on the stock
// page), each with every supplier it was bought from and how far apart their costs per piece
// are. One supplier is picked per product (the cheapest by default); the list prints as a
// purchase list grouped by supplier, or goes onto draft purchase orders.

const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
const NO_SUPPLIER = 'ยังไม่ระบุผู้ขาย';

export async function render(container, { navigate, user }) {
    let threshold = 10;
    let lines = []; // { p, on, sup (index | -1 = no supplier), qty, unit_cost }
    let q = '';
    let show = 'all'; // all | multi | nosup
    let splitPages = true;
    let busy = false;

    async function load() {
        const res = await api.get('/inventory/low-stock');
        threshold = Number(res.threshold);
        lines = res.data.map((p) => {
            const s = p.suppliers[0];
            return {
                p,
                // Already on a draft / an order waiting for delivery: left out unless ticked.
                on: !p.pending.length,
                sup: s ? 0 : -1,
                qty: s ? Math.max(1, Number(s.qty) || 1) : suggestPieces(p),
                unit_cost: s ? Number(s.unit_cost) : Number(p.cost_price) || 0,
            };
        });
        draw();
    }

    /** No supplier yet: enough pieces to get back above the line (or what sold in 30 days). */
    function suggestPieces(p) {
        return Math.max(1, Math.ceil(Math.max(threshold + 1, Number(p.sold_30d) || 0) - Math.max(0, Number(p.qty_stock))));
    }

    const supplierOf = (l) => (l.sup >= 0 ? l.p.suppliers[l.sup] : null);
    const packOf = (l) => Number(supplierOf(l)?.pack_size) || 1;
    const pieceCost = (l) => (l.unit_cost > 0 ? l.unit_cost / packOf(l) : 0);
    const lineTotal = (l) => round2(l.qty * l.unit_cost);
    const chosen = () => lines.filter((l) => l.on && l.qty > 0);

    /** Cheapest and dearest last price per piece among the product's suppliers. */
    function spread(p) {
        const c = p.suppliers.map((s) => Number(s.piece_cost));
        return c.length ? { min: Math.min(...c), max: Math.max(...c) } : null;
    }

    /** Ticked lines grouped by supplier name, in the order they first appear; no supplier last. */
    function groups() {
        const out = new Map();
        chosen().forEach((l) => {
            const s = supplierOf(l);
            const name = s ? s.supplier_name : NO_SUPPLIER;
            if (!out.has(name)) out.set(name, { supplier_name: name, supplier_tax_id: s?.supplier_tax_id || '', known: !!s, lines: [] });
            out.get(name).lines.push(l);
        });
        return [...out.values()].sort((a, b) => Number(b.known) - Number(a.known));
    }

    /** What the ticked lines save against buying each from its dearest supplier (multi-supplier only). */
    function saving() {
        return round2(chosen().reduce((t, l) => {
            const s = supplierOf(l);
            const r = spread(l.p);
            if (!s || !r || l.p.suppliers.length < 2) return t;
            return t + (r.max - Number(s.piece_cost)) * l.qty * packOf(l);
        }, 0));
    }

    function visible(l) {
        if (show === 'multi' && l.p.suppliers.length < 2) return false;
        if (show === 'nosup' && l.p.suppliers.length) return false;
        if (!q) return true;
        const t = q.toLowerCase();
        return l.p.name.toLowerCase().includes(t) || (l.p.barcode || '').includes(q)
            || l.p.suppliers.some((s) => s.supplier_name.toLowerCase().includes(t));
    }

    function diffHtml(s, r) {
        const d = round2(Number(s.piece_cost) - r.min);
        if (d < 0.005) return '<span class="pill ok">ถูกสุด</span>';
        const pct = r.min > 0 ? Math.round((d / r.min) * 1000) / 10 : 0;
        return `<span style="color:var(--bad);font-weight:600;white-space:nowrap">+฿${money(d)}/ชิ้น (+${pct}%)</span>`;
    }

    function suppliersHtml(l, i) {
        const p = l.p;
        if (!p.suppliers.length) {
            return '<div class="muted" style="font-size:12.5px">ยังไม่เคยมีใบซื้อของสินค้านี้ — ไม่มีราคาผู้ขายให้เปรียบเทียบ (พิมพ์รวมในกลุ่ม "ยังไม่ระบุผู้ขาย" ได้)</div>';
        }
        const r = spread(p);
        return `<div class="ro-sups">${p.suppliers.map((s, k) => {
            const size = Number(s.pack_size) || 1;
            const lowest = Number(s.min_piece_cost) > 0 && Number(s.min_piece_cost) < Number(s.piece_cost) - 0.004
                ? ` · เคยได้ต่ำสุด ฿${money(s.min_piece_cost)}/ชิ้น` : '';
            return `<label class="ro-sup ${k === l.sup ? 'on' : ''}">
              <input type="radio" name="sup-${i}" value="${k}" data-sup ${k === l.sup ? 'checked' : ''}>
              <span class="ro-sup-name">${escapeHtml(s.supplier_name)}</span>
              <span class="ro-sup-cost"><b>฿${money(s.piece_cost)}</b>/ชิ้น ${p.suppliers.length > 1 ? diffHtml(s, r) : ''}</span>
              <span class="muted ro-sup-meta">${size > 1 ? `แพ็ค ${count(size)} ชิ้น ฿${money(s.unit_cost)} · ` : ''}ล่าสุด ${escapeHtml(fmtDate(s.last_date))} (${count(s.qty)} ${size > 1 ? 'แพ็ค' : 'ชิ้น'}) · ซื้อ ${count(s.times)} ครั้ง${lowest}</span>
            </label>`;
        }).join('')}</div>`;
    }

    function rowHtml(l, i) {
        const p = l.p;
        const size = packOf(l);
        const out = Number(p.qty_stock) < 1;
        const pending = p.pending.length
            ? `<div class="pill warn" style="margin-top:4px;white-space:normal" title="ร่างใบสั่งซื้อ / ใบสั่งซื้อที่ยังรอส่งของ">มีใน${p.pending.map((x) => `${x.status === 'draft' ? 'ร่าง' : 'ใบสั่ง'} ${escapeHtml(x.doc_no)} (${escapeHtml(x.supplier_name)})`).join(', ')} แล้ว</div>` : '';
        return `<tr class="${l.on ? '' : 'po-off'}" data-line="${i}">
          <td><input type="checkbox" data-on ${l.on ? 'checked' : ''} aria-label="ซื้อ ${escapeHtml(p.name)}"></td>
          <td style="min-width:180px"><b>${escapeHtml(p.name)}</b>${p.barcode ? `<div class="muted" style="font-size:12px">${escapeHtml(p.barcode)}</div>` : ''}
            <div style="font-size:12.5px;margin-top:2px">คงเหลือ <b style="color:${out ? 'var(--bad)' : 'var(--warn)'}">${count(p.qty_stock)}</b>
              · ขาย 30 วัน <b>${count(p.sold_30d)}</b></div>
            <div class="muted" style="font-size:12px">ทุนเฉลี่ย ฿${money(p.cost_price)} · ขาย ฿${money(p.sale_price)}${p.last_sold ? ` · ขายล่าสุด ${escapeHtml(fmtDate(p.last_sold))}` : ''}</div>${pending}</td>
          <td style="min-width:320px">${suppliersHtml(l, i)}</td>
          <td style="white-space:nowrap"><input data-qty type="number" min="0" step="1" value="${l.qty}" class="num" style="width:70px" aria-label="จำนวน">
            <div class="muted" style="font-size:11.5px">${size > 1 ? `แพ็ค = ${count(l.qty * size)} ชิ้น` : 'ชิ้น'}</div></td>
          <td style="white-space:nowrap"><input data-cost type="number" min="0" step="0.01" value="${l.unit_cost}" class="num" style="width:90px" aria-label="ราคาต่อหน่วยซื้อ">
            <div class="muted" style="font-size:11.5px">${size > 1 && l.unit_cost > 0 ? `฿${money(pieceCost(l))}/ชิ้น` : '&nbsp;'}</div></td>
          <td class="num" data-total style="font-weight:600">${money(lineTotal(l))}</td>
        </tr>`;
    }

    function summaryHtml() {
        const g = groups();
        if (!g.length) return '<div class="muted">ยังไม่ได้เลือกรายการที่จะซื้อ</div>';
        const total = g.reduce((t, o) => t + o.lines.reduce((x, l) => x + lineTotal(l), 0), 0);
        const save = saving();
        return `${g.map((o) => `<div><b>${escapeHtml(o.supplier_name)}</b>: ${o.lines.length} รายการ ฿${money(o.lines.reduce((t, l) => t + lineTotal(l), 0))}</div>`).join('')}
          <div style="flex-basis:100%"><b>รวมทั้งหมด ${chosen().length} รายการ ฿${money(total)}</b>${save > 0.004 ? ` <span class="pill ok">ถูกกว่าซื้อจากผู้ขายที่แพงที่สุด ฿${money(save)}</span>` : ''}</div>`;
    }

    function kpiHtml() {
        const multi = lines.filter((l) => l.p.suppliers.length > 1);
        const out = lines.filter((l) => Number(l.p.qty_stock) < 1).length;
        return `<div class="kpi-grid">
          <div class="kpi"><div class="lbl">สินค้าใกล้หมด (คงเหลือ ≤ ${count(threshold)})</div><div class="val">${count(lines.length)} รายการ</div>
            <div class="sub">หมดแล้ว ${count(out)} รายการ</div></div>
          <div class="kpi"><div class="lbl">มีผู้ขายมากกว่า 1 ราย</div><div class="val">${count(multi.length)} รายการ</div>
            <div class="sub">เปรียบเทียบต้นทุนต่อชิ้นได้</div></div>
          <div class="kpi"><div class="lbl">ยังไม่เคยมีใบซื้อ</div><div class="val">${count(lines.filter((l) => !l.p.suppliers.length).length)} รายการ</div>
            <div class="sub">ไม่มีราคาผู้ขาย</div></div>
          <div class="kpi"><div class="lbl">มีในใบสั่งซื้อแล้ว</div><div class="val">${count(lines.filter((l) => l.p.pending.length).length)} รายการ</div>
            <div class="sub">ร่าง / รอส่งของ (ไม่ติ๊กไว้)</div></div>
        </div>`;
    }

    function refresh() {
        container.querySelector('[data-summary]').innerHTML = summaryHtml();
        const n = chosen().length;
        const known = groups().filter((g) => g.known).length;
        const pr = container.querySelector('#ro-print');
        pr.disabled = !n;
        pr.textContent = `🖨 พิมพ์ใบรายการซื้อสินค้า (${n})`;
        const dr = container.querySelector('#ro-draft');
        dr.disabled = busy || !known;
        dr.textContent = busy ? 'กำลังบันทึก...' : `บันทึกเป็นร่างใบสั่งซื้อ (${known} ร้าน)`;
        const boxes = [...container.querySelectorAll('tr[data-line] [data-on]')];
        const all = container.querySelector('[data-all]');
        if (all) {
            all.checked = boxes.length > 0 && boxes.every((b) => b.checked);
            all.indeterminate = !all.checked && boxes.some((b) => b.checked);
        }
    }

    function draw() {
        const shown = lines.map((l, i) => [l, i]).filter(([l]) => visible(l));
        container.innerHTML = `
        ${kpiHtml()}
        <section class="card card-pad">
          <div class="hint"><div class="dot">i</div><div>สินค้าของร้านที่คงเหลือไม่เกิน <b>${count(threshold)} ชิ้น</b> (ไม่รวมสินค้าเลิกจำหน่าย) เรียงตามยอดขาย 30 วัน ·
            เกณฑ์ใกล้หมดตั้งได้ที่หน้า <button type="button" class="link-btn" id="ro-inv">สต๊อกสินค้าของร้าน</button> ·
            ราคาผู้ขายแต่ละรายเป็นราคาจากใบซื้อครั้งล่าสุดของร้านนั้น เทียบเป็น<b>ต้นทุนต่อชิ้น</b> (แพ็คหารจำนวนชิ้นแล้ว) ระบบเลือกรายที่ถูกที่สุดไว้ให้ เปลี่ยนได้ ·
            จำนวนและราคาเริ่มจากใบซื้อครั้งล่าสุดของร้านที่เลือก แก้ได้</div></div>
          <div class="actions-row" style="justify-content:space-between;margin-top:12px">
            <input type="search" id="ro-q" class="search-input" placeholder="🔍 ค้นหาชื่อสินค้า บาร์โค้ด หรือผู้ขาย" value="${escapeHtml(q)}">
            <div class="tabs" role="group" aria-label="กรองรายการ">
              ${[['all', 'ทั้งหมด'], ['multi', 'มีผู้ขายหลายราย'], ['nosup', 'ยังไม่เคยมีใบซื้อ']].map(([k, label]) => `<button type="button" class="tab-btn ${k === show ? 'active' : ''}" data-show="${k}">${label}</button>`).join('')}
            </div>
          </div>
        </section>
        <section class="card">
          <div class="table-wrap"><table class="po-table">
            <thead><tr><th><input type="checkbox" data-all aria-label="เลือกทั้งหมดที่แสดง" title="เลือกทั้งหมดที่แสดง"></th><th>สินค้า</th><th>เปรียบเทียบผู้ขาย (ต้นทุนต่อชิ้น)</th><th>จำนวนซื้อ</th><th>ราคา/หน่วยซื้อ</th><th class="num">รวม (บาท)</th></tr></thead>
            <tbody>${shown.map(([l, i]) => rowHtml(l, i)).join('')
                || `<tr><td colspan="6" class="empty">${lines.length ? 'ไม่มีรายการที่ตรงกับตัวกรอง' : `ไม่มีสินค้าของร้านที่คงเหลือไม่เกิน ${count(threshold)} ชิ้น`}</td></tr>`}</tbody>
          </table></div>
        </section>
        <section class="card card-pad">
          <div class="po-summary" data-summary></div>
          <div class="actions-row" style="justify-content:flex-end;margin-top:12px">
            <label style="font-size:13px;display:flex;gap:6px;align-items:center"><input type="checkbox" id="ro-split" ${splitPages ? 'checked' : ''}> พิมพ์แยกหน้าตามผู้ขาย</label>
            <button type="button" class="btn secondary" id="ro-draft" title="สร้างร่างใบสั่งซื้อแยกตามร้าน (ร้านที่มีร่างอยู่แล้วจะเพิ่มลงร่างเดิม) ที่หน้าซื้อสินค้าเข้าร้าน — ไม่รวมรายการที่ยังไม่ระบุผู้ขาย"></button>
            <button type="button" class="btn" id="ro-print"></button>
          </div>
        </section>`;
        refresh();

        const search = container.querySelector('#ro-q');
        search.addEventListener('input', () => {
            q = search.value.trim();
            const at = search.selectionStart;
            draw();
            const again = container.querySelector('#ro-q');
            again.focus();
            again.setSelectionRange(at, at);
        });
        container.querySelector('#ro-inv').addEventListener('click', () => navigate('inventory'));
        container.querySelectorAll('[data-show]').forEach((b) => b.addEventListener('click', () => { show = b.dataset.show; draw(); }));
        container.querySelector('[data-all]')?.addEventListener('change', (e) => {
            shown.forEach(([l]) => { l.on = e.target.checked; });
            draw();
        });
        container.querySelector('#ro-split').addEventListener('change', (e) => { splitPages = e.target.checked; });
        container.querySelector('#ro-print').addEventListener('click', print);
        container.querySelector('#ro-draft').addEventListener('click', saveDrafts);
        container.querySelectorAll('tr[data-line]').forEach((tr) => {
            const l = lines[Number(tr.dataset.line)];
            const total = () => {
                tr.querySelector('[data-total]').textContent = money(lineTotal(l));
                refresh();
            };
            tr.querySelector('[data-on]').addEventListener('change', (e) => { l.on = e.target.checked; tr.classList.toggle('po-off', !l.on); refresh(); });
            tr.querySelectorAll('[data-sup]').forEach((r) => r.addEventListener('change', () => {
                // Another supplier: its own pack and last price.
                l.sup = Number(r.value);
                const s = supplierOf(l);
                l.qty = Math.max(1, Number(s.qty) || 1);
                l.unit_cost = Number(s.unit_cost);
                l.on = true;
                draw();
            }));
            tr.querySelector('[data-qty]').addEventListener('input', (e) => { l.qty = Math.max(0, Math.floor(Number(e.target.value) || 0)); total(); });
            tr.querySelector('[data-cost]').addEventListener('input', (e) => { l.unit_cost = Math.max(0, Number(e.target.value) || 0); total(); });
        });
    }

    function print() {
        const g = groups();
        if (!g.length) return;
        printPurchaseList({
            threshold,
            splitPages,
            preparedBy: user?.full_name || user?.username || '',
            groups: g.map((o) => ({
                supplier_name: o.supplier_name,
                supplier_tax_id: o.supplier_tax_id,
                known: o.known,
                lines: o.lines.map((l) => {
                    const s = supplierOf(l);
                    const r = spread(l.p);
                    // The other suppliers, for comparing on paper.
                    const others = s ? l.p.suppliers.filter((x) => x !== s).map((x) => ({ name: x.supplier_name, piece_cost: Number(x.piece_cost) })) : [];
                    return {
                        name: l.p.name,
                        receipt_name: s?.receipt_name && s.receipt_name !== l.p.name ? s.receipt_name : '',
                        barcode: s?.barcode || l.p.barcode || '',
                        qty_stock: Number(l.p.qty_stock), sold_30d: Number(l.p.sold_30d),
                        qty: l.qty, pack_size: packOf(l), unit_cost: l.unit_cost, piece_cost: round2(pieceCost(l)),
                        total: lineTotal(l), cheapest: !!(s && r && l.p.suppliers.length > 1 && Number(s.piece_cost) <= r.min + 0.004),
                        others,
                    };
                }),
            })),
        });
    }

    async function saveDrafts() {
        const g = groups().filter((o) => o.known);
        if (!g.length || busy) return;
        const skipped = chosen().filter((l) => !supplierOf(l)).length;
        const ok = await confirmDialog(
            `${g.map((o) => `${o.supplier_name}: ${o.lines.length} รายการ ฿${money(o.lines.reduce((t, l) => t + lineTotal(l), 0))}`).join('\n')}`
            + (skipped ? `\n\nรายการที่ยังไม่ระบุผู้ขาย ${skipped} รายการ จะไม่ถูกบันทึก` : '')
            + '\n\nร้านที่มีร่างใบสั่งซื้ออยู่แล้วจะเพิ่มลงร่างเดิม',
            { title: `บันทึกร่างใบสั่งซื้อ ${g.length} ร้าน?`, okText: 'บันทึกร่าง' }
        );
        if (!ok) return;
        busy = true;
        refresh();
        try {
            const res = await api.post('/inventory/purchase-orders', {
                orders: g.map((o) => ({
                    supplier_name: o.supplier_name, supplier_tax_id: o.supplier_tax_id,
                    items: o.lines.map((l) => {
                        const s = supplierOf(l);
                        return {
                            product_id: l.p.id, name: l.p.name, receipt_name: s.receipt_name, barcode: s.barcode,
                            qty: l.qty, pack_size: packOf(l), unit_cost: l.unit_cost,
                        };
                    }),
                })),
            });
            busy = false;
            await load();
            const made = res.data;
            const go = await confirmDialog(
                `${made.map((d) => `${d.doc_no} ${d.supplier_name}${d.merged ? ' (เพิ่มลงร่างเดิม)' : ''} รวม ${d.items} รายการ ฿${money(d.total)}`).join('\n')}\n\nเป็นร่าง ยังไม่ได้สั่ง — เมื่อสั่งกับร้านแล้วกด ยืนยันสั่งซื้อแล้ว ที่หน้าซื้อสินค้าเข้าร้าน`,
                { title: `บันทึกร่างใบสั่งซื้อ ${made.length} ใบแล้ว`, okText: 'ไปที่หน้าซื้อสินค้าเข้าร้าน', cancelText: 'อยู่หน้านี้' }
            );
            if (go) navigate('purchase');
        } catch (e) {
            busy = false;
            refresh();
            alertDialog(e.message, { tone: 'error' });
        }
    }

    await load();
}
