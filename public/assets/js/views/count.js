import { api } from '../api.js';
import { money, escapeHtml, fmtDateTime } from '../util.js';
import { openMobileCount, COUNT_PHOTO_SIZE } from './count-mobile.js';
import { attachmentUrl, fileToDataUrl, thumbDataUrl } from '../images.js';
import { confirmDialog, promptDialog } from '../dialog.js';

// Which lines the table shows, besides the search words.
const SHOWS = [
    ['all', 'ทั้งหมด'],
    ['todo', 'ยังไม่นับ'],
    ['done', 'นับแล้ว'],
    ['diff', 'มีผลต่าง'],
    ['unsaved', 'ยังไม่บันทึก'],
];

export async function render(container, { permissions, user }) {
    let current = null;
    let items = [];
    let error = null;
    let query = '';
    let show = 'all';
    let consignedOnly = false;
    let showDiscontinued = false;
    let discontinued = [];
    let notice = null;
    const canApprove = permissions.includes('stockcount.approve');
    const canCreate = permissions.includes('stockcount.create');
    const isAdmin = user?.role === 'admin';

    async function loadList() {
        const list = (await api.get('/stockcounts')).data;
        await loadItems();
        const active = list.find((c) => (c.kind || 'round') !== 'item' && (c.status === 'draft' || c.status === 'submitted'));
        if (active) {
            await loadOne(active.id);
        } else {
            current = null;
            items = [];
            draw(list);
        }
    }

    async function loadOne(id) {
        const res = await api.get(`/stockcounts/${id}`);
        current = res.data.stock_count;
        items = res.data.items;
        draw();
    }

    function draw(history) {
        if (!current) {
            container.innerHTML = `
            ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
            ${notice ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(notice)}</div>` : ''}
            ${itemsHtml(true)}
            <section class="card card-pad" style="text-align:center">
              <p class="muted">ยังไม่มีรอบตรวจนับที่กำลังดำเนินการ — นับทั้งร้านพร้อมกันให้เริ่มรอบตรวจนับ</p>
              <button type="button" class="btn" id="btn-start">เริ่มรอบตรวจนับใหม่</button>
              ${history && history.length ? `
                <div class="table-wrap" style="margin-top:20px;text-align:left"><table>
                  <thead><tr><th>เลขที่</th><th>วันที่</th><th>สถานะ</th></tr></thead>
                  <tbody>${history.map((h) => `<tr><td>${escapeHtml(h.doc_no)}</td><td>${escapeHtml(h.count_date)}</td><td>${h.status === 'cancelled' ? '<span class="pill">ยกเลิก</span>' : '<span class="pill ok">เสร็จสิ้น</span>'}</td></tr>`).join('')}</tbody>
                </table></div>` : ''}
            </section>`;
            container.querySelector('#btn-start').addEventListener('click', async () => {
                try {
                    const res = await api.post('/stockcounts/start');
                    error = null;
                    notice = null;
                    await loadOne(res.data.id);
                } catch (e) {
                    error = e.message;
                    draw(history);
                }
            });
            bindItems(history);
            return;
        }

        const { diffCount, diffValue, countedN } = totals();
        const manage = current.status === 'draft' && isAdmin;

        container.innerHTML = `
        <div class="stat-row">
          <div class="kpi"><div class="lbl">รอบตรวจนับ</div><div class="val" style="font-size:16px">${escapeHtml(current.doc_no)}</div><div class="sub">${escapeHtml(current.count_date)}</div></div>
          <div class="kpi"><div class="lbl">รายการที่นับแล้ว</div><div class="val" data-k="counted">${countedN}/${items.length}</div><div class="sub">ไม่ต้องนับครบทุกรายการ</div></div>
          <div class="kpi"><div class="lbl">พบผลต่าง</div><div class="val" data-k="diff" style="color:${diffCount ? 'var(--bad)' : 'inherit'}">${diffCount}</div></div>
          <div class="kpi"><div class="lbl">มูลค่าผลต่างสุทธิ</div><div class="val" data-k="value">฿ ${money(diffValue)}</div></div>
        </div>
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        ${notice ? `<div class="pill ok" style="font-size:13px;padding:8px 14px">${escapeHtml(notice)}</div>` : ''}
        ${itemsHtml(false)}
        ${current.status === 'draft' ? `
          <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><b>โหมดนับด้วยมือถือ</b><div class="muted" style="font-size:13px">สแกนบาร์โค้ดด้วยกล้อง ปุ่มกดตัวเลขขนาดใหญ่ ถ่ายภาพสินค้า และค้นหาสินค้าเพื่อผูกบาร์โค้ด — บันทึกทันทีทีละรายการ</div></div>
            <button type="button" class="btn" id="btn-mobile" style="font-size:15px;padding:12px 18px">📱 เปิดโหมดนับด้วยมือถือ</button>
          </section>` : ''}
        ${current.status === 'submitted' ? `
          <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><b>ส่งรายงานผลต่างแล้ว รออนุมัติ</b><div class="muted" style="font-size:13px">รอบนี้ล็อกการนับไว้ จึงไม่มีโหมดนับด้วยมือถือและแก้จำนวนไม่ได้ ${canApprove ? 'ถ้ายังนับไม่เสร็จ กด <b>ส่งกลับไปนับต่อ</b> ตัวเลขและรูปที่นับไว้ยังอยู่ครบ' : 'ถ้ายังนับไม่เสร็จ ให้ผู้จัดการร้านกด ส่งกลับไปนับต่อ'}</div></div>
            ${canApprove ? '<button type="button" class="btn secondary" id="btn-reopen" style="font-size:15px;padding:12px 18px">↩ ส่งกลับไปนับต่อ</button>' : ''}
          </section>` : ''}
        <section class="card">
          <div class="card-pad cnt-filter">
            <input type="search" id="cnt-q" class="search-input" placeholder="🔍 ค้นหาชื่อสินค้า ผู้ฝาก หรือบาร์โค้ด" value="${escapeHtml(query)}" autocomplete="off">
            <div class="tabs" role="group" aria-label="แสดงรายการ">
              ${SHOWS.map(([k, label]) => `<button type="button" class="tab-btn${show === k ? ' active' : ''}" data-show="${k}">${label}</button>`).join('')}
            </div>
            <label class="cnt-check"><input type="checkbox" id="cnt-consigned" ${consignedOnly ? 'checked' : ''}> เฉพาะสินค้าฝากขาย</label>
            <label class="cnt-check"><input type="checkbox" id="cnt-discontinued" ${showDiscontinued ? 'checked' : ''}> แสดงสินค้าที่เลิกจำหน่าย</label>
            <span class="muted" data-k="shown" style="font-size:13px"></span>
          </div>
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th>ผู้ฝาก</th><th class="num">ในระบบ</th><th class="num">นับได้จริง</th><th>ผลต่าง</th><th title="รูปถ่ายตอนนับ เก็บไว้ตรวจสอบ 30 วัน">รูปประกอบการนับ</th>${current.status === 'draft' ? '<th></th>' : ''}${manage ? '<th>ผู้ดูแลระบบ</th>' : ''}</tr></thead>
            <tbody>
              ${items.map((it, i) => {
                  const diff = diffOf(it);
                  return `<tr data-tr="${i}" style="${diff ? 'background:var(--bad-bg)' : ''}" ${matches(it) ? '' : 'hidden'}>
                    <td>${escapeHtml(it.name)}</td><td class="muted">${escapeHtml(it.consignor_name)}</td>
                    <td class="num">${it.system_qty}</td>
                    <td class="num"><input type="number" min="0" value="${counted(it) ?? ''}" placeholder="ไม่ได้นับ" data-idx="${i}" style="width:90px;text-align:right" ${current.status !== 'draft' || it.returned ? 'disabled' : ''}>
                      ${it.returned ? `<span class="pill warn" title="${escapeHtml(it.note || '')}">คืนให้ผู้ฝากแล้ว</span>` : ''}
                      ${current.status === 'draft' && !it.returned ? `<button type="button" class="btn secondary sm" data-zero="${i}" title="ขายหมดแล้ว ไม่มีของให้นับ: บันทึกว่านับได้ 0" ${counted(it) === 0 ? 'hidden' : ''}>หมด (0)</button>` : ''}</td>
                    <td data-diff>${diffHtml(diff)}</td>
                    <td class="cnt-photos">${photosHtml(it, i)}</td>
                    ${current.status === 'draft' ? `<td data-save-cell>${saveHtml(it, i)}</td>` : ''}
                    ${manage ? `<td>${manageHtml(it, i)}</td>` : ''}
                  </tr>`;
              }).join('')}
            </tbody>
          </table></div>
          <div class="empty" data-k="none" hidden>ไม่พบรายการที่ตรงกับการค้นหา</div>
        </section>
        ${showDiscontinued ? discontinuedHtml() : ''}
        <p class="muted" style="font-size:12px">ทุกรายการที่นับควรมีรูปประกอบการนับ (โหมดนับด้วยมือถือบังคับถ่ายรูปทุกครั้งที่บันทึก) · รูปย่อให้ดูเต็มจอได้ชัด และลบอัตโนมัติเมื่อครบ 30 วัน<br>กรอกเฉพาะรายการที่นับ ช่องที่เว้นว่างถือว่าไม่ได้นับ · กด <b>บันทึก</b> ท้ายแถว (หรือ Enter) เพื่อบันทึกทีละรายการแล้วไปช่องถัดไป · สินค้าที่ขายหมดแล้วไม่มีของให้นับ กดปุ่ม <b>หมด (0)</b> เพื่อบันทึกว่านับได้ 0 ระบบจะไม่ปรับสต๊อกรายการนั้น (ลบตัวเลขออกเพื่อยกเลิกการนับรายการนั้น)<br>เมื่อพบผลต่าง ระบบจะส่งรายงานให้ผู้จัดการร้านและครูที่ปรึกษาอนุมัติก่อนปรับสต๊อก</p>
        <div class="actions-row">
          ${current.status === 'draft' ? `
            <button type="button" class="btn secondary" id="btn-save">บันทึกร่างไว้ก่อน</button>
            <button type="button" class="btn" id="btn-submit" ${diffCount ? '' : 'disabled'}>ส่งรายงานผลต่างให้ผู้จัดการร้าน</button>
          ` : ''}
          ${current.status === 'submitted' && canApprove ? `<button type="button" class="btn" id="btn-approve">อนุมัติและปรับสต๊อก</button>` : ''}
          ${(current.status === 'draft' && canCreate) || (current.status === 'submitted' && canApprove) ? `<button type="button" class="btn danger" id="btn-cancel">ยกเลิกรอบตรวจนับ</button>` : ''}
          ${current.status === 'submitted' && !canApprove ? `<span class="pill warn">ส่งรายงานแล้ว รออนุมัติจากผู้จัดการร้าน</span>` : ''}
        </div>`;

        container.querySelectorAll('[data-idx]').forEach((inp) => inp.addEventListener('input', (e) => {
            const i = Number(e.target.dataset.idx);
            items[i]._pendingCounted = e.target.value.trim();
            // Update the row and totals in place so the box keeps its focus.
            const diff = diffOf(items[i]);
            const tr = container.querySelector(`tr[data-tr="${i}"]`);
            tr.style.background = diff ? 'var(--bad-bg)' : '';
            tr.querySelector('[data-diff]').innerHTML = diffHtml(diff);
            const t = totals();
            container.querySelector('[data-k="counted"]').textContent = `${t.countedN}/${items.length}`;
            const k = container.querySelector('[data-k="diff"]');
            k.textContent = t.diffCount;
            k.style.color = t.diffCount ? 'var(--bad)' : 'inherit';
            container.querySelector('[data-k="value"]').textContent = `฿ ${money(t.diffValue)}`;
            const zb = tr.querySelector('[data-zero]');
            if (zb) zb.hidden = counted(items[i]) === 0;
            const sb = container.querySelector('#btn-submit');
            if (sb) sb.disabled = !t.diffCount;
            const cell = tr.querySelector('[data-save-cell]');
            if (cell) cell.innerHTML = saveHtml(items[i], i);
        }));
        // Enter (or ตกลง on the on-screen pad) saves the line and goes on to the next one shown.
        container.querySelectorAll('[data-idx]').forEach((inp) => inp.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            saveRow(Number(inp.dataset.idx), true);
        }));
        container.querySelector('tbody').addEventListener('click', (e) => {
            const b = e.target.closest('[data-save]');
            if (b) saveRow(Number(b.dataset.save), false);
        });
        const q = container.querySelector('#cnt-q');
        q.addEventListener('input', () => { query = q.value; applyFilter(); });
        container.querySelectorAll('[data-show]').forEach((b) => b.addEventListener('click', () => {
            show = b.dataset.show;
            container.querySelectorAll('[data-show]').forEach((x) => x.classList.toggle('active', x === b));
            applyFilter();
        }));
        container.querySelector('#cnt-consigned').addEventListener('change', (e) => { consignedOnly = e.target.checked; applyFilter(); });
        container.querySelector('#cnt-discontinued').addEventListener('change', (e) => toggleDiscontinued(e.target.checked));
        container.querySelectorAll('[data-return-all]').forEach((b) => b.addEventListener('click', () => returnAll(items[Number(b.dataset.returnAll)])));
        container.querySelectorAll('[data-discontinue]').forEach((b) => b.addEventListener('click', () => discontinue(items[Number(b.dataset.discontinue)])));
        container.querySelectorAll('[data-restore]').forEach((b) => b.addEventListener('click', () => restore(discontinued[Number(b.dataset.restore)])));
        applyFilter();
        // Sold out: nothing on the shelf to scan, so record a count of 0 in one click.
        container.querySelectorAll('[data-zero]').forEach((b) => b.addEventListener('click', () => {
            const inp = container.querySelector(`[data-idx="${b.dataset.zero}"]`);
            inp.value = '0';
            inp.dispatchEvent(new Event('input'));
        }));
        container.querySelectorAll('[data-photo]').forEach((inp) => inp.addEventListener('change', () => {
            const file = inp.files[0];
            if (file) addPhoto(Number(inp.dataset.photo), file);
        }));
        const mobileBtn = container.querySelector('#btn-mobile');
        if (mobileBtn) mobileBtn.addEventListener('click', () => openMobileCount({
            stockCount: current,
            items,
            onClose: () => loadOne(current.id),
        }));
        const saveBtn = container.querySelector('#btn-save');
        if (saveBtn) saveBtn.addEventListener('click', () => saveCounts(false));
        const submitBtn = container.querySelector('#btn-submit');
        if (submitBtn) submitBtn.addEventListener('click', () => saveCounts(true));
        const approveBtn = container.querySelector('#btn-approve');
        if (approveBtn) approveBtn.addEventListener('click', approveCount);
        const reopenBtn = container.querySelector('#btn-reopen');
        if (reopenBtn) reopenBtn.addEventListener('click', reopenCount);
        const cancelBtn = container.querySelector('#btn-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelCount);
        bindItems();
    }

    // ---- นับรายการ: one product at a time with no round open (migration 0077) ----
    let itemData = null;   // { ready, pending, recent, reasons, approval_over, can_approve, user_id, open_round }
    let showRecent = false;

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
            ${noRound && canCreate ? '<button type="button" class="btn" id="btn-item-count" style="font-size:15px;padding:12px 18px">🔢 นับรายการ</button>' : ''}
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
          ${noRound && itemData.recent.length ? `
            <div class="card-pad" style="padding-top:${pend.length ? '12px' : '0'}">
              <button type="button" class="btn secondary sm" id="btn-item-recent">${showRecent ? '▾' : '▸'} ประวัติการนับรายการ (${itemData.recent.length} ครั้งล่าสุด)</button>
            </div>
            ${showRecent ? `<div class="table-wrap"><table>
              <thead><tr><th>เลขที่ / ผู้นับ</th><th>สินค้า</th><th class="num">ในระบบตอนนับ</th><th class="num">นับได้</th><th>ผลต่าง</th><th>รายละเอียดการนับ</th><th>รูป</th><th>ผล</th></tr></thead>
              <tbody>${itemData.recent.map((r) => `<tr>
                <td>${escapeHtml(r.doc_no)}<div class="muted" style="font-size:12px">${escapeHtml(r.counter_name || '-')}<br>${escapeHtml(fmtDateTime(r.counted_at || r.created_at))}</div></td>
                <td>${escapeHtml(r.name)}<div class="muted" style="font-size:12px">${escapeHtml(r.consignor_name)}</div></td>
                <td class="num">${r.count_system_qty ?? r.system_qty}</td><td class="num">${r.count_qty ?? r.counted_qty}</td>
                <td>${r.diff_at_count ? `<span class="pill bad">${signed(r.diff_at_count)}</span>` : '<span class="pill ok">ตรง</span>'}</td>
                <td>${itemDetail(r)}</td>
                <td>${itemPhotos(r)}</td>
                <td>${itemStatus(r)}</td>
              </tr>`).join('')}</tbody>
            </table></div>` : ''}` : ''}
        </section>`;
    }

    function bindItems(history) {
        const redraw = () => (current ? draw() : draw(history));
        container.querySelector('#btn-item-count')?.addEventListener('click', async () => {
            const open = (await api.get('/stockcounts/open-round').catch(() => ({ data: { open: false } }))).data.open;
            if (open) {
                await loadList();
                return;
            }
            openMobileCount({ quick: true, stockCount: null, items: [], onClose: () => loadList() });
        });
        container.querySelector('#btn-item-recent')?.addEventListener('click', () => {
            showRecent = !showRecent;
            redraw();
        });
        container.querySelectorAll('[data-item-ok]').forEach((b) => b.addEventListener('click', () => certifyItem(itemData.pending[Number(b.dataset.itemOk)], redraw)));
        container.querySelectorAll('[data-item-no]').forEach((b) => b.addEventListener('click', () => rejectItem(itemData.pending[Number(b.dataset.itemNo)], redraw)));
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
        redraw();
    }

    function matchesWords(it) {
        const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
        const text = [it.name, it.consignor_name, it.barcode].join(' ').toLowerCase();
        return words.every((w) => text.includes(w));
    }

    /** Search words (name, consignor, barcode), consigned goods only if asked, and the chosen tab. */
    function matches(it) {
        if (!matchesWords(it)) return false;
        if (consignedOnly && !it.consignor_id) return false;
        if (show === 'todo') return counted(it) === null;
        if (show === 'done') return counted(it) !== null;
        if (show === 'diff') return !!diffOf(it);
        if (show === 'unsaved') return dirty(it);
        return true;
    }

    // Rows are shown or hidden in place, so numbers typed but not saved stay in their boxes.
    function applyFilter() {
        let n = 0;
        container.querySelectorAll('tr[data-tr]').forEach((tr) => {
            const ok = matches(items[Number(tr.dataset.tr)]);
            tr.hidden = !ok;
            if (ok) n++;
        });
        const lbl = container.querySelector('[data-k="shown"]');
        if (lbl) lbl.textContent = n === items.length ? `${n} รายการ` : `แสดง ${n} จาก ${items.length} รายการ`;
        const none = container.querySelector('[data-k="none"]');
        if (none) none.hidden = n > 0;
        container.querySelectorAll('tr[data-disc]').forEach((tr) => {
            tr.hidden = !matchesWords(discontinued[Number(tr.dataset.disc)]);
        });
    }

    /** Admin: consigned goods with stock can be handed back; anything without stock can be discontinued. */
    function manageHtml(it, i) {
        if (Number(it.system_qty) > 0 && !it.consignor_id) return '';
        return Number(it.system_qty) > 0
            ? `<button type="button" class="btn secondary sm" data-return-all="${i}" title="คืนสินค้าทุกชิ้นให้ผู้ฝาก ปรับยอดเป็น 0 พร้อมออกใบคืนสินค้า">↩ คืนทุกชิ้น</button>`
            : `<button type="button" class="btn secondary sm" data-discontinue="${i}" title="ไม่นำมาขายแล้ว: ไม่แสดงในหน้าตรวจนับ หน้าสต๊อก และหน้าขาย จนกว่าจะรับสินค้าเข้าอีกครั้ง">เลิกจำหน่าย</button>`;
    }

    function discontinuedHtml() {
        return `
        <section class="card">
          <div class="card-pad"><b>สินค้าที่เลิกจำหน่าย (${discontinued.length})</b></div>
          ${discontinued.length ? `<div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th>ผู้ฝาก</th><th>บาร์โค้ด</th><th class="num">คงเหลือ</th>${isAdmin ? '<th></th>' : ''}</tr></thead>
            <tbody>${discontinued.map((p, i) => `<tr data-disc="${i}">
              <td>${escapeHtml(p.name)}</td><td class="muted">${escapeHtml(p.consignor_name)}</td><td class="muted">${escapeHtml(p.barcode || '-')}</td>
              <td class="num">${Number(p.qty_stock)}</td>
              ${isAdmin ? `<td><button type="button" class="btn secondary sm" data-restore="${i}">กลับมาจำหน่าย</button></td>` : ''}
            </tr>`).join('')}</tbody>
          </table></div>` : '<div class="empty">ไม่มีสินค้าที่เลิกจำหน่าย</div>'}
        </section>`;
    }

    async function toggleDiscontinued(on) {
        showDiscontinued = on;
        try {
            if (on) discontinued = (await api.get('/stockcounts/discontinued')).data;
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    /** Loads the round again; numbers typed in lines other than `except` stay on screen unsaved. */
    async function reloadKeepingTyped(except) {
        const pending = new Map(items.filter((x) => x !== except && x._pendingCounted !== undefined).map((x) => [x.id, x._pendingCounted]));
        const res = await api.get(`/stockcounts/${current.id}`);
        current = res.data.stock_count;
        items = res.data.items.map((x) => (pending.has(x.id) ? { ...x, _pendingCounted: pending.get(x.id) } : x));
        if (showDiscontinued) discontinued = (await api.get('/stockcounts/discontinued')).data;
    }

    /** Runs an action on a line, then shows the round as it is now. */
    async function act(it, run) {
        try {
            notice = await run();
            error = null;
            await reloadKeepingTyped(it);
        } catch (e) {
            notice = null;
            error = e.message;
        }
        draw();
    }

    async function returnAll(it) {
        const qty = Number(it.system_qty);
        if (!(await confirmDialog(`ระบบจะออกใบคืนสินค้าและปรับยอด "${it.name}" เป็น 0 ชิ้น โดยถือว่าคืนให้ ${it.consignor_name} แล้ว ยกเลิกภายหลังได้จากประวัติใบคืนสินค้า`, { title: `คืนสินค้าทุกชิ้น (${qty} ชิ้น)?`, okText: 'คืนทุกชิ้น', cancelText: 'ไม่คืน', danger: true }))) return;
        await act(it, async () => {
            const res = await api.post(`/products/${it.product_id}/return-all`);
            return `คืน "${it.name}" ${res.data.total_qty} ชิ้นให้ ${it.consignor_name} แล้ว (ใบคืน ${res.data.doc_no}) ยอดคงเหลือเป็น 0`;
        });
    }

    async function discontinue(it) {
        if (!(await confirmDialog(`"${it.name}" จะไม่แสดงในหน้าตรวจนับ หน้าสต๊อก และหน้าขาย ดูได้เมื่อทำเครื่องหมาย แสดงสินค้าที่เลิกจำหน่าย และกลับมาจำหน่ายเองเมื่อรับสินค้านี้เข้าอีกครั้ง`, { title: 'ตั้งเป็นเลิกจำหน่าย?', okText: 'เลิกจำหน่าย', cancelText: 'ไม่เปลี่ยน' }))) return;
        await act(it, async () => {
            await api.post(`/products/${it.product_id}/discontinued`, { discontinued: true });
            return `ตั้ง "${it.name}" เป็นเลิกจำหน่ายแล้ว`;
        });
    }

    async function restore(p) {
        await act(null, async () => {
            await api.post(`/products/${p.id}/discontinued`, { discontinued: false });
            return `"${p.name}" กลับมาจำหน่ายแล้ว`;
        });
    }

    const savedCount = (it) => (it.counted_qty === null || it.counted_qty === undefined ? null : Number(it.counted_qty));
    /** Typed here and not saved yet. */
    const dirty = (it) => it._pendingCounted !== undefined && counted(it) !== savedCount(it);

    function saveHtml(it, i) {
        if (it.returned) return '';
        if (dirty(it)) return `<button type="button" class="btn sm" data-save="${i}">บันทึก</button>`;
        return savedCount(it) !== null
            ? '<span class="pill ok" title="บันทึกจำนวนนี้แล้ว">✓ บันทึกแล้ว</span>'
            : `<button type="button" class="btn secondary sm" data-save="${i}" disabled>บันทึก</button>`;
    }

    /** Saves one line only; numbers typed in other lines stay on screen unsaved. */
    async function saveRow(i, next) {
        const it = items[i];
        if (dirty(it)) {
            const b = container.querySelector(`[data-save="${i}"]`);
            if (b) { b.disabled = true; b.textContent = 'กำลังบันทึก…'; }
            try {
                await api.post(`/stockcounts/${current.id}/counts`, { items: [{ item_id: it.id, counted_qty: counted(it) }] });
                await reloadKeepingTyped(it);
                error = null;
                notice = null;
            } catch (e) {
                error = e.message;
            }
            draw();
            i = items.findIndex((x) => x.id === it.id);
        }
        if (!next || i < 0) return;
        const boxes = [...container.querySelectorAll('[data-idx]')].filter((b) => !b.closest('tr').hidden && !b.disabled);
        const after = boxes.find((b) => Number(b.dataset.idx) > i);
        if (after) { after.focus(); after.select?.(); }
    }

    /** Counting photos of a line (open full size), and a button to add one while the round is open. */
    function photosHtml(it, i) {
        const shots = (it.photos || []).map((p) => (p.token
            ? `<a href="${attachmentUrl(p.token)}" target="_blank" rel="noopener" title="${escapeHtml([p.note, p.created_at].filter(Boolean).join(' · '))}"><img src="${attachmentUrl(p.token)}" alt="รูปประกอบการนับ"></a>`
            : '<span class="pill muted" title="ลบอัตโนมัติเมื่อครบ 30 วัน">รูปถูกลบแล้ว</span>')).join('');
        const missing = counted(it) !== null && !it.returned && !(it.photos || []).length ? '<span class="pill warn">ยังไม่มีรูป</span>' : '';
        const add = ['draft', 'submitted'].includes(current.status)
            ? `<label class="btn secondary sm" title="แนบรูปถ่ายตอนนับ">📷<input type="file" accept="image/*" capture="environment" data-photo="${i}" hidden></label>` : '';
        return `<div class="cnt-photo-row">${shots}${missing}${add}</div>`;
    }

    async function addPhoto(i, file) {
        const it = items[i];
        try {
            const dataUrl = await thumbDataUrl(await fileToDataUrl(file), COUNT_PHOTO_SIZE);
            if (!dataUrl) throw new Error('อ่านไฟล์รูปไม่ได้');
            const res = await api.post(`/stockcounts/${current.id}/photo`, { product_id: it.product_id, data_url: dataUrl });
            it.photos = [...(it.photos || []), { token: res.data.token, note: null, created_at: null }];
            error = null;
        } catch (e) {
            error = e.message;
        }
        draw();
    }

    /** The count as on screen (typed but unsaved wins); null = not counted. */
    function counted(it) {
        const v = it._pendingCounted !== undefined ? it._pendingCounted : it.counted_qty;
        return v === null || v === '' ? null : Number(v);
    }
    function diffOf(it) {
        const c = counted(it);
        return c === null ? null : c - Number(it.system_qty);
    }
    function diffHtml(diff) {
        if (diff === null) return '<span class="muted" style="font-size:12px">ไม่ได้นับ</span>';
        return diff === 0 ? '<span class="pill ok">ตรง</span>' : `<span class="pill bad">${diff > 0 ? '+' + diff : diff}</span>`;
    }
    function totals() {
        let diffCount = 0;
        let diffValue = 0;
        let countedN = 0;
        items.forEach((it) => {
            const d = diffOf(it);
            if (d === null) return;
            countedN++;
            if (d !== 0) {
                diffCount++;
                diffValue += d * Number(it.sale_price);
            }
        });
        return { diffCount, diffValue, countedN };
    }

    async function saveCounts(thenSubmit) {
        // Only boxes changed here are sent; a cleared box sends null (not counted).
        const payload = items
            .filter((it) => it._pendingCounted !== undefined)
            .map((it) => ({ item_id: it.id, counted_qty: counted(it) }));
        try {
            await api.post(`/stockcounts/${current.id}/counts`, { items: payload });
            if (thenSubmit) {
                await api.post(`/stockcounts/${current.id}/submit`);
            }
            error = null;
            await loadOne(current.id);
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    async function approveCount() {
        try {
            await api.post(`/stockcounts/${current.id}/approve`);
            await loadList();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    async function reopenCount() {
        try {
            await api.post(`/stockcounts/${current.id}/reopen`);
            error = null;
            await loadOne(current.id);
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    async function cancelCount() {
        if (!(await confirmDialog('ผลการนับในรอบนี้จะไม่ถูกนำไปปรับสต๊อก (บาร์โค้ดและรูปสินค้าที่บันทึกระหว่างนับยังอยู่)', { title: `ยกเลิกรอบตรวจนับ ${current.doc_no}?`, okText: 'ยกเลิกรอบตรวจนับ', cancelText: 'ไม่ยกเลิก', danger: true }))) return;
        try {
            await api.post(`/stockcounts/${current.id}/cancel`);
            error = null;
            await loadList();
        } catch (e) {
            error = e.message;
            draw();
        }
    }

    await loadList();
}
