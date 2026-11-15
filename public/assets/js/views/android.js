import { escapeHtml } from '../util.js';
import { skPage } from '../skeleton.js';

// แอป Android: both Android apps on one page, as tabs — the phone app every signed-in user pairs
// with their own account (views/mobileapp.js) and the POS app on the shop's selling devices
// (views/devices.js, for whoever manages POS devices). The old routes #/mobileapp and #/devices
// land on their tab (app.js), so QR-Codes and links made before keep working.
const TABS = [
    { key: 'mobileapp', label: 'แอปผู้จัดการ (มือถือของฉัน)', perm: null },
    { key: 'devices', label: 'แอป POS (เครื่องขายหน้าร้าน)', perm: 'devices.manage' },
];

export async function render(page, ctx) {
    const { permissions, params = {} } = ctx;
    const tabs = TABS.filter((t) => !t.perm || permissions.includes(t.perm));
    let tab = tabs.some((t) => t.key === params.tab) ? params.tab : tabs[0].key;
    page.innerHTML = `${tabs.length > 1 ? `<div class="tabs" style="margin-bottom:14px">${tabs.map((t) =>
        `<button type="button" class="tab-btn" data-tab="${t.key}">${escapeHtml(t.label)}</button>`).join('')}</div>` : ''}<div></div>`;

    async function show(key, first = false) {
        tab = key;
        page.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === key));
        // Keep the tab in the URL without triggering the router's hashchange reload.
        if (!first) history.replaceState(null, '', key === tabs[0].key ? '#/android' : `#/android?tab=${key}`);
        // A fresh pane per visit, so a slow load from a tab left behind lands in a detached node.
        const pane = document.createElement('div');
        pane.innerHTML = skPage('list');
        page.lastElementChild.replaceWith(pane);
        try {
            const mod = await import(`./${key}.js`);
            // The pairing code from a QR-Code (params.code) belongs to the phone app only.
            await mod.render(pane, { ...ctx, params: first ? params : {} });
        } catch (e) {
            pane.innerHTML = `<div class="card card-pad">เกิดข้อผิดพลาด: ${escapeHtml(e.message || '')}</div>`;
        }
    }

    page.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { if (b.dataset.tab !== tab) show(b.dataset.tab); }));
    await show(tab, true);
}
