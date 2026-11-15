import { escapeHtml } from './util.js';
import { skPage } from './skeleton.js';

/**
 * Opens a whole page (views/<key>.js) on a large modal over the current one, e.g. a consignor's
 * passbook from the register, so the work is done without leaving the page. onClose runs when
 * the modal is closed (to refresh what the page below shows).
 */
export async function openViewModal(key, { title, params = {}, ctx = {}, width = 1100, onClose = () => {} }) {
    const el = document.createElement('div');
    el.className = 'modal-backdrop view-modal';
    el.innerHTML = `
      <div class="modal" style="width:${width}px" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
        <div class="modal-head"><h2>${escapeHtml(title)}</h2><button type="button" class="modal-close" data-vm-close aria-label="ปิด">✕</button></div>
        <div class="modal-body view-modal-body">${skPage('report')}</div>
      </div>`;
    document.body.appendChild(el);
    const close = () => {
        if (!el.isConnected) return;
        el.remove();
        document.removeEventListener('keydown', onKey);
        onClose();
    };
    // Escape closes this modal only when nothing opened on top of it (a form of the page inside).
    const onKey = (e) => {
        if (e.key !== 'Escape') return;
        const top = [...document.querySelectorAll('.modal-backdrop')].pop();
        if (top === el) close();
    };
    document.addEventListener('keydown', onKey);
    el.querySelector('[data-vm-close]').addEventListener('click', close);
    el.addEventListener('click', (e) => { if (e.target === el) close(); });
    const body = el.querySelector('.view-modal-body');
    try {
        const mod = await import(`./views/${key}.js`);
        await mod.render(body, { ...ctx, params, embedded: true, navigate: (k, p) => { close(); ctx.navigate?.(k, p); } });
    } catch (e) {
        body.innerHTML = `<div class="error-box">${escapeHtml(e.message || 'เปิดหน้านี้ไม่สำเร็จ')}</div>`;
    }
    return close;
}
