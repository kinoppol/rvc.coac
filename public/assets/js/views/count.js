import { api } from '../api.js';
import { money, escapeHtml } from '../util.js';
import { openMobileCount } from './count-mobile.js';
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

        const diffCount = items.filter((i) => i.diff_qty !== null && Number(i.diff_qty) !== 0).length;
        const diffValue = items.reduce((s, i) => s + (i.diff_qty ? Number(i.diff_qty) * Number(i.sale_price) : 0), 0);
        const countedN = items.filter((i) => i.counted_qty !== null).length;

        container.innerHTML = `
        <div class="stat-row">
          <div class="kpi"><div class="lbl">รอบตรวจนับ</div><div class="val" style="font-size:16px">${escapeHtml(current.doc_no)}</div><div class="sub">${escapeHtml(current.count_date)}</div></div>
          <div class="kpi"><div class="lbl">รายการที่นับแล้ว</div><div class="val">${countedN}/${items.length}</div></div>
          <div class="kpi"><div class="lbl">พบผลต่าง</div><div class="val" style="color:${diffCount ? 'var(--bad)' : 'inherit'}">${diffCount}</div></div>
          <div class="kpi"><div class="lbl">มูลค่าผลต่างสุทธิ</div><div class="val">฿ ${money(diffValue)}</div></div>
        </div>
        ${error ? `<div class="error-box">${escapeHtml(error)}</div>` : ''}
        ${current.status === 'draft' ? `
          <section class="card card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><b>โหมดนับด้วยมือถือ</b><div class="muted" style="font-size:13px">สแกนบาร์โค้ดด้วยกล้อง ปุ่มกดตัวเลขขนาดใหญ่ ถ่ายภาพสินค้า และค้นหาสินค้าเพื่อผูกบาร์โค้ด — บันทึกทันทีทีละรายการ</div></div>
            <button type="button" class="btn" id="btn-mobile" style="font-size:15px;padding:12px 18px">📱 เปิดโหมดนับด้วยมือถือ</button>
          </section>` : ''}
        <section class="card">
          <div class="table-wrap"><table>
            <thead><tr><th>สินค้า</th><th>ผู้ฝาก</th><th class="num">ในระบบ</th><th class="num">นับได้จริง</th><th>ผลต่าง</th></tr></thead>
            <tbody>
              ${items.map((it, i) => {
                  const diff = it.diff_qty === null ? null : Number(it.diff_qty);
                  return `<tr style="${diff ? 'background:var(--bad-bg)' : ''}">
                    <td>${escapeHtml(it.name)}</td><td class="muted">${escapeHtml(it.consignor_name)}</td>
                    <td class="num">${it.system_qty}</td>
                    <td class="num"><input type="number" min="0" value="${it.counted_qty ?? ''}" data-idx="${i}" style="width:80px;text-align:right" ${current.status !== 'draft' ? 'disabled' : ''}></td>
                    <td>${diff === null ? '' : diff === 0 ? '<span class="pill ok">ตรง</span>' : `<span class="pill bad">${diff > 0 ? '+' + diff : diff}</span>`}</td>
                  </tr>`;
              }).join('')}
            </tbody>
          </table></div>
        </section>
        <p class="muted" style="font-size:12px">เมื่อพบผลต่าง ระบบจะส่งรายงานให้ผู้จัดการร้านและครูที่ปรึกษาอนุมัติก่อนปรับสต๊อก</p>
        <div class="actions-row">
          ${current.status === 'draft' ? `
            <button type="button" class="btn secondary" id="btn-save">บันทึกร่างไว้ก่อน</button>
            <button type="button" class="btn" id="btn-submit" ${diffCount ? '' : 'disabled'}>ส่งรายงานผลต่างให้ผู้จัดการร้าน</button>
          ` : ''}
          ${current.status === 'submitted' && canApprove ? `<button type="button" class="btn" id="btn-approve">อนุมัติและปรับสต๊อก</button>` : ''}
          ${(current.status === 'draft' && canCreate) || (current.status === 'submitted' && canApprove) ? `<button type="button" class="btn danger" id="btn-cancel">ยกเลิกรอบตรวจนับ</button>` : ''}
          ${current.status === 'submitted' && !canApprove ? `<span class="pill warn">ส่งรายงานแล้ว รออนุมัติจากผู้จัดการร้าน</span>` : ''}
        </div>`;

        container.querySelectorAll('[data-idx]').forEach((inp) => inp.addEventListener('change', (e) => {
            items[Number(e.target.dataset.idx)]._pendingCounted = e.target.value;
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

    async function saveCounts(thenSubmit) {
        const payload = items
            .filter((it) => it._pendingCounted !== undefined && it._pendingCounted !== '')
            .map((it, idx) => ({ item_id: it.id, counted_qty: Number(it._pendingCounted) }));
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
