import { api } from './api.js';
import { count, escapeHtml, fmtDate } from './util.js';
import { alertDialog } from './dialog.js';

// ผู้ช่วยกำหนดเกณฑ์ใกล้หมด: each store product's running-low line from what it sold —
// the average sold per day the shop was open (a day with any sale) over the last N days,
// times the days the stock should last, rounded up; a product with no sales gets the line
// for no sales. Worked out by the server from the sales records (LowStockService), shown
// here to check and change before saving. Admin / manager only (the server checks too).

const perDayText = (n) => (Number(n) ? Number(n).toLocaleString('th-TH', { maximumFractionDigits: 2 }) : '0');

export function openLowStockAssist({ onSaved = () => {} } = {}) {
    let params = null; // { avg_days, cover_days, no_sales } — null until the first answer (the saved ones)
    let data = null; // the server's answer
    let lines = []; // { row, value (string), on }
    let q = '';
    let only = 'all'; // all | changed | low
    let busy = false;
    let error = null;

    const el = document.createElement('div');
    el.className = 'modal-backdrop';
    document.body.appendChild(el);
    const close = () => { el.remove(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => { if (e.key === 'Escape' && [...document.querySelectorAll('.modal-backdrop')].pop() === el) close(); };
    document.addEventListener('keydown', onKey);

    const val = (l) => (l.value === '' ? null : Math.max(0, Math.floor(Number(l.value))));
    const changed = (l) => val(l) !== l.row.low_stock_at;
    const lowNow = (l) => val(l) !== null && l.row.qty_stock <= val(l);
    const shown = () => lines.filter((l) => {
        if (only === 'changed' && !changed(l)) return false;
        if (only === 'low' && !lowNow(l)) return false;
        if (!q) return true;
        const t = q.toLowerCase();
        return l.row.name.toLowerCase().includes(t) || (l.row.barcode || '').includes(q);
    });
    const toSave = () => lines.filter((l) => l.on && changed(l));

    async function calc() {
        busy = true;
        error = null;
        draw();
        try {
            const qs = params ? `?${new URLSearchParams(params)}` : '';
            data = (await api.get(`/inventory/low-stock/suggest${qs}`)).data;
            params = { ...data.params };
            lines = data.rows.map((row) => ({ row, value: String(row.suggested), on: true }));
        } catch (e) {
            error = e.message;
        }
        busy = false;
        draw();
    }

    function rowHtml(l) {
        const r = l.row;
        const v = val(l);
        return `<tr data-id="${r.id}" class="${l.on ? '' : 'po-off'}">
          <td><input type="checkbox" data-on ${l.on ? 'checked' : ''} aria-label="ใช้เกณฑ์ใหม่ของ ${escapeHtml(r.name)}"></td>
          <td>${escapeHtml(r.name)}${r.barcode ? `<div class="muted" style="font-size:11.5px">${escapeHtml(r.barcode)}</div>` : ''}</td>
          <td class="num">${count(r.qty_stock)}</td>
          <td class="num">${count(r.sold)}</td>
          <td class="num">${perDayText(r.per_day)}</td>
          <td class="num muted">${r.low_stock_at === null ? `<span title="ใช้เกณฑ์กลางของร้าน">${count(data.threshold)}*</span>` : count(r.low_stock_at)}</td>
          <td class="num"><input type="number" min="0" step="1" data-v value="${escapeHtml(l.value)}" class="num" style="width:76px" aria-label="เกณฑ์ใหม่ของ ${escapeHtml(r.name)}"></td>
          <td data-low>${v !== null && r.qty_stock <= v ? '<span class="pill warn">ใกล้หมด</span>' : ''}</td>
        </tr>`;
    }

    function draw() {
        const p = params || { avg_days: '', cover_days: '', no_sales: '' };
        const list = data ? shown() : [];
        const n = toSave().length;
        el.innerHTML = `
          <div class="modal" style="width:1000px" role="dialog" aria-modal="true" aria-labelledby="lsa-title">
            <div class="modal-head"><h2 id="lsa-title">✨ ผู้ช่วยกำหนดเกณฑ์สินค้าใกล้หมด</h2><button type="button" class="modal-close" data-x aria-label="ปิด">✕</button></div>
            <div class="modal-body">
              <div class="hint" style="margin-bottom:12px"><div class="dot">i</div><div>
                เกณฑ์ของแต่ละสินค้า = <b>ยอดขายเฉลี่ยต่อวัน × จำนวนวันที่ต้องการให้พอขาย</b> (ปัดขึ้น) ·
                ยอดขายเฉลี่ยคิดจากยอดขาย ${p.avg_days || 'N'} วันที่ผ่านมา (ไม่รวมวันนี้) หารด้วย<b>จำนวนวันที่ร้านเปิดขาย</b> (วันที่มีการขาย) ·
                สินค้าที่มีคงเหลือไม่เกินเกณฑ์ของตัวเองจะขึ้นเป็นสินค้าใกล้หมด · คำนวณจากยอดขายจริงในระบบ ไม่ได้ส่งข้อมูลให้โมเดล AI</div></div>
              <div class="actions-row" style="gap:12px;align-items:flex-end">
                <label class="field" style="width:170px">ยอดขายย้อนหลัง (วัน)<input type="number" min="1" max="90" step="1" data-p="avg_days" value="${p.avg_days}"></label>
                <label class="field" style="width:190px">ต้องการให้พอขาย (วัน)<input type="number" min="1" max="60" step="1" data-p="cover_days" value="${p.cover_days}"></label>
                <label class="field" style="width:220px">ไม่มียอดขาย ใช้เกณฑ์ (ชิ้น)<input type="number" min="0" step="1" data-p="no_sales" value="${p.no_sales}"></label>
                <button type="button" class="btn" data-calc ${busy ? 'disabled' : ''}>${busy ? 'กำลังคำนวณ...' : 'คำนวณใหม่'}</button>
              </div>
              ${error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(error)}</div>` : ''}
              ${data ? `
                <div class="muted" style="font-size:12.5px;margin:10px 0">
                  ยอดขาย ${escapeHtml(fmtDate(data.from))} – ${escapeHtml(fmtDate(data.to))} · ร้านเปิดขาย <b>${count(data.open_days)}</b> วัน
                  ${data.open_days ? '' : ' · <span style="color:var(--bad)">ไม่มียอดขายในช่วงนี้ ทุกสินค้าจะใช้เกณฑ์สำหรับสินค้าที่ไม่มียอดขาย</span>'}
                  · * = ยังไม่มีเกณฑ์ของตัวเอง ใช้เกณฑ์กลางของร้าน</div>
                ${data.ready ? '' : '<div class="error-box" style="margin-bottom:10px">ยังบันทึกเกณฑ์รายสินค้าไม่ได้: ให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0078) ก่อน — ดูผลการคำนวณได้</div>'}
                <div class="actions-row" style="justify-content:space-between;margin-bottom:8px">
                  <input type="search" class="search-input" data-q placeholder="🔍 ค้นหาชื่อสินค้าหรือบาร์โค้ด" value="${escapeHtml(q)}" style="max-width:320px">
                  <div class="tabs" role="group" aria-label="แสดงรายการ">${[['all', `ทั้งหมด (${count(lines.length)})`], ['changed', `เกณฑ์เปลี่ยน (${count(lines.filter(changed).length)})`], ['low', `จะใกล้หมด (${count(lines.filter(lowNow).length)})`]]
                      .map(([k, label]) => `<button type="button" class="tab-btn ${k === only ? 'active' : ''}" data-only="${k}">${label}</button>`).join('')}</div>
                </div>
                <div class="table-wrap" style="max-height:52vh"><table>
                  <thead><tr><th><input type="checkbox" data-all aria-label="เลือกทั้งหมดที่แสดง"></th><th>สินค้า</th><th class="num">คงเหลือ</th><th class="num">ขาย ${count(data.params.avg_days)} วัน</th><th class="num">เฉลี่ย/วัน</th><th class="num">เกณฑ์เดิม</th><th class="num">เกณฑ์ใหม่</th><th></th></tr></thead>
                  <tbody>${list.map(rowHtml).join('') || '<tr><td colspan="8" class="empty">ไม่มีรายการ</td></tr>'}</tbody>
                </table></div>` : (busy ? '' : '<div class="muted" style="margin-top:12px">กด คำนวณใหม่ เพื่อดูเกณฑ์ที่แนะนำ</div>')}
            </div>
            <div class="modal-foot">
              <span class="muted" style="font-size:12.5px">บันทึกเฉพาะรายการที่ติ๊กและเกณฑ์เปลี่ยน · ช่องว่าง = กลับไปใช้เกณฑ์กลางของร้าน</span>
              <div class="actions-row">
                <button type="button" class="btn secondary" data-x>ปิด</button>
                <button type="button" class="btn" data-save ${!data || !data.ready || !n || busy ? 'disabled' : ''}>บันทึกเกณฑ์ (${count(n)} รายการ)</button>
              </div>
            </div>
          </div>`;
        wire(list);
    }

    function wire(list) {
        el.querySelectorAll('[data-x]').forEach((b) => b.addEventListener('click', close));
        el.querySelectorAll('[data-p]').forEach((inp) => inp.addEventListener('change', () => {
            params = { ...(params || {}), [inp.dataset.p]: inp.value };
        }));
        el.querySelector('[data-calc]').addEventListener('click', () => {
            el.querySelectorAll('[data-p]').forEach((inp) => { params = { ...(params || {}), [inp.dataset.p]: inp.value }; });
            calc();
        });
        el.querySelector('[data-save]').addEventListener('click', save);
        const qi = el.querySelector('[data-q]');
        qi?.addEventListener('input', () => {
            q = qi.value.trim();
            const at = qi.selectionStart;
            draw();
            const again = el.querySelector('[data-q]');
            again.focus();
            again.setSelectionRange(at, at);
        });
        el.querySelectorAll('[data-only]').forEach((b) => b.addEventListener('click', () => { only = b.dataset.only; draw(); }));
        const all = el.querySelector('[data-all]');
        if (all) {
            all.checked = list.length > 0 && list.every((l) => l.on);
            all.addEventListener('change', () => { list.forEach((l) => { l.on = all.checked; }); draw(); });
        }
        const byId = new Map(lines.map((l) => [String(l.row.id), l]));
        const foot = () => {
            const b = el.querySelector('[data-save]');
            const n = toSave().length;
            b.textContent = `บันทึกเกณฑ์ (${count(n)} รายการ)`;
            b.disabled = !data?.ready || !n || busy;
        };
        el.querySelectorAll('tbody tr[data-id]').forEach((tr) => {
            const l = byId.get(tr.dataset.id);
            tr.querySelector('[data-on]').addEventListener('change', (e) => { l.on = e.target.checked; tr.classList.toggle('po-off', !l.on); foot(); });
            // Typing changes the row in place, so the box keeps its focus.
            tr.querySelector('[data-v]').addEventListener('input', (e) => {
                l.value = e.target.value.trim();
                tr.querySelector('[data-low]').innerHTML = lowNow(l) ? '<span class="pill warn">ใกล้หมด</span>' : '';
                foot();
            });
        });
    }

    async function save() {
        const items = toSave().map((l) => ({ id: l.row.id, low_stock_at: val(l) }));
        if (!items.length) return;
        busy = true;
        draw();
        try {
            const res = await api.put('/inventory/low-stock/lines', { items, ...data.params });
            close();
            alertDialog(`บันทึกเกณฑ์ใกล้หมดของสินค้า ${count(res.saved)} รายการแล้ว`, { title: 'บันทึกแล้ว' });
            onSaved();
        } catch (e) {
            busy = false;
            error = e.message;
            draw();
        }
    }

    draw();
    calc();
    return close;
}
