import { api } from '../api.js';
import { count, escapeHtml, fmtDateTime } from '../util.js';
import { alertDialog, confirmDialog, promptDialog } from '../dialog.js';
import { requestHtml } from '../sale-edit.js';

/**
 * อนุมัติแก้ไขการขาย: managers and admins decide on cashiers' requests to correct a
 * sale. Approving applies it (sale lines, stock, consignor passbooks, cash drawer).
 */
export async function render(container, { user }) {
    let tab = 'pending';
    let data = null;
    let busy = null;

    async function load() {
        data = await api.get(`/sale-edits?status=${tab}`);
        draw();
    }

    function draw() {
        const rows = data.data;
        container.innerHTML = `
        <section class="card card-pad">
          <div class="actions-row">
            <button type="button" class="tab-btn ${tab === 'pending' ? 'active' : ''}" data-tab="pending">รออนุมัติ (${count(data.pending_count)})</button>
            <button type="button" class="tab-btn ${tab === 'done' ? 'active' : ''}" data-tab="done">พิจารณาแล้ว</button>
          </div>
          <div class="muted" style="font-size:12.5px;margin-top:10px">เมื่ออนุมัติ ระบบจะปรับรายการขาย สต๊อกสินค้า สมุดบัญชีผู้ฝากขาย และยอดเงินในลิ้นชักตามคำขอทันที
            (สต๊อกอาจติดลบได้ เพราะสินค้าออกจากร้านไปแล้ว)</div>
        </section>
        ${rows.map((r) => `
          <section class="card card-pad">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center">
              <h2 style="margin:0">${escapeHtml(r.doc_no)} <span class="muted" style="font-size:13px;font-weight:400">ขายเมื่อ ${escapeHtml(fmtDateTime(r.sale_datetime))} · พนักงาน ${escapeHtml(r.requested_by_name || '-')}</span></h2>
              ${r.status === 'pending' ? `<div style="display:flex;gap:8px">
                <button type="button" class="btn secondary" data-reject="${r.id}" ${busy ? 'disabled' : ''}>ไม่อนุมัติ</button>
                <button type="button" class="btn" data-approve="${r.id}" ${busy ? 'disabled' : ''}>${busy === String(r.id) ? 'กำลังบันทึก...' : 'อนุมัติ'}</button></div>` : ''}
            </div>
            ${requestHtml(r)}
          </section>`).join('') || `<section class="card card-pad"><div class="empty">${tab === 'pending' ? 'ไม่มีคำขอรออนุมัติ' : 'ยังไม่มีคำขอที่พิจารณาแล้ว'}</div></section>`}`;
        wire();
    }

    function wire() {
        container.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { tab = b.dataset.tab; load(); }));
        container.querySelectorAll('[data-approve]').forEach((b) => b.addEventListener('click', () => approve(Number(b.dataset.approve))));
        container.querySelectorAll('[data-reject]').forEach((b) => b.addEventListener('click', () => reject(Number(b.dataset.reject))));
    }

    async function approve(id) {
        const r = data.data.find((x) => x.id === id || String(x.id) === String(id));
        if (String(r.requested_by) === String(user.id) && user.role !== 'admin') {
            alertDialog('อนุมัติคำขอของตัวเองไม่ได้ ต้องให้ผู้จัดการคนอื่นหรือผู้ดูแลระบบอนุมัติ', { tone: 'error' });
            return;
        }
        if (!await confirmDialog(`อนุมัติการแก้ไข ${r.doc_no}? รายการขาย สต๊อก และยอดเงินจะถูกปรับตามคำขอ`, { title: 'อนุมัติการแก้ไขการขาย', okText: 'อนุมัติ' })) return;
        busy = String(id);
        draw();
        try {
            await api.post(`/sale-edits/${id}/approve`, {});
            busy = null;
            await load();
        } catch (e) {
            busy = null;
            draw();
            alertDialog(e.message, { tone: 'error' });
        }
    }

    async function reject(id) {
        const r = data.data.find((x) => String(x.id) === String(id));
        const note = await promptDialog(`${r.doc_no} · ${r.requested_by_name || ''}`, { title: 'ไม่อนุมัติการแก้ไข', okText: 'ไม่อนุมัติ', placeholder: 'เหตุผลที่ไม่อนุมัติ (พนักงานจะเห็นข้อความนี้)', required: true, multiline: true, danger: true });
        if (note === null) return;
        try {
            await api.post(`/sale-edits/${id}/reject`, { note });
            await load();
        } catch (e) { alertDialog(e.message, { tone: 'error' }); }
    }

    await load();
}
