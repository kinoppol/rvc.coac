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

export function initResponsive() {
    wrapTables(document.body);
    new MutationObserver((records) => {
        for (const r of records) {
            for (const node of r.addedNodes) {
                if (node.nodeType === 1 && node.className !== 'tbl-scroll') wrapTables(node);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
