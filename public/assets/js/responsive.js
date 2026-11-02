// Touch screens and narrow windows: every table scrolls sideways inside its own box
// instead of pushing the whole page wider than the screen.
const SCROLLERS = '.table-wrap, .ai-table, .tbl-scroll';

function wrapTables(scope) {
    const tables = scope.matches?.('table') ? [scope] : scope.querySelectorAll?.('table') || [];
    for (const table of tables) {
        if (!table.parentElement || table.parentElement.closest(SCROLLERS)) continue;
        const box = document.createElement('div');
        box.className = 'tbl-scroll';
        table.parentElement.insertBefore(box, table);
        box.appendChild(table);
    }
}

// Tables whose header sticks under the top bar while the page scrolls (.table-wrap.sticky-page):
// keep the top bar's height in --topbar-h, and box a table that is wider than its card.
let fitQueued = false;
function fitStickyTables() {
    fitQueued = false;
    const bar = document.querySelector('.topbar');
    document.documentElement.style.setProperty('--topbar-h', `${bar && bar.offsetParent !== null ? bar.offsetHeight : 0}px`);
    for (const wrap of document.querySelectorAll('.table-wrap.sticky-page')) {
        const table = wrap.querySelector(':scope > table');
        if (table) wrap.classList.toggle('boxed', table.offsetWidth > wrap.clientWidth + 1);
    }
}
function queueFit() {
    if (fitQueued) return;
    fitQueued = true;
    requestAnimationFrame(fitStickyTables);
}

export function initResponsive() {
    wrapTables(document.body);
    window.addEventListener('resize', queueFit);
    if (window.ResizeObserver) new ResizeObserver(queueFit).observe(document.body);
    new MutationObserver((records) => {
        queueFit();
        for (const r of records) {
            for (const node of r.addedNodes) {
                if (node.nodeType === 1 && node.className !== 'tbl-scroll') wrapTables(node);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
