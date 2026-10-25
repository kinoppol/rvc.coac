import { api } from './api.js';
import { money, count, escapeHtml, fmtDate } from './util.js';
import { alertDialog, confirmDialog } from './dialog.js';
import { skBlock } from './skeleton.js';

// Purchase orders from stock: each picked product goes to ONE supplier it was bought from
// before (the cheapest per piece by default), and one order (waiting for delivery) is made
// per supplier. A product already on an order not delivered yet starts unticked.

const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

export async function openPoBuilder(ids, { navigate, onDone } = {}) {
    const box = document.createElement('div');
    box.className = 'modal-backdrop';
    box.innerHTML = '<div class="modal" role="dialog" aria-modal="true" style="width:1080px"><div class="modal-body">' + skBlock(6, 8) + '</div></div>';
    document.body.appendChild(box);
    const close = () => { box.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === box) close(); };
    document.addEventListener('keydown', onKey);

    let products;
    try {
        products = (await api.get(`/inventory/supplier-options?ids=${ids.join(',')}`)).data;
    } catch (e) {
        close();
        alertDialog(e.message, { tone: 'error' });
        return;
    }
    // One line per product: which supplier (index into its suppliers), how many purchase units, at what cost.
    const lines = products.map((p) => {
        const s = p.suppliers[0];
        return {
            p, on: !!s && !p.pending.length, sup: 0,
            qty: s ? Math.max(1, Number(s.qty) || 1) : 1, unit_cost: s ? Number(s.unit_cost) : 0,
        };
    });
    let busy = false;
    let error = null;

    const supplierOf = (l) => l.p.suppliers[l.sup];
    const usable = (l) => l.on && supplierOf(l) && l.qty > 0 && l.unit_cost > 0;

    /** Orders to create: one per supplier name, in the order they first appear. */
    function groups() {
        const out = new Map();
        lines.filter(usable).forEach((l) => {
            const s = supplierOf(l);
            if (!out.has(s.supplier_name)) out.set(s.supplier_name, { supplier_name: s.supplier_name, supplier_tax_id: s.supplier_tax_id, lines: [] });
            out.get(s.supplier_name).lines.push(l);
        });
        return [...out.values()];
    }

    function optionText(s, cheapest) {
        const pack = Number(s.pack_size) > 1 ? ` (แพ็ค ${s.pack_size} ชิ้น ฿${money(s.unit_cost)})` : '';
        return `${s.supplier_name} · ฿${money(s.piece_cost)}/ชิ้น${pack} · ล่าสุด ${fmtDate(s.last_date)}${cheapest ? ' · ถูกสุด' : ''}`;
    }

    function lineHtml(l, i) {
        const p = l.p;
        const s = supplierOf(l);
        const pending = p.pending.length ? `<div class="pill warn" style="margin-top:4px" title="ใบสั่งซื้อที่ยังรอส่งของ">มีในใบสั่ง ${p.pending.map((x) => `${escapeHtml(x.doc_no)} (${escapeHtml(x.supplier_name)})`).join(', ')} แล้ว</div>` : '';
        if (!s) {
            return `<tr class="po-off"><td></td><td>${escapeHtml(p.name)}<div class="muted" style="font-size:12px">คงเหลือ ${count(p.qty_stock)}</div>${pending}</td>
              <td colspan="5" class="muted" style="font-size:12.5px">ยังไม่เคยซื้อจากร้านค้า (ไม่มีใบซื้อ) — สร้างใบสั่งเองที่หน้าซื้อสินค้าเข้าร้าน</td></tr>`;
        }
        const size = Number(s.pack_size) || 1;
        return `<tr class="${l.on ? '' : 'po-off'}" data-line="${i}">
          <td><input type="checkbox" data-on ${l.on ? 'checked' : ''} aria-label="สั่ง ${escapeHtml(p.name)}"></td>
          <td>${escapeHtml(p.name)}<div class="muted" style="font-size:12px">คงเหลือ <b style="${Number(p.qty_stock) < 1 ? 'color:var(--bad)' : ''}">${count(p.qty_stock)}</b> · ทุนเฉลี่ย ฿${money(p.cost_price)}</div>${pending}</td>
          <td><select data-sup style="max-width:330px">${p.suppliers.map((x, k) => `<option value="${k}" ${k === l.sup ? 'selected' : ''}>${escapeHtml(optionText(x, k === 0 && p.suppliers.length > 1))}</option>`).join('')}</select>
            ${p.suppliers.length > 1 ? `<div class="muted" style="font-size:11.5px">ซื้อได้ ${p.suppliers.length} ร้าน</div>` : ''}</td>
          <td style="font-size:12.5px;white-space:nowrap">${size > 1 ? `แพ็ค ×${size}` : 'ชิ้น'}${s.barcode ? `<div class="muted">${escapeHtml(s.barcode)}</div>` : ''}</td>
          <td><input data-qty type="number" min="1" step="1" value="${l.qty}" class="num" style="width:70px"></td>
          <td><input data-cost type="number" min="0" step="0.01" value="${l.unit_cost}" class="num" style="width:90px"></td>
          <td class="num" data-total>${money(round2(l.qty * l.unit_cost))}<div class="muted" style="font-size:11.5px">${count(l.qty * size)} ชิ้น</div></td>
        </tr>`;
    }

    function summaryHtml() {
        const g = groups();
        if (!g.length) return '<div class="muted">ยังไม่มีรายการที่จะสั่ง</div>';
        return g.map((o) => `<div><b>${escapeHtml(o.supplier_name)}</b>: ${o.lines.length} รายการ ฿${money(o.lines.reduce((t, l) => t + round2(l.qty * l.unit_cost), 0))}</div>`).join('');
    }

    function refresh() {
        box.querySelector('[data-summary]').innerHTML = summaryHtml();
        const n = groups().length;
        const go = box.querySelector('[data-go]');
        go.disabled = busy || !n;
        go.textContent = busy ? 'กำลังสร้าง...' : `สร้างใบสั่งซื้อ ${n} ใบ`;
    }

    function draw() {
        box.querySelector('.modal').innerHTML = `
          <div class="modal-head"><h2>สร้างใบรายการสั่งซื้อสินค้า</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
          <div class="modal-body" style="padding-top:12px">
            <div class="hint"><div class="dot">i</div><div>สินค้าแต่ละรายการสั่งจากร้านเดียว ระบบเลือกร้านที่ต้นทุนต่อชิ้นต่ำที่สุดไว้ให้ (เปลี่ยนได้) ·
              จำนวนและราคาเริ่มจากใบซื้อล่าสุดของร้านนั้น · ได้ใบสั่งซื้อแยกตามร้าน สถานะ <b>รอส่งของ</b> (ยังไม่เข้าสต๊อก จนกว่าจะกด รับสินค้า ที่หน้าซื้อสินค้าเข้าร้าน)</div></div>
            <div class="table-wrap" style="max-height:440px;border:1px solid var(--border);border-radius:10px;margin-top:12px"><table class="po-table">
              <thead><tr><th></th><th>สินค้า</th><th>ร้านค้า</th><th>หน่วยซื้อ</th><th class="num">จำนวน</th><th class="num">ทุน/หน่วยซื้อ</th><th class="num">รวม</th></tr></thead>
              <tbody>${lines.map(lineHtml).join('')}</tbody></table></div>
            <div class="po-summary" data-summary>${summaryHtml()}</div>
            ${error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(error)}</div>` : ''}
          </div>
          <div class="modal-foot"><button type="button" class="btn secondary" data-x>ยกเลิก</button>
            <button type="button" class="btn" data-go></button></div>`;
        refresh();
        box.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        box.querySelectorAll('tr[data-line]').forEach((tr) => {
            const l = lines[Number(tr.dataset.line)];
            const total = () => {
                const size = Number(supplierOf(l).pack_size) || 1;
                tr.querySelector('[data-total]').innerHTML = `${money(round2(l.qty * l.unit_cost))}<div class="muted" style="font-size:11.5px">${count(l.qty * size)} ชิ้น</div>`;
                refresh();
            };
            tr.querySelector('[data-on]').addEventListener('change', (e) => { l.on = e.target.checked; tr.classList.toggle('po-off', !l.on); refresh(); });
            tr.querySelector('[data-sup]').addEventListener('change', (e) => {
                // Another supplier: its own pack and last price.
                l.sup = Number(e.target.value);
                const s = supplierOf(l);
                l.qty = Math.max(1, Number(s.qty) || 1);
                l.unit_cost = Number(s.unit_cost);
                draw();
            });
            tr.querySelector('[data-qty]').addEventListener('input', (e) => { l.qty = Math.max(0, Math.floor(Number(e.target.value) || 0)); total(); });
            tr.querySelector('[data-cost]').addEventListener('input', (e) => { l.unit_cost = Math.max(0, Number(e.target.value) || 0); total(); });
        });
        box.querySelector('[data-go]').addEventListener('click', create);
    }

    async function create() {
        const g = groups();
        if (!g.length || busy) return;
        busy = true;
        error = null;
        refresh();
        try {
            const res = await api.post('/inventory/purchase-orders', {
                orders: g.map((o) => ({
                    supplier_name: o.supplier_name, supplier_tax_id: o.supplier_tax_id,
                    items: o.lines.map((l) => {
                        const s = supplierOf(l);
                        return {
                            product_id: l.p.id, name: l.p.name, receipt_name: s.receipt_name, barcode: s.barcode,
                            qty: l.qty, pack_size: Number(s.pack_size) || 1, unit_cost: l.unit_cost,
                        };
                    }),
                })),
            });
            close();
            onDone?.();
            const made = res.data;
            const go = await confirmDialog(
                `${made.map((d) => `${d.doc_no} ${d.supplier_name} ${d.items} รายการ ฿${money(d.total)}`).join('\n')}\n\nสถานะรอส่งของ ยังไม่เข้าสต๊อก — เมื่อร้านมาส่ง กด รับสินค้า ที่หน้าซื้อสินค้าเข้าร้าน`,
                { title: `สร้างใบสั่งซื้อ ${made.length} ใบแล้ว`, okText: 'ไปที่หน้าซื้อสินค้าเข้าร้าน', cancelText: 'อยู่หน้านี้' }
            );
            if (go && navigate) navigate('purchase');
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    draw();
}
