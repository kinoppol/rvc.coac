import { escapeHtml } from '../util.js';
import { skPage } from '../skeleton.js';

// โปรโมชั่น / ใช้แต้ม: bundle promotions (promos-bundle.js) and the goods members can buy with points
// off (promos-points.js), as tabs. #/promos?tab=points opens the second.
const TABS = [
    { key: 'bundle', label: 'โปรโมชั่นจับคู่สินค้า', mod: './promos-bundle.js' },
    { key: 'points', label: 'ใช้แต้มสะสมเป็นส่วนลด', mod: './promos-points.js' },
];

export async function render(page, ctx) {
    const { params = {} } = ctx;
    let tab = TABS.some((t) => t.key === params.tab) ? params.tab : TABS[0].key;
    page.innerHTML = `<div class="tabs" style="margin-bottom:14px">${TABS.map((t) =>
        `<button type="button" class="tab-btn" data-tab="${t.key}">${escapeHtml(t.label)}</button>`).join('')}</div><div></div>`;

    async function show(key, first = false) {
        tab = key;
        page.querySelectorAll('[data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === key));
        if (!first) history.replaceState(null, '', key === TABS[0].key ? '#/promos' : `#/promos?tab=${key}`);
        const pane = document.createElement('div');
        pane.innerHTML = skPage('list');
        page.lastElementChild.replaceWith(pane);
        try {
            const mod = await import(TABS.find((t) => t.key === key).mod);
            await mod.render(pane, ctx);
        } catch (e) {
            pane.innerHTML = `<div class="card card-pad">เกิดข้อผิดพลาด: ${escapeHtml(e.message || '')}</div>`;
        }
    }

    page.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { if (b.dataset.tab !== tab) show(b.dataset.tab); }));
    await show(tab, true);
}
