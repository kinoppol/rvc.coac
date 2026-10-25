import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';
import { openMobileCount, COUNT_PHOTO_SIZE } from './count-mobile.js';
import { attachmentUrl, fileToDataUrl, thumbDataUrl } from '../images.js';
import { confirmDialog } from '../dialog.js';

export async function render(container, { permissions }) {
    let current = null;
    let items = [];
    let error = null;
    const canApprove = permissions.includes('stockcount.approve');
    const canCreate = permissions.includes('stockcount.create');

    async function loadList() {
        const list = (await api.get('/stockcounts')).data;
        const active = list.find((c) => c.status === 'draft' || c.status === 'submitted');
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
            <section class="card card-pad" style="text-align:center">
              <p class="muted">ยังไม่มีรอบตรวจนับที่กำลังดำเนินการ</p>
              <button type="button" class="btn" id="btn-start">เริ่มรอบตรวจนับใหม่</button>
              ${history && history.length ? `
                <div class="table-wrap" style="margin-top:20px;text-align:left"><table>
                  <thead><tr><th>เลขที่</th><th>วันที่</th><th>สถานะ</th></tr></thead>
                  <tbody>${history.map((h) => `<tr><td>${escapeHtml(h.doc_no)}</td><td>${escapeHtml(h.count_date)}</td><td>${h.status === 'cancelled' ? '<span class="pill">ยกเลิก</span>' : '<span class="pill ok">เสร็จสิ้น</span>'}</td></tr>`).join('')}</tbody>
                </table></div>` : ''}
            </section>`;
            container.querySelector('#btn-start').addEventListener('click', async () => {
                const res = await api.post('/stockcounts/start');
                await loadOne(res.data.id);
            });
            return;
        }

        const { diffCount, diffValue, countedN } = totals();

        container.innerHTML = `
        <div class="stat-row">
          <div class="kpi"><div class="lbl">รอบตรวจนับ</div><div class="val" style="font-size:16px">${escapeHtml(current.doc_no)}</div><div class="sub">${escapeHtml(current.count_date)}</div></div>
          <div class="kpi"><div class="lbl">รายการที่นับแล้ว</div><div class="val" data-k="counted">${countedN}/${items.length}</div><div class="sub">ไม่ต้องนับครบทุกรายการ</div></div>
          <div class="kpi"><div class="lbl">พบผลต่าง</div><div class="val" data-k="diff" style="color:${diffCount ? 'var(--bad)' : 'inherit'}">${diffCount}</div></div>
          <div class="kpi"><div class="lbl">มูลค่าผลต่างสุทธิ</div><div class="val" data-k="value">฿ ${money(diffValue)}</div></div>
        </div>
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        ${current.status === 'draft' ? `
          <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><b>โหมดนับด้วยมือถือ</b><div class="muted" style="font-size:13px">สแกนบาร์โค้ดด้วยกล้อง ปุ่มกดตัวเลขขนาดใหญ่ ถ่ายภาพสินค้า และค้นหาสินค้าเพื่อผูกบาร์โค้ด — บันทึกทันทีทีละรายการ</div></div>
            <button type="button" class="btn" id="btn-mobile" style="font-size:15px;padding:12px 18px">📱 เปิดโหมดนับด้วยมือถือ</button>
          </section>` : ''}
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th>ผู้ฝาก</th><th class="num">ในระบบ</th><th class="num">นับได้จริง</th><th>ผลต่าง</th><th title="รูปถ่ายตอนนับ เก็บไว้ตรวจสอบ 30 วัน">รูปประกอบการนับ</th></tr></thead>
            <tbody>
              ${items.map((it, i) => {
                  const diff = diffOf(it);
                  return `<tr data-tr="${i}" style="${diff ? 'background:var(--bad-bg)' : ''}">
                    <td>${escapeHtml(it.name)}</td><td class="muted">${escapeHtml(it.consignor_name)}</td>
                    <td class="num">${it.system_qty}</td>
                    <td class="num"><input type="number" min="0" value="${counted(it) ?? ''}" placeholder="ไม่ได้นับ" data-idx="${i}" style="width:90px;text-align:right" ${current.status !== 'draft' ? 'disabled' : ''}>
                      ${current.status === 'draft' ? `<button type="button" class="btn secondary sm" data-zero="${i}" title="ขายหมดแล้ว ไม่มีของให้นับ: บันทึกว่านับได้ 0" ${counted(it) === 0 ? 'hidden' : ''}>หมด (0)</button>` : ''}</td>
                    <td data-diff>${diffHtml(diff)}</td>
                    <td class="cnt-photos">${photosHtml(it, i)}</td>
                  </tr>`;
              }).join('')}
            </tbody>
          </table></div>
        </section>
        <p class="muted" style="font-size:12px">ทุกรายการที่นับควรมีรูปประกอบการนับ (โหมดนับด้วยมือถือบังคับถ่ายรูปทุกครั้งที่บันทึก) · รูปย่อให้ดูเต็มจอได้ชัด และลบอัตโนมัติเมื่อครบ 30 วัน<br>กรอกเฉพาะรายการที่นับ ช่องที่เว้นว่างถือว่าไม่ได้นับ · สินค้าที่ขายหมดแล้วไม่มีของให้นับ กดปุ่ม <b>หมด (0)</b> เพื่อบันทึกว่านับได้ 0 ระบบจะไม่ปรับสต๊อกรายการนั้น (ลบตัวเลขออกเพื่อยกเลิกการนับรายการนั้น)<br>เมื่อพบผลต่าง ระบบจะส่งรายงานให้ผู้จัดการร้านและครูที่ปรึกษาอนุมัติก่อนปรับสต๊อก</p>
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
        }));
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
        const cancelBtn = container.querySelector('#btn-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', cancelCount);
    }

    /** Counting photos of a line (open full size), and a button to add one while the round is open. */
    function photosHtml(it, i) {
        const shots = (it.photos || []).map((p) => (p.token
            ? `<a href="${attachmentUrl(p.token)}" target="_blank" rel="noopener" title="${escapeHtml([p.note, p.created_at].filter(Boolean).join(' · '))}"><img src="${attachmentUrl(p.token)}" alt="รูปประกอบการนับ"></a>`
            : '<span class="pill muted" title="ลบอัตโนมัติเมื่อครบ 30 วัน">รูปถูกลบแล้ว</span>')).join('');
        const missing = counted(it) !== null && !(it.photos || []).length ? '<span class="pill warn">ยังไม่มีรูป</span>' : '';
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
