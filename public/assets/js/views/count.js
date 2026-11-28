import { api } from '../api.js';
import { money, count, escapeHtml, fmtDateTime, todayISO } from '../util.js';
import { openMobileCount } from './count-mobile.js';
import { attachmentUrl } from '../images.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';
import { printCountSheet } from '../print-slip.js';

const dayShift = (ymd, n) => { const x = new Date(`${ymd}T00:00:00Z`); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
// The count report's days.
const REPORT_PERIODS = [['today', 'วันนี้'], ['yesterday', 'เมื่อวาน'], ['7', '7 วันล่าสุด'], ['range', 'ระบุวันที่']];

/**
 * Stock counting is one product at a time (นับรายการ): each count is that product's own record.
 * Counting the whole store in an open / closed round (with a report to submit and approve) was
 * removed — rounds approved half counted or counted wrong put many products wrong at once, and an
 * open round held up other work. Old rounds are listed read-only as history.
 */
export async function render(container, { permissions, user }) {
    let error = null;
    let notice = null;
    const canCreate = permissions.includes('stockcount.create');
    const isAdmin = user?.role === 'admin';

    async function loadList() {
        const list = (await api.get('/stockcounts')).data.filter((c) => (c.kind || 'round') !== 'item');
        await loadItems();
        await loadReport();
        draw(list);
    }

    const ROUND_STATUS = { approved: '<span class="pill ok">อนุมัติแล้ว</span>', cancelled: '<span class="pill">ยกเลิก</span>' };

    function draw(history) {
        container.innerHTML = `
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        ${notice ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(notice)}</div>` : ''}
        ${itemsHtml(true)}
        ${reportHtml()}
        ${history && history.length ? `
          <section class="card card-pad">
            <details><summary style="cursor:pointer;font-weight:600">รอบตรวจนับทั้งร้าน (เลิกใช้แล้ว) — ${history.length} รอบล่าสุด</summary>
              <div class="muted" style="font-size:12.5px;margin-top:6px">การนับทั้งร้านเป็นรอบ (เปิด/ปิดรอบ ส่งรายงาน อนุมัติ) เลิกใช้แล้ว ตอนนี้นับทีละรายการ แต่ละครั้งเป็นการนับของสินค้านั้น ผู้ดูแลระบบและผู้จัดการดูได้ที่หน้าสต๊อกสินค้า (คอลัมน์นับล่าสุด) — รอบที่เปิดค้างไว้ถูกปิดโดยไม่ปรับสต๊อก</div>
              <div class="table-wrap" style="margin-top:10px"><table>
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>สถานะ</th></tr></thead>
                <tbody>${history.map((h) => `<tr><td>${escapeHtml(h.doc_no)}</td><td>${escapeHtml(h.count_date)}</td><td>${ROUND_STATUS[h.status] || '<span class="pill">ไม่ได้ใช้ (เลิกใช้แบบรอบ)</span>'}</td></tr>`).join('')}</tbody>
              </table></div>
            </details>
          </section>` : ''}`;
        bindItems(history);
        bindReport(() => draw(history));
    }

    // ---- นับรายการ: one product at a time with no round open (migration 0077) ----
    let itemData = null;   // { ready, pending, recent, reasons, approval_over, can_approve, user_id, open_round }

    // The count report: every item count made on the chosen days (only the products counted).
    const rep = { period: 'today', from: todayISO(), to: todayISO(), rows: null, diffOnly: false, error: null };
    function repSpan() {
        const t = todayISO();
        if (rep.period === 'today') return [t, t];
        if (rep.period === 'yesterday') return [dayShift(t, -1), dayShift(t, -1)];
        if (rep.period === '7') return [dayShift(t, -6), t];
        return rep.from <= rep.to ? [rep.from, rep.to] : [rep.to, rep.from];
    }
    async function loadReport() {
        const [f, t] = repSpan();
        try {
            const d = (await api.get(`/stockcounts/items?from=${f}&to=${t}`)).data;
            rep.rows = d.ready ? [...d.pending, ...d.recent] : [];
            rep.error = null;
        } catch (e) {
            rep.rows = [];
            rep.error = e.message;
        }
    }

    function reportHtml() {
        if (!itemData || !itemData.ready) return '';
        const [f, t] = repSpan();
        const all = rep.rows || [];
        const rows = rep.diffOnly ? all.filter((r) => r.diff_at_count) : all;
        rep.shown = rows;
        const done = all.filter((r) => r.status !== 'cancelled');
        const products = new Set(done.map((r) => r.product_id)).size;
        const same = done.filter((r) => !r.diff_at_count).length;
        const diffs = done.filter((r) => r.diff_at_count);
        const waiting = all.filter((r) => r.status === 'submitted').length;
        const net = diffs.reduce((s, r) => s + r.diff_at_count, 0);
        const netValue = diffs.reduce((s, r) => s + r.diff_at_count * Number(r.sale_price || 0), 0);
        const chip = (l, v, style = '') => `<div class="kpi"><div class="lbl">${l}</div><div class="val" style="font-size:20px;${style}">${v}</div></div>`;
        return `
        <section class="card">
          <div class="card-head" style="flex-wrap:wrap;gap:10px"><h2>รายงานการนับสินค้า</h2>
            <span class="muted" style="font-size:12.5px">แสดงเฉพาะรายการที่นับในวันที่เลือก ${f === t ? escapeHtml(f) : `${escapeHtml(f)} – ${escapeHtml(t)}`}</span></div>
          <div class="card-pad" style="padding-top:0">
            <div class="actions-row" style="gap:6px;align-items:center">
              ${REPORT_PERIODS.map(([k, l]) => `<button type="button" class="tab-btn ${rep.period === k ? 'active' : ''}" data-rep-period="${k}">${l}</button>`).join('')}
              ${rep.period === 'range' ? `<label class="field" style="flex-direction:row;align-items:center;gap:6px">ตั้งแต่ <input type="date" id="rep-from" value="${escapeHtml(rep.from)}" max="${todayISO()}"></label>
                <label class="field" style="flex-direction:row;align-items:center;gap:6px">ถึง <input type="date" id="rep-to" value="${escapeHtml(rep.to)}" max="${todayISO()}"></label>` : ''}
              <label class="cnt-check" style="margin-left:auto"><input type="checkbox" id="rep-diff" ${rep.diffOnly ? 'checked' : ''}> เฉพาะที่มีผลต่าง</label>
            </div>
            ${rep.error ? `<div class="error-box" style="margin-top:10px">${escapeHtml(rep.error)}</div>` : ''}
            <div class="stat-row" style="margin:12px 0 0">
              ${chip('สินค้าที่นับ', `${products} รายการ`)}
              ${chip('ตรงกับระบบ', same, 'color:var(--ok)')}
              ${chip('มีผลต่าง', diffs.length, diffs.length ? 'color:var(--bad)' : '')}
              ${chip('ผลต่างสุทธิ', `${signed(net)} ชิ้น`, net ? 'color:var(--bad)' : '')}
              ${chip('มูลค่าผลต่าง (ราคาขาย)', `฿ ${money(netValue)}`)}
              ${waiting ? chip('รอผู้จัดการรับรอง', waiting, 'color:var(--warn)') : ''}
            </div>
          </div>
          ${rep.rows === null ? '<div class="card-pad muted">กำลังโหลด...</div>' : rows.length ? `
          <div class="table-wrap" style="max-height:560px"><table>
            <thead><tr><th>เวลานับ / เลขที่ / ผู้นับ</th><th>สินค้า</th><th class="num">ในระบบตอนนับ</th><th class="num">นับได้</th><th>ผลต่าง</th><th>รายละเอียดการนับ</th><th>รูป</th><th>ผล</th></tr></thead>
            <tbody>${rows.map((r, i) => `<tr${r.diff_at_count && r.status !== 'cancelled' ? ' style="box-shadow:inset 3px 0 0 var(--bad)"' : ''}>
              <td style="white-space:nowrap">${escapeHtml(fmtDateTime(r.counted_at || r.created_at))}<div class="muted" style="font-size:12px">${escapeHtml(r.doc_no)} · ${escapeHtml(r.counter_name || '-')}</div></td>
              <td>${escapeHtml(r.name)}<div class="muted" style="font-size:12px">${escapeHtml(r.consignor_name)}${r.barcode ? ` · ${escapeHtml(r.barcode)}` : ''}</div></td>
              <td class="num">${r.count_system_qty ?? r.system_qty}</td><td class="num"><b>${r.count_qty ?? r.counted_qty}</b></td>
              <td>${r.diff_at_count ? `<span class="pill bad">${signed(r.diff_at_count)}</span>` : '<span class="pill ok">ตรง</span>'}</td>
              <td>${itemDetail(r)}</td>
              <td>${itemPhotos(r)}</td>
              <td>${r.status === 'submitted' ? '<span class="pill warn">รอผู้จัดการรับรอง</span>' : itemStatus(r)}${editedHtml(r)}
                ${r.status === 'approved' && itemData.can_edit ? `<div style="margin-top:4px"><button type="button" class="btn secondary sm" data-edit-count="${i}" title="แก้จำนวนที่นับได้ (เช่น กรอกผิด) ต้องระบุเหตุผล">✎ แก้ไขการนับ</button></div>` : ''}</td>
            </tr>`).join('')}</tbody>
          </table></div>` : `<div class="empty" style="padding:16px">${rep.diffOnly ? 'ไม่มีรายการที่มีผลต่างในวันที่เลือก' : 'ยังไม่มีการนับในวันที่เลือก'}</div>`}
        </section>`;
    }

    function bindReport(redraw) {
        const reload = async () => { await loadReport(); redraw(); };
        container.querySelectorAll('[data-rep-period]').forEach((b) => b.addEventListener('click', () => { rep.period = b.dataset.repPeriod; rep.rows = null; redraw(); reload(); }));
        container.querySelector('#rep-from')?.addEventListener('change', (e) => { if (e.target.value) { rep.from = e.target.value; reload(); } });
        container.querySelector('#rep-to')?.addEventListener('change', (e) => { if (e.target.value) { rep.to = e.target.value; reload(); } });
        container.querySelector('#rep-diff')?.addEventListener('change', (e) => { rep.diffOnly = e.target.checked; redraw(); });
        container.querySelectorAll('[data-edit-count]').forEach((b) => b.addEventListener('click', () => editCount(rep.shown[Number(b.dataset.editCount)], redraw)));
    }

    /** A count corrected after it took effect: what was counted at first, who changed it and why. */
    function editedHtml(r) {
        if (!r.edited_at) return '';
        return `<div style="font-size:12px;margin-top:4px"><span class="pill warn">แก้ไขแล้ว</span>
          <div class="muted">เดิมนับได้ ${r.original_counted_qty} · โดย ${escapeHtml(r.editor_name || '-')} · ${escapeHtml(fmtDateTime(r.edited_at))}<br>เหตุผล: ${escapeHtml(r.edit_reason || '-')}</div></div>`;
    }

    /** Admin / manager: correct what was counted on an approved count, with a reason. */
    async function editCount(r, redraw) {
        const shown = Number(r.count_qty ?? r.counted_qty);
        const typed = await promptDialog(
            `${r.name} — ${r.doc_no} นับโดย ${r.counter_name || '-'} ในระบบตอนนับ ${r.count_system_qty ?? r.system_qty} นับได้ ${shown}\n\nจำนวนที่นับได้ที่ถูกต้อง`,
            { title: `แก้ไขการนับ ${r.doc_no}`, okText: 'ถัดไป', value: String(shown), required: 'กรุณากรอกจำนวน' }
        );
        if (typed === null) return;
        const counted = Number(typed.trim());
        if (!Number.isInteger(counted) || counted < 0) {
            error = 'จำนวนที่นับต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป';
            notice = null;
            redraw();
            return;
        }
        if (counted === shown) return;
        const delta = counted - shown;
        const effect = r.latest
            ? `สต๊อกตอนนี้จะเปลี่ยน ${signed(delta)} ชิ้น (จาก ${r.qty_stock} เป็น ${Number(r.qty_stock) + delta})`
            : 'มีการนับสินค้านี้ครั้งหลังกว่านี้แล้ว สต๊อกตอนนี้จะไม่เปลี่ยน (เปลี่ยนเฉพาะยอดในบัตรสินค้าช่วงก่อนการนับครั้งถัดไป)';
        const reason = await promptDialog(
            `${r.name}: นับได้ ${shown} → ${counted} (${signed(delta)})\n${effect}\n\nระบุเหตุผลที่แก้ไข`,
            { title: `ยืนยันแก้ไขการนับ ${r.doc_no}?`, okText: 'บันทึกการแก้ไข', placeholder: 'เหตุผล (ต้องระบุ) เช่น กรอกจำนวนผิด นับซ้ำแล้วได้ 12', required: 'กรุณาระบุเหตุผลที่แก้ไข', multiline: true, danger: true }
        );
        if (reason === null) return;
        try {
            const res = (await api.post(`/stockcounts/lines/${r.line_id}/edit`, { counted, reason: reason.trim() })).data;
            notice = `แก้ไขการนับ ${res.doc_no} แล้ว — ${res.name} นับได้ ${res.counted_before} → ${res.counted_after}`
                + (res.stock_after !== res.stock_before ? ` สต๊อกเปลี่ยนจาก ${res.stock_before} เป็น ${res.stock_after} ชิ้น` : ' สต๊อกตอนนี้ไม่เปลี่ยน');
            error = null;
        } catch (e) {
            error = e.message;
            notice = null;
        }
        await loadItems();
        await loadReport();
        redraw();
    }

    async function loadItems() {
        try {
            itemData = (await api.get('/stockcounts/items')).data;
        } catch {
            itemData = null;
        }
    }

    const signed = (n) => (n > 0 ? `+${n}` : String(n));

    function itemPhotos(r) {
        return `<div class="cnt-photo-row">${(r.photos || []).map((p) => (p.token
            ? `<a href="${attachmentUrl(p.token)}" target="_blank" rel="noopener" title="${escapeHtml(p.note || '')}"><img src="${attachmentUrl(p.token)}" alt="รูปประกอบการนับ"></a>`
            : '<span class="pill muted" title="ลบอัตโนมัติเมื่อครบ 30 วัน">รูปถูกลบแล้ว</span>')).join('') || '<span class="muted" style="font-size:12px">ไม่มีรูป</span>'}</div>`;
    }

    /** Where/how it was counted, and why it differs. */
    function itemDetail(r) {
        return `<div style="font-size:12.5px;line-height:1.5">
          ${r.count_place ? `<div>นับที่: ${escapeHtml(r.count_place)}</div>` : ''}
          ${r.count_method ? `<div class="muted">วิธีนับ: ${escapeHtml(r.count_method)}</div>` : ''}
          ${r.reason_label ? `<div><b>สาเหตุ:</b> ${escapeHtml(r.reason_label)}</div>` : ''}
          ${r.diff_detail ? `<div>${escapeHtml(r.diff_detail)}</div>` : ''}
          ${r.count_note ? `<div class="muted">หมายเหตุ: ${escapeHtml(r.count_note)}</div>` : ''}
        </div>`;
    }

    function itemStatus(r) {
        if (r.status === 'cancelled') {
            return `<span class="pill">ไม่รับรอง / ยกเลิก</span><div style="font-size:12px" class="muted">${escapeHtml(r.canceller_name || '-')} · ${escapeHtml(fmtDateTime(r.cancelled_at))}<br>เหตุผล: ${escapeHtml(r.cancel_reason || '-')}</div>`;
        }
        const certified = Math.abs(r.diff_at_count) > itemData.approval_over;
        return `<span class="pill ok">${certified ? 'รับรองแล้ว' : 'ปรับสต๊อกแล้ว'}</span>${certified ? `<div style="font-size:12px" class="muted">โดย ${escapeHtml(r.approver_name || '-')} · ${escapeHtml(fmtDateTime(r.approved_at))}<br>สต๊อก ${r.system_qty} → ${r.counted_qty}${r.review_note ? `<br>ความเห็น: ${escapeHtml(r.review_note)}` : ''}</div>` : ''}`;
    }

    /** The item-count card: start counting (no round open), counts waiting for a manager, and the latest ones. */
    function itemsHtml(noRound) {
        if (!itemData) return '';
        if (!itemData.ready) {
            return noRound && canCreate ? '<div class="pill warn" style="font-size:13px;padding:8px 14px">นับสินค้าเป็นรายการ (ไม่ต้องเปิดรอบ) ต้องให้ผู้ดูแลระบบรันการปรับฐานข้อมูล (migration 0077) ก่อน</div>' : '';
        }
        const pend = itemData.pending;
        if (!noRound && !pend.length) return '';
        const over = itemData.approval_over;
        return `
        <section class="card">
          <div class="card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><b>นับสินค้าเป็นรายการ (ไม่ต้องเปิดรอบ)</b>
              <div class="muted" style="font-size:13px">สแกน/ค้นหาสินค้าแล้วนับทีละรายการ ถ่ายรูป ระบุที่นับ · ยอดต่างจากระบบต้องเลือกสาเหตุและอธิบายรายละเอียด · ต่างไม่เกิน ${over} ชิ้นปรับสต๊อกทันที <b>ต่างเกิน ${over} ชิ้นต้องให้ผู้จัดการรับรองก่อนจึงมีผล</b></div></div>
            <div class="actions-row">
              ${canCreate ? '<button type="button" class="btn secondary" id="btn-count-sheet" style="font-size:15px;padding:12px 18px" title="พิมพ์ใบตรวจนับสินค้าสำหรับนับด้วยกระดาษ: ลำดับ ชื่อสินค้า/บาร์โค้ด ช่องจำนวนที่นับได้ และหมายเหตุ กำหนดวันที่ตรวจนับ เดือนที่นับ และชื่อผู้นับ/ผู้ตรวจสอบได้ก่อนพิมพ์">🖨️ พิมพ์ใบตรวจนับ</button>' : ''}
              ${noRound && canCreate ? '<button type="button" class="btn" id="btn-item-count" style="font-size:15px;padding:12px 18px">🔢 นับรายการ</button>' : ''}
            </div>
          </div>
          ${pend.length ? `
            <div class="card-pad" style="padding-top:0"><b style="color:var(--bad)">รอผู้จัดการรับรอง (${pend.length})</b>
              <span class="muted" style="font-size:12.5px"> — สต๊อกยังไม่เปลี่ยนจนกว่าจะรับรอง · รับรองแล้วสต๊อกจะเปลี่ยนตามผลต่างที่นับได้ (ถ้ามีขาย/รับเข้าหลังนับ ยอดนั้นยังอยู่) · ผู้นับรับรองการนับของตัวเองไม่ได้</span></div>
            <div class="table-wrap"><table>
              <thead><tr><th>เลขที่ / ผู้นับ</th><th>สินค้า</th><th class="num">ในระบบตอนนับ</th><th class="num">นับได้</th><th>ผลต่าง</th><th>รายละเอียดการนับ</th><th>รูป</th><th class="num">สต๊อกตอนนี้ → หลังรับรอง</th><th></th></tr></thead>
              <tbody>${pend.map((r, i) => {
                  const mine = Number(r.counted_by) === Number(itemData.user_id);
                  const canCertify = itemData.can_approve && (!mine || isAdmin);
                  return `<tr style="background:var(--bad-bg)">
                    <td>${escapeHtml(r.doc_no)}<div class="muted" style="font-size:12px">${escapeHtml(r.counter_name || '-')}<br>${escapeHtml(fmtDateTime(r.counted_at || r.created_at))}</div></td>
                    <td>${escapeHtml(r.name)}<div class="muted" style="font-size:12px">${escapeHtml(r.consignor_name)}</div></td>
                    <td class="num">${r.count_system_qty}</td><td class="num">${r.count_qty}</td>
                    <td><span class="pill bad">${signed(r.diff_at_count)}</span></td>
                    <td>${itemDetail(r)}</td>
                    <td>${itemPhotos(r)}</td>
                    <td class="num">${r.qty_stock} → <b>${r.stock_if_approved}</b></td>
                    <td style="white-space:nowrap">
                      ${canCertify ? `<button type="button" class="btn sm" data-item-ok="${i}">รับรอง</button>` : ''}
                      ${itemData.can_approve && !mine ? `<button type="button" class="btn secondary sm" data-item-no="${i}">ไม่รับรอง</button>` : ''}
                      ${mine ? `<button type="button" class="btn secondary sm" data-item-no="${i}">ถอนการนับ</button>` : ''}
                      ${!canCertify && !mine ? '<span class="pill warn">รอผู้จัดการ</span>' : ''}
                    </td>
                  </tr>`;
              }).join('')}</tbody>
            </table></div>` : ''}
        </section>`;
    }

    function bindItems(history) {
        const redraw = () => draw(history);
        container.querySelector('#btn-count-sheet')?.addEventListener('click', () => openCountSheet());
        container.querySelector('#btn-item-count')?.addEventListener('click', () => {
            openMobileCount({ quick: true, stockCount: null, items: [], onClose: () => loadList() });
        });
        container.querySelectorAll('[data-item-ok]').forEach((b) => b.addEventListener('click', () => certifyItem(itemData.pending[Number(b.dataset.itemOk)], redraw)));
        container.querySelectorAll('[data-item-no]').forEach((b) => b.addEventListener('click', () => rejectItem(itemData.pending[Number(b.dataset.itemNo)], redraw)));
    }

    /**
     * Paper count sheet (ใบตรวจนับ): the monthly count has no fixed day (a day the shop is closed,
     * agreed by the manager and staff, before or after month end), so the count date (default: the
     * day printed), the month it is for and who counts / checks are set here before printing.
     */
    async function openCountSheet() {
        let all;
        try {
            all = (await api.get('/stockcounts/sheet')).data;
        } catch (e) {
            await alertDialog(e.message, { tone: 'error' });
            return;
        }
        const today = todayISO();
        const thColl = new Intl.Collator('th', { numeric: true, sensitivity: 'base' });
        // Counted in the first half of a month = most likely last month's count.
        const monthOf = (iso) => {
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(Date.UTC(y, d <= 15 ? m - 2 : m - 1, 1)).toISOString().slice(0, 7);
        };
        const store = all.filter((p) => !p.consigned).length;
        const html = `
          <div style="display:grid;gap:10px;margin-top:6px">
            <label>วันที่ตรวจนับ (พิมพ์ที่หัวกระดาษทุกหน้า ค่าเริ่มต้นคือวันที่พิมพ์)<br><input type="date" data-cs-date value="${today}" style="width:100%"></label>
            <label>ตรวจนับประจำเดือน<br><input type="month" data-cs-month value="${monthOf(today)}" style="width:100%"></label>
            <label>ชื่อผู้นับ<br><input type="text" data-cs-counter placeholder="เว้นว่าง = เขียนชื่อเองบนกระดาษ" autocomplete="off" style="width:100%"></label>
            <label>ชื่อผู้ตรวจสอบ<br><input type="text" data-cs-checker placeholder="เว้นว่าง = เขียนชื่อเองบนกระดาษ" autocomplete="off" style="width:100%"></label>
            <div class="muted" style="font-size:12px">วันตรวจนับไม่ตายตัว (ตรงกับวันหยุดขายของร้าน ก่อนหรือหลังสิ้นเดือน) ตามที่ผู้จัดการและพนักงานตกลงกัน</div>
            <label><input type="radio" name="cs-scope" value="all" checked> สินค้าทั้งหมด ${count(all.length)} รายการ</label>
            <label><input type="radio" name="cs-scope" value="store"> เฉพาะสินค้าของร้าน (ไม่รวมฝากขาย) ${count(store)} รายการ</label>
            <label><input type="radio" name="cs-scope" value="consign"> เฉพาะสินค้าฝากขาย ${count(all.length - store)} รายการ</label>
            <label><input type="checkbox" data-cs-instock> เฉพาะสินค้าที่ในระบบยังมีคงเหลือ</label>
          </div>`;
        const ask = confirmDialog('', { title: '🖨️ พิมพ์ใบตรวจนับสินค้า', okText: 'พิมพ์', html, width: 460 });
        // The dialog is on screen now; its fields keep their values after it closes.
        const box = [...document.querySelectorAll('.app-dialog')].pop();
        const f = (sel) => box.querySelector(sel);
        const date = f('[data-cs-date]');
        const month = f('[data-cs-month]');
        let monthTouched = false;
        month.addEventListener('input', () => { monthTouched = true; });
        date.addEventListener('input', () => { if (!monthTouched && date.value) month.value = monthOf(date.value); });
        const scopeEls = [...box.querySelectorAll('input[name="cs-scope"]')];
        const inStock = f('[data-cs-instock]');
        const counter = f('[data-cs-counter]');
        const checker = f('[data-cs-checker]');
        if (!(await ask)) return;
        const scopeKey = scopeEls.find((r) => r.checked)?.value || 'all';
        let list = scopeKey === 'store' ? all.filter((p) => !p.consigned) : scopeKey === 'consign' ? all.filter((p) => p.consigned) : all;
        if (inStock.checked) list = list.filter((p) => p.qty_stock > 0);
        list = [...list].sort((a, b) => thColl.compare(a.name || '', b.name || ''));
        if (!list.length) {
            await alertDialog('ไม่มีสินค้าที่จะพิมพ์');
            return;
        }
        const scope = [{ all: 'สินค้าทั้งหมด', store: 'เฉพาะสินค้าของร้าน', consign: 'เฉพาะสินค้าฝากขาย' }[scopeKey], inStock.checked ? 'ที่ยังมีคงเหลือ' : '']
            .filter(Boolean).join(' ');
        printCountSheet({
            items: list.map((p) => ({ name: p.name, barcode: p.barcode })),
            countDate: date.value || today, month: month.value || '', scope,
            counter: counter.value.trim(), checker: checker.value.trim(),
        });
    }

    async function certifyItem(r, redraw) {
        const note = await promptDialog(
            `${r.name}: ${r.counter_name || '-'} นับได้ ${r.count_qty} ในระบบตอนนับ ${r.count_system_qty} (${signed(r.diff_at_count)}) สาเหตุ: ${r.reason_label || '-'} — ${r.diff_detail || ''}\n\nรับรองแล้วสต๊อกจะเปลี่ยนจาก ${r.qty_stock} เป็น ${r.stock_if_approved} ชิ้น`,
            { title: `รับรองการนับ ${r.doc_no}?`, okText: 'รับรองและปรับสต๊อก', placeholder: 'ความเห็นผู้รับรอง (ถ้ามี) เช่น ตรวจนับซ้ำแล้วตรงกัน', multiline: true }
        );
        if (note === null) return;
        try {
            const res = (await api.post(`/stockcounts/items/${r.id}/approve`, { note })).data;
            notice = `รับรอง ${res.doc_no} แล้ว — สต๊อก ${r.name} เปลี่ยนจาก ${res.stock_before} เป็น ${res.stock_after} ชิ้น`;
            error = null;
        } catch (e) {
            error = e.message;
            notice = null;
        }
        await loadItems();
        await loadReport();
        redraw();
    }

    async function rejectItem(r, redraw) {
        const mine = Number(r.counted_by) === Number(itemData.user_id);
        const reason = await promptDialog(
            mine ? `ถอนการนับ ${r.name} (${signed(r.diff_at_count)}) สต๊อกจะไม่เปลี่ยน` : `ไม่รับรองการนับ ${r.name} ของ ${r.counter_name || '-'} (${signed(r.diff_at_count)}) สต๊อกจะไม่เปลี่ยน`,
            { title: `${mine ? 'ถอนการนับ' : 'ไม่รับรองการนับ'} ${r.doc_no}?`, okText: mine ? 'ถอนการนับ' : 'ไม่รับรอง', placeholder: 'เหตุผล (ต้องระบุ) เช่น ให้นับใหม่อีกครั้ง', required: 'กรุณาระบุเหตุผล', multiline: true, danger: true }
        );
        if (reason === null) return;
        try {
            const res = (await api.post(`/stockcounts/items/${r.id}/reject`, { reason })).data;
            notice = `${mine ? 'ถอนการนับ' : 'ไม่รับรองการนับ'} ${res.doc_no} แล้ว — สต๊อกไม่เปลี่ยน`;
            error = null;
        } catch (e) {
            error = e.message;
            notice = null;
        }
        await loadItems();
        await loadReport();
        redraw();
    }

    await loadList();
}
