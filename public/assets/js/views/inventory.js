import { api } from '../api.js';
import { money, count, escapeHtml, fmtDate } from '../util.js';

export async function render(container, { navigate, permissions }) {
    let search = '';
    let res = null;
    // Barcode editor: piece barcode (scanned at the POS) + any number of pack barcodes.
    let editing = null; // { id, name, barcode, packs: [{ barcode, pack_size }], error, busy }
    const canEdit = permissions.includes('purchase.create');

    async function load() {
        const q = search ? `?q=${encodeURIComponent(search)}` : '';
        res = await api.get(`/inventory${q}`);
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
            <div class="sub">หักค่าวัสดุใช้ในร้าน ฿ ${money(s.supply_cost)} = กำไรสุทธิ ฿ ${money(s.net_profit)}</div></div>
        </div>
        <section class="card card-pad">
          <div class="actions-row" style="justify-content:space-between">
            <input type="search" id="q" placeholder="ค้นหาชื่อหรือบาร์โค้ด (ชิ้น/แพ็ค) แล้วกด Enter" value="${escapeHtml(search)}" style="max-width:320px">
            ${canBuy ? `<button type="button" class="btn" id="go-buy">ซื้อสินค้าเข้าร้าน</button>` : ''}
          </div>
        </section>
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th title="บาร์โค้ดบนตัวสินค้า 1 ชิ้น ใช้สแกนตอนขาย">บาร์โค้ดชิ้น</th>
              <th title="บาร์โค้ดของแพ็ค/ลังที่ซื้อเข้า สินค้าเดียวกันมีได้หลายขนาดแพ็ค">บาร์โค้ดแพ็ค</th><th>รับเข้า</th><th>ขายแล้ว</th><th>คงเหลือ</th>
              <th>ต้นทุน/ชิ้น</th><th>ราคาขาย</th><th>กำไร %</th><th>มูลค่าทุนคงเหลือ</th><th>กำไรสะสม</th><th>ซื้อล่าสุด</th></tr></thead>
            <tbody>${res.data.map(row).join('') || `<tr><td colspan="12" class="empty">ยังไม่มีสินค้าของร้าน — เริ่มจากเมนู ซื้อสินค้าเข้าร้าน</td></tr>`}</tbody>
          </table></div>
        </section>
        ${editing ? editHtml() : ''}`;

        const input = container.querySelector('#q');
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { search = input.value.trim(); load(); } });
        const buy = container.querySelector('#go-buy');
        if (buy) buy.addEventListener('click', () => navigate('purchase'));
        container.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
            const p = res.data.find((x) => String(x.id) === b.dataset.edit);
            editing = { id: p.id, name: p.name, barcode: p.barcode || '', packs: p.pack_barcodes.map((x) => ({ ...x })), error: null, busy: false };
            if (!editing.packs.length) editing.packs.push({ barcode: '', pack_size: '' });
            draw();
            container.querySelector('#bc-piece')?.focus();
        }));
        if (editing) bindEdit();
    }

    function row(p) {
        const low = Number(p.qty_stock) <= 3;
        return `<tr>
          <td>${escapeHtml(p.name)}</td>
          <td class="muted">${p.barcode ? escapeHtml(p.barcode) : '<span title="ยังไม่มีบาร์โค้ดชิ้น">—</span>'}</td>
          <td style="white-space:nowrap">
            ${p.pack_barcodes.map((b) => `<div class="muted" style="font-size:12px">${escapeHtml(b.barcode)} <span class="pill muted">×${b.pack_size}</span></div>`).join('')}
            ${canEdit ? `<button type="button" class="btn secondary sm" data-edit="${p.id}" title="แก้ไขบาร์โค้ดชิ้น/แพ็ค">แก้ไข</button>` : ''}
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
              <h2>บาร์โค้ด · ${escapeHtml(e.name)}</h2>
              <button type="button" class="modal-close" data-close-edit title="ปิด" aria-label="ปิด">✕</button>
            </div>
            <div class="modal-body">
              <label class="field" style="display:block;margin-bottom:16px">บาร์โค้ดชิ้น (สแกนตอนขาย)
                <input id="bc-piece" value="${escapeHtml(e.barcode)}" placeholder="สแกนบาร์โค้ดบนตัวสินค้า 1 ชิ้น" style="width:100%">
              </label>
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
              <button type="button" class="btn secondary sm" id="bc-add" style="margin-top:8px">+ เพิ่มบาร์โค้ดแพ็ค</button>
              ${e.error ? `<div class="error-box" style="margin-top:12px">${escapeHtml(e.error)}</div>` : ''}
            </div>
            <div class="modal-foot"><button type="button" class="btn secondary" data-close-edit>ยกเลิก</button>
              <button type="button" class="btn" id="bc-save" ${e.busy ? 'disabled' : ''}>${e.busy ? 'กำลังบันทึก...' : 'บันทึก'}</button></div>
          </div>
        </div>`;
    }

    function bindEdit() {
        const e = editing;
        container.querySelector('#bc-piece').addEventListener('input', (ev) => { e.barcode = ev.target.value; });
        container.querySelectorAll('tr[data-pack]').forEach((tr) => {
            const p = e.packs[Number(tr.dataset.pack)];
            tr.querySelectorAll('[data-pf]').forEach((inp) => inp.addEventListener('input', () => { p[inp.dataset.pf] = inp.value; }));
        });
        container.querySelectorAll('[data-del-pack]').forEach((b) => b.addEventListener('click', () => {
            e.packs.splice(Number(b.dataset.delPack), 1);
            draw();
        }));
        container.querySelector('#bc-add').addEventListener('click', () => {
            e.packs.push({ barcode: '', pack_size: '' });
            draw();
            const inputs = container.querySelectorAll('[data-pf="barcode"]');
            inputs[inputs.length - 1]?.focus();
        });
        container.querySelectorAll('[data-close-edit]').forEach((b) => b.addEventListener('click', () => { editing = null; draw(); }));
        container.querySelector('#bc-save').addEventListener('click', async () => {
            e.busy = true; e.error = null; draw();
            try {
                const out = await api.put(`/inventory/${e.id}/barcodes`, {
                    barcode: e.barcode.trim(),
                    pack_barcodes: e.packs
                        .filter((p) => String(p.barcode).trim())
                        .map((p) => ({ barcode: String(p.barcode).trim(), pack_size: Number(p.pack_size) })),
                });
                const p = res.data.find((x) => x.id === e.id);
                p.barcode = out.data.barcode;
                p.pack_barcodes = out.data.pack_barcodes;
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
